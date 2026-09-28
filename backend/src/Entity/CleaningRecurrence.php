<?php

namespace App\Entity;

use App\Repository\CleaningRecurrenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A recurring cleaning of a place (e.g. every Monday and Thursday at 09:00, or the first Saturday of the month):
 * App\Cleaning\RecurrenceGenerator creates its tasks some days ahead, one per matching day, idempotent by the
 * externalRef "recurrence:<id>:<YYYY-MM-DD>". Rule:
 * - weekly: "weekdays" (ISO 1 = Monday … 7 = Sunday);
 * - monthly: "monthDay" (1–31, skipped in shorter months), or "nth" (1–5, -1 = last) + "nthWeekday" (1–7).
 */
#[ORM\Entity(repositoryClass: CleaningRecurrenceRepository::class)]
#[ORM\Index(name: 'idx_cleaning_recurrence_place', columns: ['place_id'])]
class CleaningRecurrence
{
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'place_id', length: 36)]
    private string $placeId;

    #[ORM\Column(length: 160)]
    private string $placeName;

    #[ORM\Column(length: 16)]
    private string $type = CleaningTask::PERSONAL;

    #[ORM\Column(length: 120)]
    private string $label = 'Ménage';

    #[ORM\Column(length: 8)]
    private string $frequency = self::WEEKLY;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [];

    #[ORM\Column(nullable: true)]
    private ?int $monthDay = null;

    #[ORM\Column(nullable: true)]
    private ?int $nth = null;

    #[ORM\Column(nullable: true)]
    private ?int $nthWeekday = null;

    /** Local time "HH:MM". */
    #[ORM\Column(length: 5)]
    private string $time = '10:00';

    #[ORM\Column]
    private int $durationMinutes = 120;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    /** @var list<string> own checklist; empty: the place's template for the type */
    #[ORM\Column(type: Types::JSON)]
    private array $checklist = [];

    #[ORM\Column(nullable: true)]
    private ?int $cost = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    use TrackedTrait;

    public function __construct(string $placeId, string $placeName, \DateTimeImmutable $startsOn)
    {
        $this->id = Uuid::v7();
        $this->placeId = $placeId;
        $this->placeName = $placeName;
        $this->startsOn = $startsOn->setTime(0, 0);
    }

    public function getId(): Uuid { return $this->id; }
    public function getPlaceId(): string { return $this->placeId; }
    public function getPlaceName(): string { return $this->placeName; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }
    public function getFrequency(): string { return $this->frequency; }
    /** @param list<int> $weekdays */
    public function setWeekly(array $weekdays): static { $this->frequency = self::WEEKLY; $this->weekdays = $weekdays; $this->monthDay = $this->nth = $this->nthWeekday = null; return $this; }
    public function setMonthly(?int $monthDay, ?int $nth, ?int $nthWeekday): static { $this->frequency = self::MONTHLY; $this->weekdays = []; $this->monthDay = $monthDay; $this->nth = $nth; $this->nthWeekday = $nthWeekday; return $this; }
    public function getTime(): string { return $this->time; }
    public function setTime(string $time): static { $this->time = $time; return $this; }
    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $minutes): static { $this->durationMinutes = $minutes; return $this; }
    public function getAssignee(): ?User { return $this->assignee; }
    public function setAssignee(?User $user): static { $this->assignee = $user; return $this; }
    /** @return list<string> */
    public function getChecklist(): array { return $this->checklist; }
    /** @param list<string> $checklist */
    public function setChecklist(array $checklist): static { $this->checklist = $checklist; return $this; }
    public function getCost(): ?int { return $this->cost; }
    public function setCost(?int $cost): static { $this->cost = $cost; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(\DateTimeImmutable $d): static { $this->startsOn = $d->setTime(0, 0); return $this; }
    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $d): static { $this->endsOn = $d?->setTime(0, 0); return $this; }

    public function externalRefFor(\DateTimeImmutable $day): string
    {
        return 'recurrence:'.$this->id->toRfc4122().':'.$day->format('Y-m-d');
    }

    /** Whether the rule gives a cleaning on that day (time ignored). */
    public function matches(\DateTimeImmutable $day): bool
    {
        $day = $day->setTime(0, 0);
        if (!$this->active || $day < $this->startsOn || (null !== $this->endsOn && $day > $this->endsOn)) {
            return false;
        }
        if (self::WEEKLY === $this->frequency) {
            return \in_array((int) $day->format('N'), $this->weekdays, true);
        }
        if (null !== $this->monthDay) {
            return (int) $day->format('j') === $this->monthDay;
        }
        if (null === $this->nth || null === $this->nthWeekday || (int) $day->format('N') !== $this->nthWeekday) {
            return false;
        }
        $occurrence = intdiv((int) $day->format('j') - 1, 7) + 1;
        $isLast = (int) $day->modify('+7 days')->format('n') !== (int) $day->format('n');

        return -1 === $this->nth ? $isLast : $occurrence === $this->nth;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(), 'placeId' => $this->placeId, 'placeName' => $this->placeName,
            'type' => $this->type, 'label' => $this->label, 'frequency' => $this->frequency, 'weekdays' => $this->weekdays,
            'monthDay' => $this->monthDay, 'nth' => $this->nth, 'nthWeekday' => $this->nthWeekday,
            'time' => $this->time, 'durationMinutes' => $this->durationMinutes,
            'assignee' => null === $this->assignee ? null : ['id' => $this->assignee->getId()->toRfc4122(), 'email' => $this->assignee->getEmail(), 'name' => $this->assignee->getDisplayName()],
            'checklist' => $this->checklist, 'cost' => $this->cost, 'active' => $this->active,
            'startsOn' => $this->startsOn->format('Y-m-d'), 'endsOn' => $this->endsOn?->format('Y-m-d'),
        ];
    }
}
