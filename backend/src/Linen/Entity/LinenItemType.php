<?php

namespace App\Linen\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A kind of linen (housse, drap, taie, grande serviette…). Optional: the Rocket Stock item bought to replace it
 * (stockItemId) and its weight (grams, to estimate a laundry batch's weight).
 */
#[ORM\Entity]
#[ORM\Table(name: 'linen_item_type')]
class LinenItemType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $stockItemId = null;

    #[ORM\Column(nullable: true)]
    private ?int $weightGrams = null;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(string $name, ?string $id = null)
    {
        $this->id = null === $id ? Uuid::v7() : Uuid::fromString($id);
        $this->name = $name;
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getStockItemId(): ?string { return $this->stockItemId; }
    public function setStockItemId(?string $id): static { $this->stockItemId = $id; return $this; }
    public function getWeightGrams(): ?int { return $this->weightGrams; }
    public function setWeightGrams(?int $grams): static { $this->weightGrams = $grams; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id->toRfc4122(), 'name' => $this->name, 'stockItemId' => $this->stockItemId, 'weightGrams' => $this->weightGrams, 'position' => $this->position];
    }
}
