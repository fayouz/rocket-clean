<?php

namespace App\Cleaning;

use App\Entity\CleaningTask;
use App\Entity\OccupiedPeriod;
use App\Linen\Contract\ArrivalSource;
use App\Linen\Contract\CleaningJobs;
use App\Linen\Contract\LinenJob;
use App\Linen\Contract\PlaceNames;
use App\Place\PlaceDirectory;
use App\Repository\CleaningTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter of the cleaning side for the linen module (App\Linen): cleanings, stays and places seen through the
 * module's ports. Extracting the module as "Rocket Laundry" means reimplementing these three ports over HTTP.
 */
final class LinenBridge implements CleaningJobs, ArrivalSource, PlaceNames
{
    public function __construct(
        private readonly CleaningTaskRepository $tasks,
        private readonly PlaceDirectory $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function find(string $cleaningId): ?LinenJob
    {
        $task = Uuid::isValid($cleaningId) ? $this->tasks->find($cleaningId) : null;

        return null === $task ? null : self::job($task);
    }

    public static function job(CleaningTask $task): LinenJob
    {
        return new LinenJob($task->getId()->toRfc4122(), $task->getPlaceId(), $task->getPlaceName(), CleaningTask::RENTAL === $task->getType() ? 'rental' : 'personal', $task->getAssignee()?->getId()->toRfc4122());
    }

    public function arrivals(string $placeId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $periods = $this->em->createQueryBuilder()->select('p')->from(OccupiedPeriod::class, 'p')
            ->where('p.placeId = :place AND p.from >= :from AND p.from < :until')->orderBy('p.from', 'ASC')
            ->setParameter('place', $placeId)->setParameter('from', $from)->setParameter('until', $until)
            ->getQuery()->getResult();

        return array_map(static fn (OccupiedPeriod $p) => ['from' => $p->getFrom(), 'until' => $p->getUntil(), 'externalRef' => $p->toArray()['externalRef']], $periods);
    }

    public function all(): array
    {
        return array_map(static fn (array $p) => ['id' => $p['id'], 'name' => $p['name']], $this->places->all());
    }

    public function name(string $placeId): string
    {
        return $this->places->get($placeId)->getName();
    }
}
