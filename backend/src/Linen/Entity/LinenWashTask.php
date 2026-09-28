<?php

namespace App\Linen\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * In-house washing of a place's dirty linen, assignable like a cleaning: steps (machine, séchage, pliage) and lines
 * [{typeId, qty}]. Completing it moves the lines dirty → clean (refs wash:<id>:<typeId>).
 */
#[ORM\Entity]
#[ORM\Table(name: 'linen_wash_task')]
#[ORM\Index(name: 'idx_linen_wash_task_scheduled', columns: ['scheduled_at'])]
class LinenWashTask
{
    public const TODO = 'todo';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::TODO, self::IN_PROGRESS, self::DONE, self::CANCELLED];
    public const STEPS = ['machine', 'sechage', 'pliage'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(length: 160)]
    private string $placeName;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $assignee = null;

    #[ORM\Column(length: 16)]
    private string $status = self::TODO;

    /** @var array<string, bool> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps = ['machine' => false, 'sechage' => false, 'pliage' => false];

    /** @var list<array{typeId: string, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $lines;

    #[ORM\Column(length: 16)]
    private string $usage = 'rental';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @param list<array{typeId: string, qty: int}> $lines */
    public function __construct(string $placeId, string $placeName, string $label, \DateTimeImmutable $scheduledAt, array $lines)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->placeName = $placeName;
        $this->label = $label;
        $this->scheduledAt = $scheduledAt;
        $this->lines = $lines;
    }

    public function getId(): Uuid { return $this->id; }
    public function getPlaceId(): string { return $this->placeId; }
    public function getPlaceName(): string { return $this->placeName; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }
    public function getScheduledAt(): \DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(\DateTimeImmutable $at): static { $this->scheduledAt = $at; return $this; }
    public function getAssignee(): ?User { return $this->assignee; }
    public function setAssignee(?User $user): static { $this->assignee = $user; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->completedAt = self::DONE === $status ? ($this->completedAt ?? new \DateTimeImmutable()) : null;

        return $this;
    }
    public function setStep(string $step, bool $done): static { $this->steps[$step] = $done; return $this; }
    /** @return list<array{typeId: string, qty: int}> */
    public function getLines(): array { return $this->lines; }
    /** @param list<array{typeId: string, qty: int}> $lines */
    public function setLines(array $lines): static { $this->lines = $lines; return $this; }
    public function getUsage(): string { return $this->usage; }
    public function setUsage(string $usage): static { $this->usage = $usage; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(), 'placeId' => $this->placeId, 'placeName' => $this->placeName, 'label' => $this->label,
            'scheduledAt' => $this->scheduledAt->format(\DATE_ATOM), 'status' => $this->status, 'steps' => $this->steps,
            'lines' => $this->lines, 'usage' => $this->usage,
            'assignee' => null === $this->assignee ? null : ['id' => $this->assignee->getId()->toRfc4122(), 'email' => $this->assignee->getEmail(), 'name' => $this->assignee->getDisplayName()],
            'completedAt' => $this->completedAt?->format(\DATE_ATOM),
        ];
    }
}
