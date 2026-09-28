<?php

namespace App\Cleaning;

use App\Entity\CleaningRecurrence;
use App\Entity\CleaningTask;
use App\Repository\CleaningRecurrenceRepository;
use App\Repository\CleaningTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the cleanings of the active recurrences for the next CLEANING_RECURRENCE_DAYS days (default 14), one per
 * matching day, idempotent by externalRef "recurrence:<id>:<date>" (a task deleted by hand is recreated only if still
 * ahead and missing). Run daily by the worker (CleaningSchedule) and right after a recurrence is saved.
 */
final class RecurrenceGenerator
{
    public function __construct(
        private readonly CleaningRecurrenceRepository $recurrences,
        private readonly CleaningTaskRepository $tasks,
        private readonly CleaningPlanner $planner,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(int:CLEANING_RECURRENCE_DAYS)%')] private readonly int $days = 14,
    ) {
    }

    /** @return int number of cleanings created (flushed) */
    public function generate(?CleaningRecurrence $only = null, \DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $created = 0;
        $today = $now->setTime(0, 0);
        foreach (null === $only ? $this->recurrences->findBy(['active' => true]) : [$only] as $recurrence) {
            for ($i = 0; $i <= $this->days; ++$i) {
                $day = $today->modify("+$i days");
                if (!$recurrence->matches($day)) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', $recurrence->getTime()));
                $at = $day->setTime($h, $m);
                $ref = $recurrence->externalRefFor($day);
                if ($at < $now || null !== $this->tasks->findOneBy(['placeId' => $recurrence->getPlaceId(), 'externalRef' => $ref])) {
                    continue;
                }
                $checklist = $recurrence->getChecklist() ?: $this->planner->checklistFor($recurrence->getPlaceId(), $recurrence->getType());
                $task = (new CleaningTask($recurrence->getPlaceId(), $recurrence->getPlaceName(), $recurrence->getLabel(), $at, $checklist, $ref))
                    ->setType($recurrence->getType())->setOrigin('recurrence')
                    ->setDueAt($at->modify('+'.$recurrence->getDurationMinutes().' minutes'))
                    ->setAssignee($recurrence->getAssignee())
                    ->setCost($recurrence->getCost() ?? $this->planner->defaultCost($recurrence->getPlaceId(), $recurrence->getType()));
                $this->planner->refreshConflict($task);
                $this->em->persist($task);
                ++$created;
            }
        }
        $this->em->flush();

        return $created;
    }
}
