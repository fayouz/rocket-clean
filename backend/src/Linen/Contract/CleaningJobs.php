<?php

namespace App\Linen\Contract;

/** Port to the cleanings (implemented by the cleaning side, App\Cleaning\LinenBridge): the only coupling of the module. */
interface CleaningJobs
{
    public function find(string $cleaningId): ?LinenJob;
}
