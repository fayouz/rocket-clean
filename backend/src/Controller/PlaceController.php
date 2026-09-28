<?php

namespace App\Controller;

use App\Entity\CleaningChecklistItem;
use App\Entity\CleaningTask;
use App\Entity\Site;
use App\Place\PlaceDirectory;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The places cleanings are about: Rocket Place's when configured (read only here, managed in Place), else local
 * places (Site) created, renamed and deleted here by an administrator. {"id", "name", "source": local|place}.
 */
#[IsGranted('CLEAN_READ')]
final class PlaceController extends AbstractController
{
    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** A plain list, as Rocket Place answers; "source" tells where each place lives. */
    #[Route('/api/places', name: 'api_places', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->places->all());
    }

    #[Route('/api/places/{placeId}', name: 'api_place', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function show(string $placeId): JsonResponse
    {
        $site = $this->places->get($placeId);
        $this->em->flush();

        return $this->json($site->toArray());
    }

    /** JSON {"name": string}. Standalone only (with Rocket Place, places are created there). */
    #[Route('/api/places', name: 'api_place_create', methods: ['POST'])]
    #[IsGranted('CLEAN_MANAGE')]
    public function create(Request $request): JsonResponse
    {
        $this->assertLocal();
        $site = new Site($this->name($request));
        $this->em->persist($site);
        $this->em->flush();

        return $this->json($site->toArray(), 201);
    }

    #[Route('/api/places/{placeId}', name: 'api_place_update', methods: ['PATCH'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function update(string $placeId, Request $request): JsonResponse
    {
        $this->assertLocal();
        $site = $this->places->get($placeId);
        $site->setName($this->name($request));
        foreach ($this->em->getRepository(CleaningTask::class)->findBy(['placeId' => $placeId]) as $task) {
            $task->setPlaceName($site->getName());
        }
        $this->em->flush();

        return $this->json($site->toArray());
    }

    /** Deletes a local place with its cleanings and checklist. */
    #[Route('/api/places/{placeId}', name: 'api_place_delete', methods: ['DELETE'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function delete(string $placeId): JsonResponse
    {
        $this->assertLocal();
        $site = $this->places->get($placeId);
        foreach ([CleaningTask::class, CleaningChecklistItem::class] as $class) {
            foreach ($this->em->getRepository($class)->findBy(['placeId' => $placeId]) as $row) {
                $this->em->remove($row);
            }
        }
        $this->em->remove($site);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private function assertLocal(): void
    {
        if ($this->places->isRemote()) {
            throw new HttpException(409, 'Les lieux sont gérés dans Rocket Place (ROCKET_PLACE_URL configuré).');
        }
    }

    private function name(Request $request): string
    {
        $name = mb_substr(trim((string) ($request->toArray()['name'] ?? '')), 0, 160);

        return '' !== $name ? $name : throw new HttpException(422, 'Nom requis.');
    }
}
