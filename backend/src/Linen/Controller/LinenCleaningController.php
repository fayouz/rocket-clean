<?php

namespace App\Linen\Controller;

use App\Linen\CleaningLinen;
use App\Linen\Contract\CleaningJobs;
use App\Linen\Contract\LinenJob;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "Linge" step of a cleaning: kits and pieces removed, placed, found damaged. Open to the cleaning's assignee
 * (or anyone when unassigned) and to managers; the cleaning is reached through the CleaningJobs port only.
 */
#[IsGranted('LINEN_READ')]
final class LinenCleaningController extends LinenApiController
{
    public function __construct(
        private readonly CleaningJobs $jobs,
        private readonly CleaningLinen $linen,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** {kits, types, movements already recorded by this cleaning}. */
    #[Route('/api/cleanings/{id}/linen', name: 'api_cleaning_linen', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(string $id): JsonResponse
    {
        return $this->json($this->linen->view($this->job($id)));
    }

    /**
     * {"key"?: idempotency key, "removed": [{"kit"|"type": uuid, "qty"}], "placed": [...], "damaged": [...]}:
     * removed in_use → dirty, placed clean → in_use, damaged in_use → damaged, refs cleaning:<id>:linen:<key>.
     */
    #[Route('/api/cleanings/{id}/linen', name: 'api_cleaning_linen_record', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function record(string $id, Request $request): JsonResponse
    {
        $job = $this->job($id);
        $result = $this->linen->record($job, $request->toArray(), $this->actor());
        $this->em->flush();

        return $this->json($result + $this->linen->view($job), $result['alreadyRecorded'] ? 200 : 201);
    }

    private function job(string $id): LinenJob
    {
        $job = $this->jobs->find($id) ?? throw new HttpException(404, 'Ménage inconnu.');
        $me = $this->getUser();
        if (!$this->isGranted('LINEN_MANAGE') && null !== $job->assigneeId && (!$me instanceof User || $me->getId()->toRfc4122() !== $job->assigneeId)) {
            throw new HttpException(403, 'Ce ménage est attribué à quelqu’un d’autre.');
        }

        return $job;
    }
}
