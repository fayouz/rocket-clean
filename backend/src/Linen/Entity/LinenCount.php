<?php

namespace App\Linen\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Quantity of one type of linen of a place, at a location (reserve|logement), in a state. Changed only by LinenLedger. */
#[ORM\Entity]
#[ORM\Table(name: 'linen_count')]
#[ORM\UniqueConstraint(name: 'uniq_linen_count', columns: ['place_id', 'location', 'item_type_id', 'state'])]
class LinenCount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(length: 16)]
    private string $location;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LinenItemType $itemType;

    #[ORM\Column(length: 16)]
    private string $state;

    #[ORM\Column]
    private int $qty = 0;

    public function __construct(string $placeId, string $location, LinenItemType $itemType, string $state)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->location = $location;
        $this->itemType = $itemType;
        $this->state = $state;
    }

    public function getPlaceId(): string { return $this->placeId; }
    public function getLocation(): string { return $this->location; }
    public function getItemType(): LinenItemType { return $this->itemType; }
    public function getState(): string { return $this->state; }
    public function getQty(): int { return $this->qty; }
    public function add(int $delta): void { $this->qty += $delta; }
}
