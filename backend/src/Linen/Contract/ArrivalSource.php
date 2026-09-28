<?php

namespace App\Linen\Contract;

/** Port to the stays of a place (occupancy pushed by the PMS/Host: PUT /api/places/{placeId}/occupancy). */
interface ArrivalSource
{
    /** @return list<array{from: \DateTimeImmutable, until: \DateTimeImmutable, externalRef: ?string}> arrivals starting in [$from, $until), sorted */
    public function arrivals(string $placeId, \DateTimeImmutable $from, \DateTimeImmutable $until): array;
}
