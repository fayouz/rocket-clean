<?php

namespace App\MessageHandler;

use App\Cleaning\CleaningNotifier;
use App\Message\NotifyLateCleanings;
use App\Message\SendCleaningSummary;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CleaningNotificationsHandler
{
    public function __construct(private readonly CleaningNotifier $notifier)
    {
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
