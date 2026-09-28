<?php

namespace App\Linen;

use App\Linen\Contract\ArrivalSource;
use App\Linen\Contract\PlaceNames;
use App\Linen\Entity\LinenBatch;
use App\Linen\Entity\LinenCount;
use App\Linen\Entity\LinenMovement;
use App\Linen\Entity\LinenPar;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read side of the module: readiness of the next arrivals, summary of a place's linen by state, alerts.
 *
 * Readiness of an arrival: for each kit the place needs (LinenPar), "required" = units (one kit per bed/bathroom),
 * "needed" = required × rank of the arrival in the window (no wash assumed in between), "available" = whole kits
 * made by the clean linen of the reserve. ready: available ≥ needed + required (a spare round); tight: available ≥
 * needed; missing: less.
 */
final class LinenInsights
{
    public const READY = 'ready';
    public const TIGHT = 'tight';
    public const MISSING = 'missing';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinenLedger $ledger,
        private readonly ArrivalSource $arrivals,
        private readonly PlaceNames $places,
        private readonly LinenCatalog $catalog,
    ) {
    }

    /** @return list<string> ids of the places having linen needs or counts */
    public function placeIds(): array
    {
        $ids = array_column($this->em->createQuery('SELECT DISTINCT p.placeId FROM '.LinenPar::class.' p')->getScalarResult(), 'placeId');
        $ids = array_merge($ids, array_column($this->em->createQuery('SELECT DISTINCT c.placeId FROM '.LinenCount::class.' c')->getScalarResult(), 'placeId'));

        return array_values(array_unique($ids));
    }

    /** @return array<string, string> placeId => name, for known places (unknown ones: "Lieu") */
    public function names(): array
    {
        return array_column($this->places->all(), 'name', 'id');
    }

    /**
     * @return list<array<string, mixed>> one entry per arrival of the place in [$date, $date + $days)
     */
    public function readiness(string $placeId, \DateTimeImmutable $date, int $days = 14): array
    {
        $pars = $this->em->getRepository(LinenPar::class)->findBy(['placeId' => $placeId]);
        $stock = $this->ledger->cleanStock($placeId);
        $out = [];
        foreach ($this->arrivals->arrivals($placeId, $date, $date->modify("+$days days")) as $rank => $arrival) {
            $kits = [];
            $worst = self::READY;
            foreach ($pars as $par) {
                $required = $par->getUnits();
                $needed = $required * ($rank + 1);
                $available = LinenCatalog::kitsFrom($par->getKit(), $stock);
                $status = $available >= $needed + $required ? self::READY : ($available >= $needed ? self::TIGHT : self::MISSING);
                $worst = self::rank($status) > self::rank($worst) ? $status : $worst;
                $kits[] = ['kitId' => $par->getKit()->getId()->toRfc4122(), 'kitName' => $par->getKit()->getName(), 'required' => $required, 'needed' => $needed, 'available' => $available, 'status' => $status];
            }
            $out[] = [
                'from' => $arrival['from']->format(\DATE_ATOM), 'until' => $arrival['until']->format(\DATE_ATOM), 'externalRef' => $arrival['externalRef'],
                'status' => [] === $kits ? self::READY : $worst, 'kits' => $kits,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> counts of a place by state, by location and by type */
    public function summary(string $placeId, string $placeName): array
    {
        $states = array_fill_keys(LinenMovement::STATES, 0);
        $byLocation = array_fill_keys(LinenMovement::LOCATIONS, $states);
        $types = [];
        foreach ($this->catalog->types() as $t) {
            $types[$t->getId()->toRfc4122()] = ['typeId' => $t->getId()->toRfc4122(), 'name' => $t->getName(), 'states' => $states, 'total' => 0];
        }
        foreach ($this->em->getRepository(LinenCount::class)->findBy(['placeId' => $placeId]) as $c) {
            $id = $c->getItemType()->getId()->toRfc4122();
            $states[$c->getState()] += $c->getQty();
            $byLocation[$c->getLocation()][$c->getState()] += $c->getQty();
            $types[$id]['states'][$c->getState()] += $c->getQty();
            $types[$id]['total'] += \in_array($c->getState(), [LinenMovement::LOST], true) ? 0 : $c->getQty();
        }
        $kits = [];
        $stock = $this->ledger->cleanStock($placeId);
        foreach ($this->em->getRepository(LinenPar::class)->findBy(['placeId' => $placeId]) as $par) {
            $kits[] = $par->toArray() + ['cleanKits' => LinenCatalog::kitsFrom($par->getKit(), $stock)];
        }

        return ['placeId' => $placeId, 'placeName' => $placeName, 'states' => $states, 'byLocation' => $byLocation, 'types' => array_values($types), 'kits' => $kits];
    }

    /**
     * Alerts: arrivals of the next 7 days short of clean kits (missing: error, tight: warning), batches overdue at
     * the laundry, linen lost or damaged this month.
     *
     * @return list<array{type: string, level: string, placeId: string, placeName: string, message: string, at: ?string, link: string}>
     */
    public function alerts(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $names = $this->names();
        $alerts = [];
        foreach ($this->placeIds() as $placeId) {
            foreach ($this->readiness($placeId, $now->setTime(0, 0), 7) as $a) {
                if (self::READY === $a['status']) {
                    continue;
                }
                $short = array_values(array_filter($a['kits'], static fn (array $k) => self::READY !== $k['status']));
                $alerts[] = [
                    'type' => 'kits', 'level' => self::MISSING === $a['status'] ? 'error' : 'warning', 'placeId' => $placeId, 'placeName' => $names[$placeId] ?? 'Lieu',
                    'message' => (self::MISSING === $a['status'] ? 'Kits propres insuffisants' : 'Kits propres justes').' pour l’arrivée du '.$a['from'].' : '
                        .implode(', ', array_map(static fn (array $k) => \sprintf('%s %d/%d', $k['kitName'], $k['available'], $k['needed']), $short)),
                    'at' => $a['from'], 'link' => '/linge?place='.$placeId,
                ];
            }
        }
        foreach ($this->em->getRepository(LinenBatch::class)->findBy(['status' => LinenBatch::SENT], ['expectedAt' => 'ASC']) as $b) {
            if ($b->isOverdue($now)) {
                $alerts[] = [
                    'type' => 'batch_overdue', 'level' => 'error', 'placeId' => $b->getPlaceId(), 'placeName' => $b->getPlaceName(),
                    'message' => \sprintf('Lot chez %s attendu le %s, pas encore revenu.', $b->getLaundry()->getName(), $b->getExpectedAt()->format('d/m/Y')),
                    'at' => $b->getExpectedAt()->format(\DATE_ATOM), 'link' => '/linge/blanchisserie',
                ];
            }
        }
        foreach ($this->losses($now->modify('first day of this month')->setTime(0, 0)) as $placeId => $qty) {
            $alerts[] = [
                'type' => 'losses', 'level' => 'warning', 'placeId' => $placeId, 'placeName' => $names[$placeId] ?? 'Lieu',
                'message' => \sprintf('%d pièce(s) perdue(s) ou abîmée(s) ce mois-ci.', $qty), 'at' => null, 'link' => '/linge?place='.$placeId,
            ];
        }

        return $alerts;
    }

    /** @return array<string, int> placeId => pieces that became lost or damaged since $since */
    public function losses(\DateTimeImmutable $since): array
    {
        $rows = $this->em->createQuery('SELECT m.placeId AS placeId, SUM(m.qty) AS qty FROM '.LinenMovement::class.' m WHERE m.toState IN (:states) AND m.createdAt >= :since GROUP BY m.placeId')
            ->setParameter('states', [LinenMovement::LOST, LinenMovement::DAMAGED])->setParameter('since', $since)->getScalarResult();

        return array_map('intval', array_column($rows, 'qty', 'placeId'));
    }

    private static function rank(string $status): int
    {
        return match ($status) { self::MISSING => 2, self::TIGHT => 1, default => 0 };
    }
}
