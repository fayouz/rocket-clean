<?php

namespace App\Linen\Contract;

/** Port to the place directory: names of places (the linen module owns no place). */
interface PlaceNames
{
    /** @return list<array{id: string, name: string}> */
    public function all(): array;

    /** The name of a place; a 404 HttpException if unknown. */
    public function name(string $placeId): string;
}
