<?php

namespace App\Dashboard;

use App\Entity\CleaningTask;
use App\Place\PlaceDirectory;
use App\Repository\CleaningTaskRepository;
use Rocket\Core\Dashboard\DashboardSectionInterface;
use Rocket\Core\Entity\User;

/** Cleanings on the dashboard: of the day, late, in conflict with a stay, done today, and the list of today's and late cleanings. */
final class CleaningSection implements DashboardSectionInterface
{
    public function __construct(
        private readonly CleaningTaskRepository $cleanings,
        private readonly PlaceDirectory $places,
    ) {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $now = new \DateTimeImmutable();
        $today = new \DateTimeImmutable('today');
        $cleanings = array_values(array_filter(
            $this->cleanings->search($today, $today->modify('+1 day'), null, $admin ? null : $user, true),
            static fn (CleaningTask $t) => CleaningTask::CANCELLED !== $t->getStatus(),
        ));
        $late = array_values(array_filter($cleanings, static fn (CleaningTask $t) => $t->isLate($now)));
        $cleaningsToday = \count($cleanings) - \count(array_filter($late, static fn (CleaningTask $t) => $t->getScheduledAt() < $today));
        $done = \count(array_filter($cleanings, static fn (CleaningTask $t) => CleaningTask::DONE === $t->getStatus()));
        $kpis = [
            ['id' => 'cleanings_today', 'label' => 'Ménages du jour', 'value' => $cleaningsToday, 'format' => 'number', 'icon' => 'i-lucide-sparkles', 'tone' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'],
            ['id' => 'cleanings_done', 'label' => 'Terminés', 'value' => $done, 'format' => 'number', 'icon' => 'i-lucide-check', 'tone' => 'bg-primary/10 text-primary'],
            ['id' => 'cleanings_conflict', 'label' => 'En conflit avec un séjour', 'value' => \count(array_filter($cleanings, static fn (CleaningTask $t) => $t->hasConflict())), 'format' => 'number', 'icon' => 'i-lucide-calendar-x', 'tone' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400'],
            ['id' => 'cleanings_late', 'label' => 'Ménages en retard', 'value' => \count($late), 'format' => 'number', 'icon' => 'i-lucide-alarm-clock', 'tone' => 'bg-red-500/10 text-red-600 dark:text-red-400'],
        ];
        if (!$this->places->isRemote()) {
            $kpis[] = ['id' => 'places', 'label' => 'Lieux', 'value' => \count($this->places->all()), 'format' => 'number', 'icon' => 'i-lucide-map-pin', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'];
        }

        return [
            'kpis' => $kpis,
            'series' => [],
            'daily' => [],
            'recent' => [
                'title' => 'Ménages du jour et en retard',
                'link' => '/menage',
                'empty' => 'Aucun ménage aujourd’hui.',
                'items' => array_map(static fn (CleaningTask $t) => [
                    'id' => $t->getId()->toRfc4122(),
                    'title' => $t->getPlaceName(),
                    'subtitle' => $t->getLabel().(null !== $t->getAssignee() ? ' · '.$t->getAssignee()->getDisplayName() : ' · non attribué'),
                    'at' => $t->getScheduledAt()->format(\DATE_ATOM),
                    'badge' => $t->hasConflict() ? 'Conflit' : ($t->isLate($now) ? 'En retard' : match ($t->getStatus()) { CleaningTask::DONE => 'Fait', CleaningTask::IN_PROGRESS => 'En cours', default => 'À faire' }),
                    'badgeColor' => $t->hasConflict() ? 'warning' : ($t->isLate($now) ? 'error' : match ($t->getStatus()) { CleaningTask::DONE => 'success', CleaningTask::IN_PROGRESS => 'info', default => 'neutral' }),
                    'link' => '/menage',
                ], \array_slice($cleanings, 0, 8)),
            ],
            'quickActions' => [
                ['label' => 'Ménage', 'icon' => 'i-lucide-sparkles', 'to' => '/menage', 'tone' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'],
                ['label' => 'Lieux', 'icon' => 'i-lucide-map-pin', 'to' => '/places', 'tone' => 'bg-primary/10 text-primary'],
            ],
        ];
    }
}
