<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_images')]
class ProductImage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Product $product,
        #[ORM\Column(type: 'text')]
        public string $url,
        #[ORM\Column(length: 255)]
        public string $path,
    ) {
    }
}
