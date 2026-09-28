<?php

namespace App\Linen\Controller;

use App\Linen\Contract\PlaceNames;
use App\Linen\Entity\LinenCount;
use App\Linen\Entity\LinenItemType;
use App\Linen\Entity\LinenKit;
use App\Linen\Entity\LinenMovement;
use App\Linen\Entity\LinenPar;
use App\Linen\LinenCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/** Types of linen, kits and the needs of each place (par levels). Reading: LINEN_READ; changes: LINEN_MANAGE. */
#[IsGranted('LINEN_READ')]
final class LinenCatalogController extends LinenApiController
{
    public function __construct(
        private readonly LinenCatalog $catalog,
        private readonly PlaceNames $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/linen/types', name: 'api_linen_types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        return $this->json(array_map(static fn (LinenItemType $t) => $t->toArray(), $this->catalog->types()));
    }

    /** JSON {"name", "stockItemId"?: uuid|null (Rocket Stock item to buy it again), "weightGrams"?: int|null, "position"?: int}. */
    #[Route('/api/linen/types', name: 'api_linen_type_create', methods: ['POST'])]
    #[IsGranted('LINEN_MANAGE')]
    public function createType(Request $request): JsonResponse
    {
        $type = new LinenItemType('');
        $this->applyType($type, $request->toArray() + ['name' => null]);
        $this->em->persist($type);
        $this->em->flush();

        return $this->json($type->toArray(), 201);
    }

    #[Route('/api/linen/types/{id}', name: 'api_linen_type_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function updateType(#[MapEntity] LinenItemType $type, Request $request): JsonResponse
    {
        $this->applyType($type, $request->toArray());
        $this->em->flush();

        return $this->json($type->toArray());
    }

    /** Only a type never counted nor moved (409 otherwise). */
    #[Route('/api/linen/types/{id}', name: 'api_linen_type_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function deleteType(#[MapEntity] LinenItemType $type): JsonResponse
    {
        if (null !== $this->em->getRepository(LinenMovement::class)->findOneBy(['itemType' => $type]) || null !== $this->em->getRepository(LinenCount::class)->findOneBy(['itemType' => $type])) {
            throw new HttpException(409, 'Ce type de linge a déjà des mouvements : il ne peut plus être supprimé.');
        }
        foreach ($this->catalog->kits() as $kit) {
            if (\in_array($type->getId()->toRfc4122(), array_column($kit->getLines(), 'typeId'), true)) {
                throw new HttpException(409, \sprintf('Ce type de linge fait partie du kit « %s ».', $kit->getName()));
            }
        }
        $this->em->remove($type);
        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/api/linen/kits', name: 'api_linen_kits', methods: ['GET'])]
    public function kits(): JsonResponse
    {
        return $this->json(array_map(static fn (LinenKit $k) => $k->toArray(), $this->catalog->kits()));
    }

    /** JSON {"name", "lines": [{"typeId", "qty"}]}. */
    #[Route('/api/linen/kits', name: 'api_linen_kit_create', methods: ['POST'])]
    #[IsGranted('LINEN_MANAGE')]
    public function createKit(Request $request): JsonResponse
    {
        $body = $request->toArray();
        $kit = new LinenKit($this->text($body['name'] ?? null, 80, 'name'), $this->kitLines($body['lines'] ?? null));
        $this->em->persist($kit);
        $this->em->flush();

        return $this->json($kit->toArray(), 201);
    }

    #[Route('/api/linen/kits/{id}', name: 'api_linen_kit_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function updateKit(#[MapEntity] LinenKit $kit, Request $request): JsonResponse
    {
        $body = $request->toArray();
        if (\array_key_exists('name', $body)) {
            $kit->setName($this->text($body['name'], 80, 'name'));
        }
        if (\array_key_exists('lines', $body)) {
            $kit->setLines($this->kitLines($body['lines']));
        }
        $this->em->flush();

        return $this->json($kit->toArray());
    }

    /** Also removes it from the needs of the places. */
    #[Route('/api/linen/kits/{id}', name: 'api_linen_kit_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function deleteKit(#[MapEntity] LinenKit $kit): JsonResponse
    {
        foreach ($this->em->getRepository(LinenPar::class)->findBy(['kit' => $kit]) as $par) {
            $this->em->remove($par);
        }
        $this->em->remove($kit);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /** Needs of a place: [{"kitId", "kitName", "units", "kitsPerUnit", "parLevel"}]. */
    #[Route('/api/linen/places/{placeId}/needs', name: 'api_linen_needs', methods: ['GET'])]
    public function needs(string $placeId): JsonResponse
    {
        return $this->json(array_map(static fn (LinenPar $p) => $p->toArray(), $this->em->getRepository(LinenPar::class)->findBy(['placeId' => $this->placeParam($placeId)])));
    }

    /** Replaces the needs of a place: [{"kitId", "units": beds/bathrooms using it (1-50), "kitsPerUnit": par level (1-10)}]. */
    #[Route('/api/linen/places/{placeId}/needs', name: 'api_linen_needs_update', methods: ['PUT'])]
    #[IsGranted('LINEN_MANAGE')]
    public function updateNeeds(string $placeId, Request $request): JsonResponse
    {
        $this->places->name($this->placeParam($placeId));
        $body = $request->toArray();
        $list = array_is_list($body) ? $body : (array) ($body['needs'] ?? []);
        $pars = [];
        foreach ($list as $line) {
            $kit = $this->catalog->kit(\is_array($line) ? ($line['kitId'] ?? null) : null);
            $key = $kit->getId()->toRfc4122();
            if (isset($pars[$key])) {
                throw new HttpException(422, 'Un kit ne figure qu’une fois dans les besoins.');
            }
            $pars[$key] = new LinenPar($placeId, $kit, $this->intIn($line['units'] ?? null, 1, 50, 'units'), $this->intIn($line['kitsPerUnit'] ?? 3, 1, 10, 'kitsPerUnit'));
        }
        foreach ($this->em->getRepository(LinenPar::class)->findBy(['placeId' => $placeId]) as $old) {
            $this->em->remove($old);
        }
        $this->em->flush();
        array_walk($pars, fn (LinenPar $p) => $this->em->persist($p));
        $this->em->flush();

        return $this->json(array_values(array_map(static fn (LinenPar $p) => $p->toArray(), $pars)));
    }

    /** @param array<mixed> $body */
    private function applyType(LinenItemType $type, array $body): void
    {
        if (\array_key_exists('name', $body)) {
            $type->setName($this->text($body['name'], 80, 'name'));
        }
        if (\array_key_exists('stockItemId', $body)) {
            $id = $body['stockItemId'];
            $type->setStockItemId(null === $id || '' === $id ? null : (\is_string($id) && Uuid::isValid($id) ? $id : throw new HttpException(422, '« stockItemId » : identifiant d’article Rocket Stock (UUID) attendu.')));
        }
        if (\array_key_exists('weightGrams', $body)) {
            $type->setWeightGrams(null === $body['weightGrams'] ? null : $this->intIn($body['weightGrams'], 1, 20000, 'weightGrams'));
        }
        if (\array_key_exists('position', $body)) {
            $type->setPosition($this->intIn($body['position'], 0, 1000, 'position'));
        }
    }

    /** @return list<array{typeId: string, qty: int}> */
    private function kitLines(mixed $lines): array
    {
        $out = $this->catalog->lines($lines);

        return [] !== $out ? $out : throw new HttpException(422, 'Un kit demande au moins une ligne.');
    }
}
