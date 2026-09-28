<?php

namespace App\Linen;

use App\Linen\Entity\LinenCount;
use App\Linen\Entity\LinenItemType;
use App\Linen\Entity\LinenMovement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only writer of linen counts: each change is a LinenMovement (from state/location → to state/location). A
 * movement whose externalRef is already recorded is skipped (idempotent replays). Strict by default (not enough
 * linen in the "from" state → 422); lenient for field reports (a cleaning, a wash, a laundry return), where what was
 * seen wins: the "from" count stops at 0 and the movement is still recorded.
 */
final class LinenLedger
{
    /** @var array<string, LinenCount> counts created or loaded during this request */
    private array $counts = [];
    /** @var array<string, true> references recorded during this request, before flush */
    private array $refs = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isRecorded(string $externalRef): bool
    {
        return isset($this->refs[$externalRef]) || null !== $this->em->getRepository(LinenMovement::class)->findOneBy(['externalRef' => $externalRef]);
    }

    /** Applies a movement; null when its externalRef was already recorded. */
    public function move(
        string $placeId, LinenItemType $type, ?string $from, ?string $to, int $qty, string $reason, ?string $externalRef,
        string $origin, string $usage, ?string $actor = null, bool $lenient = false, ?string $fromLocation = null, ?string $toLocation = null,
    ): ?LinenMovement {
        if (null !== $externalRef && $this->isRecorded($externalRef)) {
            return null;
        }
        if ($qty < 1) {
            throw new HttpException(422, 'Quantité invalide.');
        }
        if (null === $from && null === $to) {
            throw new HttpException(422, 'Mouvement sans effet.');
        }
        foreach ([$from, $to] as $state) {
            if (null !== $state && !\in_array($state, LinenMovement::STATES, true)) {
                throw new HttpException(422, 'État invalide ('.implode(', ', LinenMovement::STATES).').');
            }
        }
        foreach ([$fromLocation, $toLocation] as $location) {
            if (null !== $location && !\in_array($location, LinenMovement::LOCATIONS, true)) {
                throw new HttpException(422, 'Emplacement invalide ('.implode(', ', LinenMovement::LOCATIONS).').');
            }
        }
        $fromLocation = null === $from ? null : ($fromLocation ?? LinenMovement::defaultLocation($from));
        $toLocation = null === $to ? null : ($toLocation ?? LinenMovement::defaultLocation($to));
        if ($from === $to && $fromLocation === $toLocation) {
            throw new HttpException(422, 'Mouvement sans effet.');
        }
        if (null !== $from) {
            $count = $this->count($placeId, $fromLocation, $type, $from);
            if ($count->getQty() < $qty && !$lenient) {
                throw new HttpException(422, \sprintf('Pas assez de « %s » à l’état %s (%d, %d demandés).', $type->getName(), $from, $count->getQty(), $qty));
            }
            $count->add(-min($qty, max(0, $count->getQty())));
        }
        if (null !== $to) {
            $this->count($placeId, $toLocation, $type, $to)->add($qty);
        }
        $movement = new LinenMovement($placeId, $type, $from, $fromLocation, $to, $toLocation, $qty, mb_substr($reason, 0, 160), $externalRef, $origin, $usage, $actor);
        $this->em->persist($movement);
        if (null !== $externalRef) {
            $this->refs[$externalRef] = true;
        }

        return $movement;
    }

    /**
     * Quantities of a place by state (all locations), per type id.
     *
     * @return array<string, array<string, int>> typeId => state => qty
     */
    public function byType(string $placeId, ?string $location = null): array
    {
        $out = [];
        $criteria = ['placeId' => $placeId] + (null === $location ? [] : ['location' => $location]);
        foreach ($this->em->getRepository(LinenCount::class)->findBy($criteria) as $c) {
            $id = $c->getItemType()->getId()->toRfc4122();
            $out[$id][$c->getState()] = ($out[$id][$c->getState()] ?? 0) + $c->getQty();
        }

        return $out;
    }

    /** @return array<string, int> typeId => clean quantity in the reserve */
    public function cleanStock(string $placeId): array
    {
        return array_map(static fn (array $states) => $states[LinenMovement::CLEAN] ?? 0, $this->byType($placeId, LinenMovement::RESERVE));
    }

    /** Current count (created at 0 if none). */
    public function count(string $placeId, string $location, LinenItemType $type, string $state): LinenCount
    {
        $key = implode('|', [$placeId, $location, $type->getId()->toRfc4122(), $state]);
        if (isset($this->counts[$key]) && $this->em->contains($this->counts[$key])) {
            return $this->counts[$key];
        }
        $count = $this->em->getRepository(LinenCount::class)->findOneBy(['placeId' => $placeId, 'location' => $location, 'itemType' => $type, 'state' => $state]);
        if (null === $count) {
            $this->em->persist($count = new LinenCount($placeId, $location, $type, $state));
        }

        return $this->counts[$key] = $count;
    }
}
