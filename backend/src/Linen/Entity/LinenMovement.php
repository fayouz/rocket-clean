<?php

namespace App\Linen\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every change of the linen counts: qty of a type goes from a state/location to another. A null "from" is an entry
 * (purchase, inventory found more), a null "to" an exit (inventory found less, replaced). externalRef makes it
 * idempotent (unique): a replayed change is not applied twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'linen_movement')]
#[ORM\UniqueConstraint(name: 'uniq_linen_movement_ref', columns: ['external_ref'])]
#[ORM\Index(name: 'idx_linen_movement_place', columns: ['place_id', 'created_at'])]
class LinenMovement
{
    public const CLEAN = 'clean';
    public const IN_USE = 'in_use';
    public const DIRTY = 'dirty';
    public const AT_LAUNDRY = 'at_laundry';
    public const DAMAGED = 'damaged';
    public const LOST = 'lost';
    public const STATES = [self::CLEAN, self::IN_USE, self::DIRTY, self::AT_LAUNDRY, self::DAMAGED, self::LOST];
    public const RESERVE = 'reserve';
    public const LOGEMENT = 'logement';
    public const LOCATIONS = [self::RESERVE, self::LOGEMENT];
    /** Who caused it: a cleaning, the host (Rocket Host / PMS), or the linen module itself. */
    public const ORIGINS = ['clean', 'host', 'linen'];
    public const USAGES = ['rental', 'personal'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LinenItemType $itemType;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $fromState;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $fromLocation;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $toState;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $toLocation;

    #[ORM\Column]
    private int $qty;

    #[ORM\Column(length: 160)]
    private string $reason;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $externalRef;

    #[ORM\Column(length: 16)]
    private string $origin;

    #[ORM\Column(length: 16)]
    private string $usage;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $placeId, LinenItemType $itemType, ?string $fromState, ?string $fromLocation, ?string $toState, ?string $toLocation, int $qty, string $reason, ?string $externalRef, string $origin, string $usage, ?string $createdBy)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->itemType = $itemType;
        $this->fromState = $fromState;
        $this->fromLocation = $fromLocation;
        $this->toState = $toState;
        $this->toLocation = $toLocation;
        $this->qty = $qty;
        $this->reason = $reason;
        $this->externalRef = $externalRef;
        $this->origin = $origin;
        $this->usage = $usage;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Where linen in that state lives by default: in the accommodation when in use, else in the reserve. */
    public static function defaultLocation(string $state): string
    {
        return self::IN_USE === $state ? self::LOGEMENT : self::RESERVE;
    }

    public function getPlaceId(): string { return $this->placeId; }
    public function getItemType(): LinenItemType { return $this->itemType; }
    public function getFromState(): ?string { return $this->fromState; }
    public function getToState(): ?string { return $this->toState; }
    public function getQty(): int { return $this->qty; }
    public function getUsage(): string { return $this->usage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(), 'placeId' => $this->placeId,
            'typeId' => $this->itemType->getId()->toRfc4122(), 'typeName' => $this->itemType->getName(),
            'from' => $this->fromState, 'fromLocation' => $this->fromLocation, 'to' => $this->toState, 'toLocation' => $this->toLocation,
            'qty' => $this->qty, 'reason' => $this->reason, 'externalRef' => $this->externalRef, 'origin' => $this->origin, 'usage' => $this->usage,
            'createdBy' => $this->createdBy, 'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
