<?php

namespace App\Cleaning;

use App\Message\NotifyLateCleanings;
use App\Message\SendCleaningSummary;
use Rocket\Core\Scheduler\RecurringTaskProviderInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/** Recurring e-mails of cleanings, run by the worker (`messenger:consume async scheduler_default`) of rocket-core. */
final class CleaningSchedule implements RecurringTaskProviderInterface
{
    public function recurringMessages(): iterable
    {
        yield RecurringMessage::every('1 day', new NotifyLateCleanings(), from: new \DateTimeImmutable('today 08:00'));
        yield RecurringMessage::every('1 day', new SendCleaningSummary(), from: new \DateTimeImmutable('today 20:00'));
    }
}
