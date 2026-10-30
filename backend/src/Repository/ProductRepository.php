<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Service\ProductData;
use App\Support\ApiException;
use App\Support\Database;
use App\Support\CatalogLock;
use Doctrine\ORM\EntityManager;

final class ProductRepository
{
    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function snapshot(callable $operation): mixed
    {
        return \App\Support\ReadSnapshot::run($this->database->connection(), $operation);
    }

    public function __construct(private readonly Database $database)
    {
    }

    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function withCatalogWrite(callable $operation): mixed
    {
        return (new CatalogLock($this->database->connection()))->read($operation);
    }

    /** @param array<string, mixed> $filters
     *  @return array<string, mixed>
     */
    public function paginate(array $filters): array
    {
        $page = $this->positiveInt($filters['page'] ?? '1', 1, 10000000);
        $limit = $this->positiveInt($filters['limit'] ?? '20', 1, 100);
        $em = $this->database->entityManager();
        $query = $this->query($em, $filters);
        $total = (int) (clone $query)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->orderBy('p.id', 'ASC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
        $thumbnails = $this->thumbnails($em, $rows);
        return ['items' => array_map(fn (Product $p): array => $p->toArray(false) + ['thumbnail' => $thumbnails[$p->id] ?? null], $rows), 'total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => (int) ceil($total / $limit)];
    }

    /** @param list<Product> $products
     *  @return array<int, array{url: string, path: string}>
     */
    private function thumbnails(EntityManager $em, array $products): array
    {
        // One batch for page thumbnails; never load every image or issue one query per product.
        $thumbnails = [];
        if ($products !== []) {
            $ids = array_map(fn (Product $p): int => (int) $p->id, $products);
            $images = $em->getConnection()->fetchAllAssociative(
                'SELECT i.product_id, i.url, i.path FROM product_images i WHERE i.product_id IN (?) AND i.id = (SELECT MIN(first_image.id) FROM product_images first_image WHERE first_image.product_id = i.product_id)',
                [$ids],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
            foreach ($images as $image) {
                $thumbnails[(int) $image['product_id']] = ['url' => $image['url'], 'path' => $image['path']];
            }
        }
        return $thumbnails;
    }

    /** @param array<string, mixed> $filters
     *  @return iterable<Product>
     */
    public function iterate(array $filters = []): iterable
    {
        $em = $this->database->entityManager();
        $lastId = 0;
        do {
            $batch = $this->query($em, $filters)->andWhere('p.id > :after')->setParameter('after', $lastId)
                ->orderBy('p.id', 'ASC')->setMaxResults(100)->getQuery()->getResult();
            if ($batch === []) {
                break;
            }
            $ids = array_map(fn (Product $p): int => (int) $p->id, $batch);
            if ($ids === []) {
                break;
            }
            // Hydrate each collection separately to avoid both N+1 queries and a cartesian join.
            foreach (['attributes', 'images'] as $relation) {
                $em->createQueryBuilder()->select('p', 'r')->from(Product::class, 'p')
                    ->leftJoin('p.' . $relation, 'r')->where('p.id IN (:ids)')->setParameter('ids', $ids)
                    ->getQuery()->getResult();
            }
            foreach ($batch as $product) {
                yield $product;
            }
            $lastId = max($ids);
            $em->clear();
        } while (count($batch) === 100);
    }

    public function find(int $id): Product
    {
        return $this->database->entityManager()->find(Product::class, $id) ?? throw new ApiException('Товар не найден.', 404);
    }

    /** @param list<array{url: string, path: string}> $images
     *  @param list<string> $failedImageUrls
     *  @return array{product: array<string, mixed>, created: bool}
     */
    public function save(ProductData $data, array $images, ?int $id = null, bool $createOnly = false, array $failedImageUrls = []): array
    {
        $em = $this->database->entityManager();
        $connection = $em->getConnection();
        // Caller may own an outer transaction (the import row receipt).
        return $this->withCatalogWrite(fn (): array => $connection->transactional(function () use ($em, $connection, $data, $images, $id, $createOnly, $failedImageUrls): array {
            if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['product:' . $data->externalCode]);
            }
            $existing = $em->getRepository(Product::class)->findOneBy(['externalCode' => $data->externalCode]);
            $product = $id !== null ? $em->find(Product::class, $id) : $existing;
            if ($id !== null && $product === null) {
                throw new ApiException('Товар не найден.', 404);
            }
            if (($createOnly && $existing !== null) || ($existing !== null && $id !== null && $existing->id !== $id)) {
                throw new ApiException('Товар с таким внешним кодом уже существует.', 409);
            }
            $created = $product === null;
            $product ??= new Product($data->externalCode);
            $product->externalCode = $data->externalCode;
            $product->name = $data->name;
            $product->description = $data->description;
            $product->price = $data->price;
            $product->purchasePrice = $data->purchasePrice;
            $product->discount = $data->discount;
            // Replace the gallery atomically: partial network failures must not discard
            // working photos or leave a mixture of old and new source sets.
            if ($failedImageUrls !== []) {
                $previous = [];
                foreach ($product->images as $old) {
                    if (is_file(\App\Support\Config::storage() . $old->path)) {
                        $previous[] = ['url' => $old->url, 'path' => $old->path];
                    }
                }
                if ($previous !== []) {
                    $images = $previous;
                }
            }
            $product->attributes->clear();
            $product->images->clear();
            foreach ($data->attributes as $key => $value) {
                $product->attributes->add(new ProductAttribute($product, (string) $key, $value));
            }
            foreach ($images as $image) {
                $product->images->add(new ProductImage($product, $image['url'], $image['path']));
            }
            $em->persist($product);
            $em->flush();
            return ['product' => $product->toArray(), 'created' => $created];
        }));
    }

    public function delete(int $id): void
    {
        $this->withCatalogWrite(function () use ($id): void {
            $em = $this->database->entityManager();
            $product = $em->find(Product::class, $id) ?? throw new ApiException('Товар не найден.', 404);
            $em->remove($product);
            $em->flush();
        });
    }

    public function imagePath(string $url): ?string
    {
        $path = $this->database->connection()->fetchOne('SELECT path FROM product_images WHERE url = ? LIMIT 1', [$url]);
        return is_string($path) ? $path : null;
    }

    /** @param array<string, mixed> $filters */
    private function query(EntityManager $em, array $filters): \Doctrine\ORM\QueryBuilder
    {
        $q = $em->createQueryBuilder()->select('p')->from(Product::class, 'p');
        if (isset($filters['name']) && $filters['name'] !== '') {
            if (!is_string($filters['name']) || mb_strlen($filters['name']) > 500) {
                throw new ApiException('Некорректный поисковый запрос.', 422);
            }
            $name = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['name']));
            $q->andWhere("LOWER(p.name) LIKE :name ESCAPE '!'")->setParameter('name', '%' . $name . '%');
        }
        foreach (['price_min' => '>=', 'price_max' => '<='] as $key => $op) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $value = ProductData::money($filters[$key], $key);
                $q->andWhere('p.price ' . $op . ' :' . $key)->setParameter($key, $value);
            }
        }
        if (isset($filters['price_min'], $filters['price_max']) && $filters['price_min'] !== '' && $filters['price_max'] !== '' && bccomp(ProductData::money($filters['price_min'], 'price_min'), ProductData::money($filters['price_max'], 'price_max'), 2) > 0) {
            throw new ApiException('Минимальная цена больше максимальной.', 422);
        }
        return $q;
    }

    private function positiveInt(mixed $value, int $min, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new ApiException('Некорректные параметры пагинации.', 422);
        }
        return (int) $value;
    }
}
