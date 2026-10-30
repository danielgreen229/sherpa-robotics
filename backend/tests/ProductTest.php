<?php

declare(strict_types=1);

namespace Tests;

use App\Service\ProductData;
use App\Support\ApiException;

final class ProductTest extends DatabaseTestCase
{
    /** @param array<string, mixed> $changes */
    private function data(array $changes = []): ProductData
    {
        return ProductData::fromArray($changes + ['external_code' => 'code-001', 'name' => 'Тестовый товар', 'price' => '1320,00', 'purchase_price' => '880,00', 'attributes' => ['Размер' => 'M', 'Цвет' => 'Синий']]);
    }

    public function testCrudAndCascadeDeletion(): void
    {
        $saved = $this->products->save($this->data(), [['url' => 'https://example.com/image.jpg', 'path' => '/media/test.jpg']], createOnly: true);
        $id = $saved['product']['id'];
        self::assertTrue($saved['created']);
        $product = $this->products->find($id)->toArray();
        self::assertSame('1320.00', $product['price']);
        self::assertSame('33.33', $product['discount']);
        self::assertCount(2, $product['attributes']);
        self::assertCount(1, $product['images']);
        $this->products->save($this->data(['name' => 'Обновлённый товар', 'attributes' => ['Размер' => 'XL']]), [], $id);
        $changed = $this->products->find($id)->toArray();
        self::assertSame('Обновлённый товар', $changed['name']);
        self::assertCount(1, $changed['attributes']);
        self::assertCount(0, $changed['images']);
        $this->products->delete($id);
        self::assertSame(0, (int) $this->database->connection()->fetchOne('SELECT COUNT(*) FROM product_attributes'));
        self::assertSame(0, (int) $this->database->connection()->fetchOne('SELECT COUNT(*) FROM product_images'));
        $this->expectException(ApiException::class);
        $this->products->find($id);
    }

    public function testUpsertReplacesRelationsWithoutDuplicates(): void
    {
        $first = $this->products->save($this->data(), []);
        $second = $this->products->save($this->data(['price' => '1500']), []);
        self::assertFalse($second['created']);
        self::assertSame($first['product']['id'], $second['product']['id']);
        self::assertSame(1, $this->products->paginate([])['total']);
        self::assertSame(2, (int) $this->database->connection()->fetchOne('SELECT COUNT(*) FROM product_attributes'));
    }

    public function testDuplicateManualCreateReturnsConflict(): void
    {
        $this->products->save($this->data(), [], createOnly: true);
        try {
            $this->products->save($this->data(), [], createOnly: true);
            self::fail('Expected duplicate rejection');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testServerPaginationSearchAndPriceBounds(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->products->save($this->data(['external_code' => 'code-' . $i, 'name' => ($i <= 15 ? 'Кепка ' : 'Сумка ') . $i, 'price' => (string) ($i * 100), 'purchase_price' => '10']), []);
        }
        $page = $this->products->paginate(['page' => '2', 'limit' => '10']);
        self::assertCount(10, $page['items']);
        self::assertSame(25, $page['total']);
        self::assertSame(3, $page['total_pages']);
        self::assertSame('Кепка 11', $page['items'][0]['name']);
        $filtered = $this->products->paginate(['name' => 'Кепка', 'price_min' => '500', 'price_max' => '800']);
        self::assertSame(4, $filtered['total']);
        self::assertSame(0, $this->products->paginate(['name' => "%' OR 1=1 --"])['total']);
    }

    public function testTransactionRollsBackProductAndAttributes(): void
    {
        $db = $this->database->connection();
        try {
            $db->transactional(function (): void {
                $this->products->save($this->data(), []);
                throw new \RuntimeException('Simulated receipt write failure');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $this->products->paginate([])['total']);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM product_attributes'));
    }

    public function testInvalidPaginationRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->products->paginate(['limit' => '101']);
    }

    public function testListIncludesOnlyFirstImageAndHandlesMissingOrReplacedImages(): void
    {
        $first = ['url' => 'https://example.com/first.jpg', 'path' => '/media/first.jpg'];
        $second = ['url' => 'https://example.com/second.jpg', 'path' => '/media/second.jpg'];
        $saved = $this->products->save($this->data(), [$first, $second]);
        $this->products->save($this->data(['external_code' => 'without-photo']), []);
        $items = $this->products->paginate([])['items'];
        self::assertSame($first, $items[0]['thumbnail']);
        self::assertNull($items[1]['thumbnail']);
        self::assertArrayNotHasKey('images', $items[0]);
        $this->products->save($this->data(), [$second, $first], $saved['product']['id']);
        self::assertSame($second, $this->products->paginate([])['items'][0]['thumbnail']);
        self::assertSame($second, $this->products->find($saved['product']['id'])->toArray()['images'][0]);
        self::assertSame([], $this->products->paginate(['name' => 'absent'])['items']);
    }

    public function testExportIterationCrossesBatchBoundaryWithoutSkippingRelations(): void
    {
        for ($i = 1; $i <= 105; ++$i) {
            $this->products->save($this->data(['external_code' => 'batch-' . $i]), [['url' => 'https://example.com/' . $i . '.jpg', 'path' => '/media/' . $i . '.jpg']]);
        }
        $rows = [];
        foreach ($this->products->iterate() as $product) {
            $rows[] = $product->toArray();
        }
        self::assertCount(105, $rows);
        self::assertSame('batch-105', $rows[104]['external_code']);
        self::assertCount(2, $rows[104]['attributes']);
        self::assertSame('/media/105.jpg', $rows[104]['images'][0]['path']);
        self::assertSame(105, count(array_unique(array_column($rows, 'external_code'))));
    }

    public function testInvalidRangeRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->products->paginate(['price_min' => '1000', 'price_max' => '10']);
    }
}
