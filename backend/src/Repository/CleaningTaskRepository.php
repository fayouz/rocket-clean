<?php

namespace App\Repository;

use App\Entity\CleaningTask;
use Rocket\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CleaningTask> */
class CleaningTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CleaningTask::class);
    }

    /**
     * Tasks whose window meets [$from, $to[, plus (when $withLate) older ones still open.
     *
     * @return list<CleaningTask>
     */
    public function search(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $placeId = null, ?User $assignee = null, bool $withLate = false): array
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.scheduledAt', 'ASC');
        if (null !== $from && null !== $to) {
            $window = 't.scheduledAt < :to AND COALESCE(t.dueAt, t.scheduledAt) >= :from';
            $qb->setParameter('from', $from)->setParameter('to', $to);
            if ($withLate) {
                $window = "($window) OR (t.scheduledAt < :from AND t.status IN (:open))";
                $qb->setParameter('open', [CleaningTask::TODO, CleaningTask::IN_PROGRESS]);
            }
            $qb->andWhere($window);
        }
        if (null !== $placeId) {
            $qb->andWhere('t.placeId = :place')->setParameter('place', $placeId);
        }
        if (null !== $assignee) {
            $qb->andWhere('t.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<CleaningTask> open tasks whose deadline has passed */
    public function late(\DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->createQueryBuilder('t')->where('t.status IN (:open)')->andWhere('t.scheduledAt < :now')
                ->setParameter('open', [CleaningTask::TODO, CleaningTask::IN_PROGRESS])->setParameter('now', $now)
                ->orderBy('t.scheduledAt', 'ASC')->getQuery()->getResult(),
            static fn (CleaningTask $t) => $t->isLate($now),
        ));
    }
}
