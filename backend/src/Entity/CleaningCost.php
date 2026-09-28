<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Default cost (cents) of a type of cleaning at a place, copied into new cleanings that do not give their own. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_cleaning_cost_place_type', columns: ['place_id', 'type'])]
class CleaningCost
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column]
    private int $cost;

    public function __construct(string $placeId, string $type, int $cost)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->type = $type;
        $this->cost = $cost;
    }

    public function getType(): string { return $this->type; }
    public function getCost(): int { return $this->cost; }
    public function setCost(int $cost): static { $this->cost = $cost; return $this; }
}
