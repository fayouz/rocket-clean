<?php

namespace App\Linen\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A batch of a place's dirty linen sent to a laundry: counted lines and weight at departure (dirty → at_laundry),
 * counted lines at return (at_laundry → clean, damaged ones → damaged, missing ones → lost), discrepancies and cost.
 */
#[ORM\Entity]
#[ORM\Table(name: 'linen_batch')]
#[ORM\Index(name: 'idx_linen_batch_status', columns: ['status'])]
class LinenBatch
{
    public const SENT = 'sent';
    public const RETURNED = 'returned';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Laundry $laundry;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(length: 160)]
    private string $placeName;

    #[ORM\Column(length: 16)]
    private string $usage = 'rental';

    #[ORM\Column(length: 16)]
    private string $status = self::SENT;

    /** @var list<array{typeId: string, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $sentLines;

    #[ORM\Column(nullable: true)]
    private ?int $weightGrams = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expectedAt;

    /** @var list<array{typeId: string, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $returnedLines = [];

    /** @var list<array{typeId: string, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $damagedLines = [];

    /** @var list<array{typeId: string, missing: int, damaged: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $discrepancies = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $cost = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailSentAt = null;

    /** @param list<array{typeId: string, qty: int}> $sentLines */
    public function __construct(Laundry $laundry, string $placeId, string $placeName, array $sentLines, \DateTimeImmutable $sentAt)
    {
        $this->id = Uuid::v7();
        $this->laundry = $laundry;
        $this->placeId = $placeId;
        $this->placeName = $placeName;
        $this->sentLines = $sentLines;
        $this->sentAt = $sentAt;
        $this->expectedAt = $sentAt->modify(\sprintf('+%d days', $laundry->getTurnaroundDays()));
    }

    public function getId(): Uuid { return $this->id; }
    public function getLaundry(): Laundry { return $this->laundry; }
    public function getPlaceId(): string { return $this->placeId; }
    public function getPlaceName(): string { return $this->placeName; }
    public function getUsage(): string { return $this->usage; }
    public function setUsage(string $usage): static { $this->usage = $usage; return $this; }
    public function getStatus(): string { return $this->status; }
    /** @return list<array{typeId: string, qty: int}> */
    public function getSentLines(): array { return $this->sentLines; }
    public function getWeightGrams(): ?int { return $this->weightGrams; }
    public function setWeightGrams(?int $grams): static { $this->weightGrams = $grams; return $this; }
    public function getSentAt(): \DateTimeImmutable { return $this->sentAt; }
    public function getExpectedAt(): \DateTimeImmutable { return $this->expectedAt; }
    public function setExpectedAt(\DateTimeImmutable $at): static { $this->expectedAt = $at; return $this; }
    public function getReturnedAt(): ?\DateTimeImmutable { return $this->returnedAt; }
    public function getCost(): ?int { return $this->cost; }
    public function setCost(?int $cost): static { $this->cost = $cost; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }
    public function getEmailSentAt(): ?\DateTimeImmutable { return $this->emailSentAt; }
    public function markEmailSent(): void { $this->emailSentAt = new \DateTimeImmutable(); }

    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return self::SENT === $this->status && $this->expectedAt < $now;
    }

    /**
     * @param list<array{typeId: string, qty: int}>                   $returned
     * @param list<array{typeId: string, qty: int}>                   $damaged
     * @param list<array{typeId: string, missing: int, damaged: int}> $discrepancies
     */
    public function markReturned(array $returned, array $damaged, array $discrepancies, \DateTimeImmutable $at): void
    {
        $this->status = self::RETURNED;
        $this->returnedLines = $returned;
        $this->damagedLines = $damaged;
        $this->discrepancies = $discrepancies;
        $this->returnedAt = $at;
    }

    /** @return array<string, mixed> */
    public function toArray(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        return [
            'id' => $this->id->toRfc4122(), 'laundry' => ['id' => $this->laundry->getId()->toRfc4122(), 'name' => $this->laundry->getName()],
            'placeId' => $this->placeId, 'placeName' => $this->placeName, 'usage' => $this->usage, 'status' => $this->status,
            'sentLines' => $this->sentLines, 'weightGrams' => $this->weightGrams,
            'sentAt' => $this->sentAt->format(\DATE_ATOM), 'expectedAt' => $this->expectedAt->format(\DATE_ATOM), 'overdue' => $this->isOverdue($now),
            'returnedLines' => $this->returnedLines, 'damagedLines' => $this->damagedLines, 'discrepancies' => $this->discrepancies,
            'returnedAt' => $this->returnedAt?->format(\DATE_ATOM), 'cost' => $this->cost, 'note' => $this->note,
            'emailSentAt' => $this->emailSentAt?->format(\DATE_ATOM),
        ];
    }
}
