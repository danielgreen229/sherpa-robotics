<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(name: 'external_code', length: 255, unique: true)]
    public string $externalCode;

    #[ORM\Column(length: 500)]
    public string $name = '';

    #[ORM\Column(type: 'text')]
    public string $description = '';

    /** @var numeric-string */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    public string $price = '0.00';

    /** @var numeric-string */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    public string $discount = '0.00';

    /** @var numeric-string */
    #[ORM\Column(name: 'purchase_price', type: 'decimal', precision: 14, scale: 2)]
    public string $purchasePrice = '0.00';

    /** @var Collection<int, ProductAttribute> */
    #[ORM\OneToMany(targetEntity: ProductAttribute::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $attributes;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(targetEntity: ProductImage::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $images;

    public function __construct(string $externalCode)
    {
        $this->externalCode = $externalCode;
        $this->attributes = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    /** @return array<string, mixed> */
    public function toArray(bool $details = true): array
    {
        $data = ['id' => $this->id, 'external_code' => $this->externalCode, 'name' => $this->name, 'description' => $this->description, 'price' => bcadd($this->price, '0', 2), 'discount' => bcadd($this->discount, '0', 2), 'purchase_price' => bcadd($this->purchasePrice, '0', 2)];
        if ($details) {
            $data['attributes'] = array_values($this->attributes->map(fn (ProductAttribute $a): array => ['key' => $a->key, 'value' => $a->value])->toArray());
            $data['images'] = array_values($this->images->map(fn (ProductImage $i): array => ['url' => $i->url, 'path' => $i->path])->toArray());
        }
        return $data;
    }
}
