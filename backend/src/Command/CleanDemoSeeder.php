<?php

namespace App\Command;

use App\Entity\CleaningChecklistItem;
use App\Entity\CleaningTask;
use App\Entity\Site;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Demo data: two local places (same ids as Rocket Place's demo places), a checklist per place and two cleanings
 * (one today, one late), assigned to the first demo user who is not an administrator. Idempotent. */
final class CleanDemoSeeder implements DemoSeederInterface
{
    public const PORT = '0192f7c4-0000-7000-8000-000000000001';
    public const VIGNES = '0192f7c4-0000-7000-8000-000000000002';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        foreach ([self::PORT => 'Le port', self::VIGNES => 'Les vignes'] as $id => $name) {
            if (null === $this->sites->find($id)) {
                $this->em->persist(new Site($name, Site::LOCAL, $id));
            }
        }
        $checklists = [
            self::PORT => ['Draps et serviettes changés', 'Salle de bain et WC', 'Cuisine et vaisselle', 'Poubelles descendues', 'Machine à café détartrée'],
            self::VIGNES => ['Draps et serviettes changés', 'Salle de bain et WC', 'Terrasse balayée', 'Poubelles descendues'],
        ];
        foreach ($checklists as $placeId => $labels) {
            if ([] === $this->em->getRepository(CleaningChecklistItem::class)->findBy(['placeId' => $placeId])) {
                foreach ($labels as $i => $label) {
                    $this->em->persist(new CleaningChecklistItem($placeId, $label, $i));
                }
            }
        }
        $cleaner = null; // the first demo user who is not an administrator, else anyone
        foreach ($users as $user) {
            if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $cleaner = $user;
                break;
            }
        }
        $cleaner ??= [] === $users ? null : reset($users);
        $cleanings = [
            [self::PORT, 'Le port', 'demo-menage-1', 'Ménage après Sofia Rossi', new \DateTimeImmutable('today 11:00'), new \DateTimeImmutable('today 16:00')],
            [self::VIGNES, 'Les vignes', 'demo-menage-2', 'Ménage de fin de séjour', new \DateTimeImmutable('-1 day 11:00'), new \DateTimeImmutable('-1 day 16:00')],
        ];
        foreach ($cleanings as [$placeId, $placeName, $ref, $label, $at, $due]) {
            if (null === $this->em->getRepository(CleaningTask::class)->findOneBy(['placeId' => $placeId, 'externalRef' => $ref])) {
                $this->em->persist((new CleaningTask($placeId, $placeName, $label, $at, $checklists[$placeId], $ref))->setDueAt($due)->setAssignee($cleaner));
            }
        }
        $this->em->flush();

        $io->text('Rocket Clean : 2 lieux locaux, 2 checklists, 2 ménages.');
    }
}
