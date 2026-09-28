<?php

namespace App\Linen\Contract;

/** What the linen module needs to know of a cleaning: never the cleaning entity itself. */
final class LinenJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $placeId,
        public readonly string $placeName,
        /** rental|personal */
        public readonly string $usage,
        public readonly ?string $assigneeId,
    ) {
    }
}
