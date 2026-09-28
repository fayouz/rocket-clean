<?php

namespace App\Linen\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Needs of a place for a kit: "units" (beds or bathrooms using that kit: one kit each per arrival) and the par level
 * "kitsPerUnit" (kits owned per unit, e.g. 3 per double bed: one in use, one clean, one at the laundry).
 */
#[ORM\Entity]
#[ORM\Table(name: 'linen_par')]
#[ORM\UniqueConstraint(name: 'uniq_linen_par_place_kit', columns: ['place_id', 'kit_id'])]
class LinenPar
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LinenKit $kit;

    #[ORM\Column]
    private int $units;

    #[ORM\Column]
    private int $kitsPerUnit;

    public function __construct(string $placeId, LinenKit $kit, int $units, int $kitsPerUnit)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->kit = $kit;
        $this->units = $units;
        $this->kitsPerUnit = $kitsPerUnit;
    }

    public function getPlaceId(): string { return $this->placeId; }
    public function getKit(): LinenKit { return $this->kit; }
    public function getUnits(): int { return $this->units; }
    public function getKitsPerUnit(): int { return $this->kitsPerUnit; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['placeId' => $this->placeId, 'kitId' => $this->kit->getId()->toRfc4122(), 'kitName' => $this->kit->getName(), 'units' => $this->units, 'kitsPerUnit' => $this->kitsPerUnit, 'parLevel' => $this->units * $this->kitsPerUnit];
    }
}
