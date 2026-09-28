<?php

namespace App\Repository;

use App\Entity\CleaningRecurrence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CleaningRecurrence> */
class CleaningRecurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CleaningRecurrence::class);
    }
}
