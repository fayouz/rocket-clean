<?php

namespace App\Linen\Controller;

use App\Linen\Contract\PlaceNames;
use App\Linen\Entity\LinenBatch;
use App\Linen\Entity\LinenCount;
use App\Linen\Entity\LinenMovement;
use App\Linen\LaundryBatches;
use App\Linen\LinenCatalog;
use App\Linen\LinenInsights;
use App\Linen\LinenLedger;
use App\Stock\StockClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Linen of the places: counts by state (summary), movements, inventory, readiness of the next arrivals, alerts,
 * laundry costs by usage and replacement of lost/damaged linen through Rocket Stock. Open to applications acting
 * for themselves (Rocket Host): see CleanScopeGuardListener.
 */
#[IsGranted('LINEN_READ')]
final class LinenController extends LinenApiController
{
    public function __construct(
        private readonly LinenCatalog $catalog,
        private readonly LinenLedger $ledger,
        private readonly LinenInsights $insights,
        private readonly PlaceNames $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** ?place=<id>: one place; else every place having linen. [{placeId, placeName, states, byLocation, types, kits}]. */
    #[Route('/api/linen/summary', name: 'api_linen_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $names = $this->insights->names();
        $ids = $this->placeIds($request);

        return $this->json(array_map(fn (string $id) => $this->insights->summary($id, $names[$id] ?? 'Lieu'), $ids));
    }

    /**
     * ?place=<id> (else every place with needs), ?date=AAAA-MM-JJ (default today), ?days=1-60 (default 14):
     * [{placeId, placeName, arrivals: [{from, until, externalRef, status: ready|tight|missing, kits: [{kitId, kitName, required, needed, available, status}]}]}].
     */
    #[Route('/api/linen/readiness', name: 'api_linen_readiness', methods: ['GET'])]
    public function readiness(Request $request): JsonResponse
    {
        $date = $this->dayParam($request, 'date');
        $days = (int) $request->query->get('days', 14);
        if ($days < 1 || $days > 60) {
            throw new HttpException(422, 'days : entre 1 et 60.');
        }
        $names = $this->insights->names();

        return $this->json(array_map(fn (string $id) => ['placeId' => $id, 'placeName' => $names[$id] ?? 'Lieu', 'arrivals' => $this->insights->readiness($id, $date, $days)], $this->placeIds($request)));
    }

    #[Route('/api/linen/alerts', name: 'api_linen_alerts', methods: ['GET'])]
    public function alerts(): JsonResponse
    {
        return $this->json($this->insights->alerts());
    }

    /** Last movements of a place (?limit, default 100, at most 500). */
    #[Route('/api/linen/places/{placeId}/movements', name: 'api_linen_movements', methods: ['GET'])]
    public function movements(string $placeId, Request $request): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', 100)));
        $list = $this->em->getRepository(LinenMovement::class)->findBy(['placeId' => $this->placeParam($placeId)], ['createdAt' => 'DESC'], $limit);

        return $this->json(array_map(static fn (LinenMovement $m) => $m->toArray(), $list));
    }

    /**
     * A movement by hand or from an application: {"type": uuid, "from"?: state|null, "to"?: state|null, "qty",
     * "reason"?, "externalRef"?, "origin"?: clean|host|linen, "usage"?: rental|personal, "fromLocation"?, "toLocation"?}.
     * 201, or 200 {"alreadyRecorded": true} when the externalRef was already recorded.
     */
    #[Route('/api/linen/places/{placeId}/movements', name: 'api_linen_movement_create', methods: ['POST'])]
    #[IsGranted('LINEN_MANAGE')]
    public function move(string $placeId, Request $request): JsonResponse
    {
        $this->places->name($this->placeParam($placeId));
        $b = $request->toArray();
        $ref = isset($b['externalRef']) && '' !== (string) $b['externalRef'] ? mb_substr((string) $b['externalRef'], 0, 160) : null;
        $m = $this->ledger->move(
            $placeId, $this->catalog->type($b['type'] ?? $b['typeId'] ?? null), $b['from'] ?? null, $b['to'] ?? null, $this->intIn($b['qty'] ?? null, 1, 10000, 'qty'),
            isset($b['reason']) ? mb_substr((string) $b['reason'], 0, 160) : 'Mouvement manuel', $ref, $this->origin($b['origin'] ?? null),
            LaundryBatches::usage($b['usage'] ?? 'rental'), $this->actor(), false, $b['fromLocation'] ?? null, $b['toLocation'] ?? null,
        );
        $this->em->flush();

        return null === $m ? $this->json(['alreadyRecorded' => true]) : $this->json($m->toArray(), 201);
    }

    /**
     * Inventory of a place: [{"type": uuid, "state", "location"?: reserve|logement, "qty": counted}] sets each count
     * to the counted quantity through adjustment movements (entry or exit, reason "Inventaire"). Answers the summary.
     */
    #[Route('/api/linen/places/{placeId}/counts', name: 'api_linen_counts_update', methods: ['PUT'])]
    #[IsGranted('LINEN_MANAGE')]
    public function inventory(string $placeId, Request $request): JsonResponse
    {
        $name = $this->places->name($this->placeParam($placeId));
        $body = $request->toArray();
        $list = array_is_list($body) ? $body : (array) ($body['counts'] ?? []);
        if (\count($list) > 500) {
            throw new HttpException(422, '500 lignes au plus.');
        }
        foreach ($list as $line) {
            $type = $this->catalog->type(\is_array($line) ? ($line['type'] ?? $line['typeId'] ?? null) : null);
            $state = \in_array($line['state'] ?? null, LinenMovement::STATES, true) ? $line['state'] : throw new HttpException(422, 'État invalide ('.implode(', ', LinenMovement::STATES).').');
            $location = $line['location'] ?? LinenMovement::defaultLocation($state);
            if (!\in_array($location, LinenMovement::LOCATIONS, true)) {
                throw new HttpException(422, 'Emplacement invalide (reserve, logement).');
            }
            $delta = $this->intIn($line['qty'] ?? null, 0, 100000, 'qty') - $this->ledger->count($placeId, $location, $type, $state)->getQty();
            if (0 !== $delta) {
                $this->ledger->move($placeId, $type, $delta < 0 ? $state : null, $delta > 0 ? $state : null, abs($delta), 'Inventaire', null, $this->origin(null), 'rental', $this->actor(), false, $delta < 0 ? $location : null, $delta > 0 ? $location : null);
            }
        }
        $this->em->flush();

        return $this->json($this->insights->summary($placeId, $name));
    }

    /**
     * Laundry costs by usage over returned batches: ?from=AAAA-MM-JJ (default first day of the month), ?to (default
     * today, included), ?place. JSON {rows: [...], totals: {rental, personal, total}} (cents), or CSV with ?format=csv.
     */
    #[Route('/api/linen/costs', name: 'api_linen_costs', methods: ['GET'])]
    public function costs(Request $request): Response
    {
        $from = $this->dayParam($request, 'from', 'first day of this month midnight');
        $to = $this->dayParam($request, 'to')->modify('+1 day');
        $qb = $this->em->createQueryBuilder()->select('b')->from(LinenBatch::class, 'b')
            ->where('b.status = :s AND b.returnedAt >= :from AND b.returnedAt < :to')->orderBy('b.returnedAt', 'ASC')
            ->setParameter('s', LinenBatch::RETURNED)->setParameter('from', $from)->setParameter('to', $to);
        if ('' !== ($place = (string) $request->query->get('place', ''))) {
            $qb->andWhere('b.placeId = :place')->setParameter('place', $place);
        }
        $rows = [];
        $totals = ['rental' => 0, 'personal' => 0, 'total' => 0];
        foreach ($qb->getQuery()->getResult() as $b) {
            /** @var LinenBatch $b */
            $cost = $b->getCost() ?? 0;
            $totals[$b->getUsage()] += $cost;
            $totals['total'] += $cost;
            $rows[] = ['batchId' => $b->getId()->toRfc4122(), 'placeId' => $b->getPlaceId(), 'placeName' => $b->getPlaceName(), 'laundry' => $b->getLaundry()->getName(), 'usage' => $b->getUsage(), 'sentAt' => $b->getSentAt()->format(\DATE_ATOM), 'returnedAt' => $b->getReturnedAt()?->format(\DATE_ATOM), 'weightGrams' => $b->getWeightGrams(), 'cost' => $b->getCost()];
        }
        if ('csv' === $request->query->get('format')) {
            $csv = "lot;lieu;blanchisserie;usage;envoi;retour;poids_kg;cout_eur\n";
            foreach ($rows as $r) {
                $csv .= implode(';', [$r['batchId'], str_replace(';', ',', $r['placeName']), str_replace(';', ',', $r['laundry']), $r['usage'], substr($r['sentAt'], 0, 10), substr((string) $r['returnedAt'], 0, 10), null === $r['weightGrams'] ? '' : number_format($r['weightGrams'] / 1000, 1, ',', ''), null === $r['cost'] ? '' : number_format($r['cost'] / 100, 2, ',', '')])."\n";
            }

            return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="linge-couts.csv"']);
        }

        return $this->json(['from' => $from->format('Y-m-d'), 'to' => $to->modify('-1 day')->format('Y-m-d'), 'rows' => $rows, 'totals' => $totals]);
    }

    /**
     * Linen to replace (lost or damaged) per place and type: [{placeId, placeName, typeId, typeName, lost, damaged,
     * stockItemId}], and whether Rocket Stock is configured.
     */
    #[Route('/api/linen/replacements', name: 'api_linen_replacements', methods: ['GET'])]
    public function replacements(Request $request, StockClient $stock): JsonResponse
    {
        $names = $this->insights->names();
        $out = [];
        $qb = $this->em->createQueryBuilder()->select('c')->from(LinenCount::class, 'c')->where('c.state IN (:s) AND c.qty > 0')
            ->setParameter('s', [LinenMovement::LOST, LinenMovement::DAMAGED]);
        if ('' !== ($place = (string) $request->query->get('place', ''))) {
            $qb->andWhere('c.placeId = :place')->setParameter('place', $place);
        }
        foreach ($qb->getQuery()->getResult() as $c) {
            /** @var LinenCount $c */
            $key = $c->getPlaceId().'|'.$c->getItemType()->getId()->toRfc4122();
            $out[$key] ??= ['placeId' => $c->getPlaceId(), 'placeName' => $names[$c->getPlaceId()] ?? 'Lieu', 'typeId' => $c->getItemType()->getId()->toRfc4122(), 'typeName' => $c->getItemType()->getName(), 'lost' => 0, 'damaged' => 0, 'stockItemId' => $c->getItemType()->getStockItemId()];
            $out[$key][$c->getState()] += $c->getQty();
        }

        return $this->json(['stockConfigured' => $stock->isConfigured(), 'items' => array_values($out)]);
    }

    /**
     * Explicit action: records in Rocket Stock the exit of qty of the type's Stock item (POST /api/movements, type
     * "out", externalRef linen:replace:<key>) so that it shows in Stock's next shopping cart, then retires that qty
     * from the lost (then damaged) linen of the place. {"placeId", "type", "qty", "key"?: idempotency key}.
     * 409 when Rocket Stock is not configured, 422 when the type has no Stock item.
     */
    #[Route('/api/linen/replacements', name: 'api_linen_replacement_create', methods: ['POST'])]
    #[IsGranted('LINEN_MANAGE')]
    public function replace(Request $request, StockClient $stock): JsonResponse
    {
        $b = $request->toArray();
        $placeId = $this->placeParam((string) ($b['placeId'] ?? ''));
        $type = $this->catalog->type($b['type'] ?? $b['typeId'] ?? null);
        $qty = $this->intIn($b['qty'] ?? null, 1, 1000, 'qty');
        $key = (string) ($b['key'] ?? Uuid::v4()->toBase58());
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) {
            throw new HttpException(422, '« key » : 1 à 64 caractères parmi A-Z a-z 0-9 _ -.');
        }
        if (null === $type->getStockItemId()) {
            throw new HttpException(422, 'Ce type de linge n’est lié à aucun article de Rocket Stock.');
        }
        if (!$stock->isConfigured()) {
            throw new HttpException(409, 'Rocket Stock n’est pas configuré (ROCKET_STOCK_URL).');
        }
        $ref = "replace:$key";
        if ($this->ledger->isRecorded("$ref:lost") || $this->ledger->isRecorded("$ref:damaged")) {
            return $this->json(['alreadyRecorded' => true]);
        }
        $lost = $this->ledger->count($placeId, LinenMovement::RESERVE, $type, LinenMovement::LOST)->getQty();
        $damaged = $this->ledger->count($placeId, LinenMovement::RESERVE, $type, LinenMovement::DAMAGED)->getQty();
        if ($lost + $damaged < $qty) {
            throw new HttpException(422, \sprintf('Seulement %d pièce(s) perdue(s) ou abîmée(s) à remplacer.', $lost + $damaged));
        }
        $movement = $stock->request('POST', '/api/movements', [
            'item' => $type->getStockItemId(), 'placeId' => $placeId, 'type' => 'out', 'quantity' => $qty,
            'reason' => 'Linge à remplacer : '.$type->getName(), 'externalRef' => "linen:replace:$key", 'origin' => 'clean', 'usage' => 'rental',
        ]);
        $fromLost = min($lost, $qty);
        if ($fromLost > 0) {
            $this->ledger->move($placeId, $type, LinenMovement::LOST, null, $fromLost, 'Remplacé (Rocket Stock)', "$ref:lost", 'linen', 'rental', $this->actor());
        }
        if ($qty - $fromLost > 0) {
            $this->ledger->move($placeId, $type, LinenMovement::DAMAGED, null, $qty - $fromLost, 'Remplacé (Rocket Stock)', "$ref:damaged", 'linen', 'rental', $this->actor());
        }
        $this->em->flush();

        return $this->json(['key' => $key, 'retired' => $qty, 'stockMovement' => $movement], 201);
    }

    /** @return list<string> */
    private function placeIds(Request $request): array
    {
        $place = (string) $request->query->get('place', '');

        return '' === $place ? $this->insights->placeIds() : [$this->placeParam($place)];
    }
}
