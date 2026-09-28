<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A period when a place is occupied (a stay), pushed by Rocket Host or a PMS (PUT /api/places/{placeId}/occupancy,
 * which replaces the whole list of the place). A personal or maintenance cleaning overlapping one is flagged
 * (CleaningTask::$conflict).
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_occupied_period_place', columns: ['place_id'])]
class OccupiedPeriod
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(name: 'starts_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $from;

    #[ORM\Column(name: 'ends_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $until;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalRef;

    public function __construct(string $placeId, \DateTimeImmutable $from, \DateTimeImmutable $until, ?string $externalRef = null)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->from = $from;
        $this->until = $until;
        $this->externalRef = $externalRef;
    }

    public function getPlaceId(): string { return $this->placeId; }
    public function getFrom(): \DateTimeImmutable { return $this->from; }
    public function getUntil(): \DateTimeImmutable { return $this->until; }

    public function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $start < $this->until && $end > $this->from;
    }

    /** @return array{from: string, until: string, externalRef: ?string} */
    public function toArray(): array
    {
        return ['from' => $this->from->format(\DATE_ATOM), 'until' => $this->until->format(\DATE_ATOM), 'externalRef' => $this->externalRef];
    }
}
