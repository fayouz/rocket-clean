<?php

namespace App\Cleaning;

use App\Entity\CleaningChecklistItem;
use App\Entity\CleaningCost;
use App\Entity\CleaningTask;
use App\Entity\OccupiedPeriod;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a new cleaning inherits from its place and type (checklist template, default cost) and the conflict flag of
 * personal/maintenance cleanings against the place's occupied periods. Callers flush.
 */
final class CleaningPlanner
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<string> the place's checklist template for the type */
    public function checklist(string $placeId, string $type): array
    {
        return array_map(static fn (CleaningChecklistItem $i) => $i->getLabel(),
            $this->em->getRepository(CleaningChecklistItem::class)->findBy(['placeId' => $placeId, 'type' => $type], ['position' => 'ASC']));
    }

    /** @return list<array{label: string, synonyms?: list<string>, photo?: bool, area?: string}> the template with the assistant's details */
    public function checklistLines(string $placeId, string $type): array
    {
        return array_map(static fn (CleaningChecklistItem $i) => $i->toLine(),
            $this->em->getRepository(CleaningChecklistItem::class)->findBy(['placeId' => $placeId, 'type' => $type], ['position' => 'ASC']));
    }

    /** @return list<array{label: string, synonyms?: list<string>, photo?: bool, area?: string}> checklist copied into a new cleaning: the type's template, else the rental (default) one */
    public function checklistFor(string $placeId, string $type): array
    {
        return $this->checklistLines($placeId, $type) ?: (CleaningTask::RENTAL === $type ? [] : $this->checklistLines($placeId, CleaningTask::RENTAL));
    }

    public function defaultCost(string $placeId, string $type): ?int
    {
        return $this->em->getRepository(CleaningCost::class)->findOneBy(['placeId' => $placeId, 'type' => $type])?->getCost();
    }

    /** @return array<string, ?int> default cost per type */
    public function costs(string $placeId): array
    {
        $out = array_fill_keys(CleaningTask::TYPES, null);
        foreach ($this->em->getRepository(CleaningCost::class)->findBy(['placeId' => $placeId]) as $cost) {
            $out[$cost->getType()] = $cost->getCost();
        }

        return $out;
    }

    /** @param array<string, mixed> $values {"rental": cents|null, ...}; unknown keys ignored */
    public function setCosts(string $placeId, array $values): void
    {
        $repo = $this->em->getRepository(CleaningCost::class);
        foreach (CleaningTask::TYPES as $type) {
            if (!\array_key_exists($type, $values)) {
                continue;
            }
            $existing = $repo->findOneBy(['placeId' => $placeId, 'type' => $type]);
            $cost = self::cents($values[$type]);
            if (null === $cost) {
                null !== $existing && $this->em->remove($existing);
            } elseif (null === $existing) {
                $this->em->persist(new CleaningCost($placeId, $type, $cost));
            } else {
                $existing->setCost($cost);
            }
        }
    }

    /** Recomputes the conflict flag of one cleaning (rental cleanings never conflict: they happen between stays). */
    public function refreshConflict(CleaningTask $task): void
    {
        if (CleaningTask::RENTAL === $task->getType()) {
            $task->setConflict(false);

            return;
        }
        $conflict = false;
        foreach ($this->em->getRepository(OccupiedPeriod::class)->findBy(['placeId' => $task->getPlaceId()]) as $period) {
            if ($period->overlaps($task->getScheduledAt(), $task->getEndsAt())) {
                $conflict = true;
                break;
            }
        }
        $task->setConflict($conflict);
    }

    /** Cents from a JSON value: null/"" → null, else a non-negative integer (anything else throws). */
    public static function cents(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('Coût attendu en centimes (entier positif).');
        }

        return $value;
    }
}
