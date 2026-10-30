<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_attributes')]
class ProductAttribute
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Product $product,
        #[ORM\Column(name: '`key`', length: 255)]
        public string $key,
        #[ORM\Column(name: 'value', type: 'text')]
        public string $value,
    ) {
    }
}
