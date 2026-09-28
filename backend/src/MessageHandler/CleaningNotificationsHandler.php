<?php

namespace App\MessageHandler;

use App\Cleaning\CleaningNotifier;
use App\Cleaning\RecurrenceGenerator;
use App\Message\GenerateRecurringCleanings;
use App\Message\NotifyLateCleanings;
use App\Message\SendCleaningSummary;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CleaningNotificationsHandler
{
    public function __construct(private readonly CleaningNotifier $notifier, private readonly RecurrenceGenerator $recurrences)
    {
    }

    #[AsMessageHandler]
    public function recurrences(GenerateRecurringCleanings $message): void
    {
        $this->recurrences->generate();
    }

    #[AsMessageHandler]
    public function late(NotifyLateCleanings $message): void
    {
        $this->notifier->late();
    }

    #[AsMessageHandler]
    public function summary(SendCleaningSummary $message): void
    {
        $this->notifier->summary();
    }
}
