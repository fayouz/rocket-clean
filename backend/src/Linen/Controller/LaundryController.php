<?php

namespace App\Linen\Controller;

use App\Linen\Contract\PlaceNames;
use App\Linen\Entity\Laundry;
use App\Linen\Entity\LinenBatch;
use App\Linen\LaundryBatches;
use App\Linen\LinenCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Laundry providers (managers) and batches of linen sent to them: sending and counting the return is open to the
 * staff (LINEN_READ); the drop-off e-mail is previewed (GET), then sent only on an explicit confirmed POST (managers).
 */
#[IsGranted('LINEN_READ')]
final class LaundryController extends LinenApiController
{
    public function __construct(
        private readonly LaundryBatches $batches,
        private readonly LinenCatalog $catalog,
        private readonly PlaceNames $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/linen/laundries', name: 'api_linen_laundries', methods: ['GET'])]
    public function laundries(): JsonResponse
    {
        return $this->json(array_map(static fn (Laundry $l) => $l->toArray(), $this->em->getRepository(Laundry::class)->findBy([], ['name' => 'ASC'])));
    }

    /** {"name", "orderEmail"?, "pricing": kg|piece, "pricePerKg"? (cents), "piecePrices"? {typeId: cents}, "turnaroundDays"?, "active"?}. */
    #[Route('/api/linen/laundries', name: 'api_linen_laundry_create', methods: ['POST'])]
    #[IsGranted('LINEN_MANAGE')]
    public function create(Request $request): JsonResponse
    {
        $laundry = new Laundry('');
        $this->apply($laundry, $request->toArray() + ['name' => null]);
        $this->em->persist($laundry);
        $this->em->flush();

        return $this->json($laundry->toArray(), 201);
    }

    #[Route('/api/linen/laundries/{id}', name: 'api_linen_laundry_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function update(#[MapEntity] Laundry $laundry, Request $request): JsonResponse
    {
        $this->apply($laundry, $request->toArray());
        $this->em->flush();

        return $this->json($laundry->toArray());
    }

    /** ?status=sent|returned, ?place=<id>: the last 200 batches, newest first. */
    #[Route('/api/linen/batches', name: 'api_linen_batches', methods: ['GET'])]
    public function batches(Request $request): JsonResponse
    {
        $criteria = [];
        if ('' !== ($status = (string) $request->query->get('status', ''))) {
            $criteria['status'] = $status;
        }
        if ('' !== ($place = (string) $request->query->get('place', ''))) {
            $criteria['placeId'] = $place;
        }
        $now = new \DateTimeImmutable();

        return $this->json(array_map(static fn (LinenBatch $b) => $b->toArray($now), $this->em->getRepository(LinenBatch::class)->findBy($criteria, ['sentAt' => 'DESC'], 200)));
    }

    /** {"laundryId", "placeId", "lines": [{type|kit, qty}], "weightKg"?, "usage"?: rental|personal, "note"?}: dirty → at_laundry. */
    #[Route('/api/linen/batches', name: 'api_linen_batch_create', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $b = $request->toArray();
        $laundry = $this->laundry($b['laundryId'] ?? null);
        $placeId = $this->placeParam((string) ($b['placeId'] ?? ''));
        $batch = $this->batches->send($laundry, $placeId, $this->places->name($placeId), $b, $this->actor());
        $this->em->flush();

        return $this->json($batch->toArray(), 201);
    }

    #[Route('/api/linen/batches/{id}', name: 'api_linen_batch', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity] LinenBatch $batch): JsonResponse
    {
        return $this->json($batch->toArray());
    }

    /** Counted return {"returned": [{type, qty}], "damaged"?: [{type, qty}], "weightKg"?}: the rest is flagged missing (lost). */
    #[Route('/api/linen/batches/{id}/return', name: 'api_linen_batch_return', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function receive(#[MapEntity] LinenBatch $batch, Request $request): JsonResponse
    {
        $this->batches->receive($batch, $request->toArray(), $this->actor());
        $this->em->flush();

        return $this->json($batch->toArray());
    }

    /** Preview of the drop-off e-mail {to, subject, htmlBody, alreadySent, sentAt}: nothing is sent. */
    #[Route('/api/linen/batches/{id}/email', name: 'api_linen_batch_email', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function email(#[MapEntity] LinenBatch $batch): JsonResponse
    {
        return $this->json($this->batches->email($batch));
    }

    /** Sends the drop-off e-mail through Rocket Mailer: {"confirm": true} required; once per batch (idempotent). */
    #[Route('/api/linen/batches/{id}/email', name: 'api_linen_batch_email_send', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function sendEmail(#[MapEntity] LinenBatch $batch, Request $request): JsonResponse
    {
        if (true !== ($request->toArray()['confirm'] ?? null)) {
            throw new HttpException(422, 'Envoi non confirmé : relire l’aperçu puis envoyer {"confirm": true}.');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new HttpException(403, 'L’e-mail part au nom d’un utilisateur connecté.');
        }
        $result = $this->batches->sendEmail($batch, $user->getEmail());
        $this->em->flush();

        return $this->json($result);
    }

    private function laundry(mixed $id): Laundry
    {
        $laundry = \is_string($id) && Uuid::isValid($id) ? $this->em->find(Laundry::class, $id) : null;

        return $laundry ?? throw new HttpException(422, 'Blanchisserie inconnue.');
    }

    /** @param array<mixed> $b */
    private function apply(Laundry $laundry, array $b): void
    {
        if (\array_key_exists('name', $b)) {
            $laundry->setName($this->text($b['name'], 120, 'name'));
        }
        if (\array_key_exists('orderEmail', $b)) {
            $email = null === $b['orderEmail'] ? '' : trim((string) $b['orderEmail']);
            if ('' !== $email && !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(422, 'Adresse de commande invalide.');
            }
            $laundry->setOrderEmail('' === $email ? null : mb_substr($email, 0, 180));
        }
        if (\array_key_exists('pricing', $b)) {
            \in_array($b['pricing'], [Laundry::PER_KG, Laundry::PER_PIECE], true) ? $laundry->setPricing($b['pricing']) : throw new HttpException(422, 'Tarif : kg ou piece.');
        }
        if (\array_key_exists('pricePerKg', $b)) {
            $laundry->setPricePerKg(null === $b['pricePerKg'] ? null : $this->intIn($b['pricePerKg'], 0, 100000, 'pricePerKg'));
        }
        if (\array_key_exists('piecePrices', $b)) {
            $prices = [];
            foreach ((array) $b['piecePrices'] as $typeId => $cents) {
                $prices[$this->catalog->type((string) $typeId)->getId()->toRfc4122()] = $this->intIn($cents, 0, 100000, 'piecePrices');
            }
            $laundry->setPiecePrices($prices);
        }
        if (\array_key_exists('turnaroundDays', $b)) {
            $laundry->setTurnaroundDays($this->intIn($b['turnaroundDays'], 0, 60, 'turnaroundDays'));
        }
        if (\array_key_exists('active', $b)) {
            $laundry->setActive(true === $b['active']);
        }
    }
}
