<?php

namespace App\Entity;

use App\Repository\CleaningTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A cleaning of a place ("ménage"). The place is referenced by its id (Rocket Place's, or a local Site in standalone
 * mode) with its name cached at creation (placeName), never by a foreign key: Rocket Clean owns no place.
 * A cleaning has: a window (scheduledAt → dueAt), a status, an optional assignee (a user), a
 * checklist copied from the place's template at creation, notes, photos (Rocket Cloud file ids in the place's folder)
 * and stock reports (levels set on the place's StockLevel during the cleaning). Created by hand or by a client app
 * (e.g. a PMS after a departure) with an `externalRef`, unique per place, which makes creation idempotent.
 */
#[ORM\Entity(repositoryClass: CleaningTaskRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_cleaning_task_place_external_ref', columns: ['place_id', 'external_ref'])]
#[ORM\Index(name: 'idx_cleaning_task_scheduled_at', columns: ['scheduled_at'])]
#[ORM\Index(name: 'idx_cleaning_task_place', columns: ['place_id'])]
class CleaningTask
{
    public const TODO = 'todo';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::TODO, self::IN_PROGRESS, self::DONE, self::CANCELLED];
    public const PHOTO_MOMENTS = ['before', 'after', 'damage'];
    public const MAX_PHOTOS = 30;
    public const RENTAL = 'rental';
    public const PERSONAL = 'personal';
    public const MAINTENANCE = 'maintenance';
    public const TYPES = [self::RENTAL, self::PERSONAL, self::MAINTENANCE];
    /** Who created the task: the host (Rocket Host), a PMS, Rocket Place, a user of Rocket Clean, or a recurrence. */
    public const ORIGINS = ['host', 'pms', 'place', 'clean', 'recurrence'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(length: 160)]
    private string $placeName;

    #[ORM\Column(length: 120)]
    private string $label;

    /** rental (turnover between stays), personal (the owner's own use) or maintenance. */
    #[ORM\Column(length: 16, options: ['default' => 'personal'])]
    private string $type = self::PERSONAL;

    #[ORM\Column(length: 16, options: ['default' => 'clean'])]
    private string $origin = 'clean';

    /** Name of the application that created the task (app token), which may keep editing it. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $originApp = null;

    /** Cost in cents (optional; defaults to the place's cost for the type). */
    #[ORM\Column(nullable: true)]
    private ?int $cost = null;

    /** A personal/maintenance task overlapping an occupied period of the place (OccupiedPeriod). */
    #[ORM\Column(options: ['default' => false])]
    private bool $conflict = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(length: 16)]
    private string $status = self::TODO;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var list<array{label: string, done: bool, synonyms?: list<string>, photo?: bool, area?: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $checklist = [];

    /** Problems reported during the cleaning (by voice or by hand): @var list<array{text: string, at: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $incidents = [];

    /** Compte rendu, written when the cleaning is completed (CleaningReport); null before. @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $report = null;

    /** @var list<array{fileId: string, name: string, moment: string, at: string, area?: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $photos = [];

    /** @var list<array{stockLevelId: string, item: string, level: string, at: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $stockReports = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Salt of the secret link (/m/<token>, CleaningLinkSigner); null: no link (never generated, or revoked). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $linkSalt = null;

    use TrackedTrait;

    /** @param list<string|array{label: string, synonyms?: list<string>, photo?: bool, area?: string}> $checklist template lines */
    public function __construct(string $placeId, string $placeName, string $label, \DateTimeImmutable $scheduledAt, array $checklist = [], ?string $externalRef = null)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->placeName = $placeName;
        $this->label = $label;
        $this->scheduledAt = $scheduledAt;
        $this->checklist = array_map(static fn (string|array $l) => \is_string($l) ? ['label' => $l, 'done' => false] : ['label' => $l['label'], 'done' => false] + array_intersect_key($l, ['synonyms' => 1, 'photo' => 1, 'area' => 1]), array_values($checklist));
        $this->externalRef = $externalRef;
        $this->type = null !== $externalRef && str_starts_with($externalRef, 'booking:') ? self::RENTAL : self::PERSONAL;
    }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getOrigin(): string { return $this->origin; }
    public function getOriginApp(): ?string { return $this->originApp; }
    public function setOrigin(string $origin, ?string $app = null): static { $this->origin = $origin; $this->originApp = $app; return $this; }
    public function getCost(): ?int { return $this->cost; }
    public function setCost(?int $cost): static { $this->cost = $cost; return $this; }
    public function hasConflict(): bool { return $this->conflict; }
    public function setConflict(bool $conflict): static { $this->conflict = $conflict; return $this; }

    /** End of the window used for overlaps: dueAt, else scheduledAt. */
    public function getEndsAt(): \DateTimeImmutable { return $this->dueAt ?? $this->scheduledAt; }

    public function getId(): Uuid { return $this->id; }
    public function getPlaceId(): string { return $this->placeId; }
    public function getPlaceName(): string { return $this->placeName; }
    public function setPlaceName(string $name): static { $this->placeName = $name; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }
    public function getScheduledAt(): \DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(\DateTimeImmutable $at): static { $this->scheduledAt = $at; return $this; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $at): static { $this->dueAt = $at; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getAssignee(): ?User { return $this->assignee; }
    public function setAssignee(?User $user): static { $this->assignee = $user; return $this; }
    public function getExternalRef(): ?string { return $this->externalRef; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    /** @return list<array{label: string, done: bool}> */
    public function getChecklist(): array { return $this->checklist; }
    /** @return list<array{fileId: string, name: string, moment: string, at: string}> */
    public function getPhotos(): array { return $this->photos; }

    /** @return list<array{text: string, at: string}> */
    public function getIncidents(): array { return $this->incidents; }

    public function addIncident(string $text, \DateTimeImmutable $at): static
    {
        $this->incidents[] = ['text' => $text, 'at' => $at->format(\DATE_ATOM)];

        return $this;
    }

    /** @return list<array{stockLevelId: string, item: string, level: string, at: string, quantity?: float}> */
    public function getStockReports(): array { return $this->stockReports; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    /** @return array<string, mixed>|null */
    public function getReport(): ?array { return $this->report; }
    /** @param array<string, mixed>|null $report */
    public function setReport(?array $report): static { $this->report = $report; return $this; }

    public function getLinkSalt(): ?string { return $this->linkSalt; }

    /** New salt: a new secret link, the previous one stops working. */
    public function regenerateLink(): static { $this->linkSalt = bin2hex(random_bytes(16)); return $this; }

    public function revokeLink(): static { $this->linkSalt = null; return $this; }

    public function setStatus(string $status, \DateTimeImmutable $now): static
    {
        $this->status = $status;
        if (self::IN_PROGRESS === $status && null === $this->startedAt) {
            $this->startedAt = $now;
        }
        $this->completedAt = self::DONE === $status ? ($this->completedAt ?? $now) : null;

        return $this;
    }

    public function checkItem(int $index, bool $done): static
    {
        if (!isset($this->checklist[$index])) {
            throw new \OutOfRangeException('Point de checklist inconnu.');
        }
        $this->checklist[$index]['done'] = $done;

        return $this;
    }

    public function addPhoto(string $fileId, string $name, string $moment, \DateTimeImmutable $at, ?string $area = null): static
    {
        $this->photos[] = ['fileId' => $fileId, 'name' => $name, 'moment' => $moment, 'at' => $at->format(\DATE_ATOM)] + (null === $area ? [] : ['area' => $area]);

        return $this;
    }

    public function addStockReport(string $stockLevelId, string $item, string $level, \DateTimeImmutable $at, ?float $quantity = null): static
    {
        $this->stockReports[] = ['stockLevelId' => $stockLevelId, 'item' => $item, 'level' => $level, 'at' => $at->format(\DATE_ATOM)] + (null === $quantity ? [] : ['quantity' => $quantity]);

        return $this;
    }

    public function isLate(\DateTimeImmutable $now): bool
    {
        return \in_array($this->status, [self::TODO, self::IN_PROGRESS], true) && ($this->dueAt ?? $this->scheduledAt->setTime(23, 59, 59)) < $now;
    }

    /** @return array<string, mixed> */
    public function toArray(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'placeId' => $this->placeId, 'placeName' => $this->placeName,
            'label' => $this->label, 'type' => $this->type, 'origin' => $this->origin, 'originApp' => $this->originApp,
            'cost' => $this->cost, 'conflict' => $this->conflict,
            'scheduledAt' => $this->scheduledAt->format(\DATE_ATOM), 'dueAt' => $this->dueAt?->format(\DATE_ATOM),
            'status' => $this->status, 'late' => $this->isLate($now),
            'assignee' => null === $this->assignee ? null : ['id' => $this->assignee->getId()->toRfc4122(), 'email' => $this->assignee->getEmail(), 'name' => $this->assignee->getDisplayName()],
            'externalRef' => $this->externalRef, 'notes' => $this->notes,
            'checklist' => $this->checklist, 'photos' => $this->photos, 'stockReports' => $this->stockReports,
            'incidents' => $this->incidents, 'hasReport' => null !== $this->report,
            'startedAt' => $this->startedAt?->format(\DATE_ATOM), 'completedAt' => $this->completedAt?->format(\DATE_ATOM),
        ];
    }
}
