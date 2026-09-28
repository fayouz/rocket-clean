<?php

namespace App\Linen\Dashboard;

use App\Linen\Entity\LinenBatch;
use App\Linen\LinenInsights;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Dashboard\DashboardSectionInterface;
use Rocket\Core\Entity\User;

/** Linen on the dashboard: arrivals short of clean kits, batches at the laundry (and overdue), losses this month. */
final class LinenSection implements DashboardSectionInterface
{
    public function __construct(
        private readonly LinenInsights $insights,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $alerts = $this->insights->alerts();
        $count = static fn (string $type) => \count(array_filter($alerts, static fn (array $a) => $type === $a['type']));
        $atLaundry = \count($this->em->getRepository(LinenBatch::class)->findBy(['status' => LinenBatch::SENT]));
        $losses = array_sum($this->insights->losses(new \DateTimeImmutable('first day of this month midnight')));

        return [
            'kpis' => [
                ['id' => 'linen_kits_short', 'label' => 'Arrivées à court de linge', 'value' => $count('kits'), 'format' => 'number', 'icon' => 'i-lucide-bed-double', 'tone' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400'],
                ['id' => 'linen_batches', 'label' => 'Lots en blanchisserie', 'value' => $atLaundry, 'format' => 'number', 'icon' => 'i-lucide-truck', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400', 'detail' => $count('batch_overdue') > 0 ? $count('batch_overdue').' en retard' : null],
                ['id' => 'linen_losses', 'label' => 'Linge perdu ou abîmé (mois)', 'value' => $losses, 'format' => 'number', 'icon' => 'i-lucide-shirt', 'tone' => 'bg-red-500/10 text-red-600 dark:text-red-400'],
            ],
            'quickActions' => [
                ['label' => 'Linge', 'icon' => 'i-lucide-shirt', 'to' => '/linge', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
            ],
        ];
    }
}
