<?php

namespace App\Linen\Command;

use App\Linen\Entity\Laundry;
use App\Linen\Entity\LinenBatch;
use App\Linen\Entity\LinenItemType;
use App\Linen\Entity\LinenKit;
use App\Linen\Entity\LinenMovement;
use App\Linen\Entity\LinenPar;
use App\Linen\LinenLedger;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Demo linen: 7 types, 2 kits, needs of the two demo places (Le port: 1 double bed, 1 bathroom; Les vignes: 2 double
 * beds, 1 bathroom), counts (inventory), a laundry billed per kg and a batch of Les vignes at the laundry. Idempotent
 * (fixed ids, movements with externalRef demo-linen:*).
 */
final class LinenDemoSeeder implements DemoSeederInterface
{
    private const PORT = '0192f7c4-0000-7000-8000-000000000001';
    private const VIGNES = '0192f7c4-0000-7000-8000-000000000002';
    private const TYPES = [
        'housse' => ['0192f7c4-0000-7000-8000-00000000a001', 'Housse de couette', 1200],
        'drap' => ['0192f7c4-0000-7000-8000-00000000a002', 'Drap housse', 600],
        'taie' => ['0192f7c4-0000-7000-8000-00000000a003', 'Taie d’oreiller', 150],
        'grande' => ['0192f7c4-0000-7000-8000-00000000a004', 'Grande serviette', 600],
        'petite' => ['0192f7c4-0000-7000-8000-00000000a005', 'Petite serviette', 250],
        'tapis' => ['0192f7c4-0000-7000-8000-00000000a006', 'Tapis de bain', 400],
        'torchon' => ['0192f7c4-0000-7000-8000-00000000a007', 'Torchon', 100],
    ];
    private const KIT_LIT = '0192f7c4-0000-7000-8000-00000000b001';
    private const KIT_BAIN = '0192f7c4-0000-7000-8000-00000000b002';
    private const LAUNDRY = '0192f7c4-0000-7000-8000-00000000c001';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinenLedger $ledger,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        $types = [];
        foreach (self::TYPES as $key => [$id, $name, $grams]) {
            $types[$key] = $this->em->find(LinenItemType::class, $id) ?? (new LinenItemType($name, $id))->setWeightGrams($grams)->setPosition(\count($types));
            $this->em->persist($types[$key]);
        }
        $line = static fn (string $k, int $qty) => ['typeId' => self::TYPES[$k][0], 'qty' => $qty];
        $lit = $this->em->find(LinenKit::class, self::KIT_LIT) ?? new LinenKit('Kit lit double', [$line('housse', 1), $line('drap', 1), $line('taie', 2)], self::KIT_LIT);
        $bain = $this->em->find(LinenKit::class, self::KIT_BAIN) ?? new LinenKit('Kit bain (2 pers.)', [$line('grande', 2), $line('petite', 2), $line('tapis', 1)], self::KIT_BAIN);
        $this->em->persist($lit);
        $this->em->persist($bain);
        $this->em->flush();
        foreach ([[self::PORT, $lit, 1], [self::PORT, $bain, 1], [self::VIGNES, $lit, 2], [self::VIGNES, $bain, 1]] as [$placeId, $kit, $units]) {
            if (null === $this->em->getRepository(LinenPar::class)->findOneBy(['placeId' => $placeId, 'kit' => $kit])) {
                $this->em->persist(new LinenPar($placeId, $kit, $units, 3));
            }
        }
        // Inventory: Le port is well stocked; Les vignes is short of clean sheets (a batch is at the laundry).
        $counts = [
            self::PORT => ['clean' => ['housse' => 2, 'drap' => 2, 'taie' => 4, 'grande' => 4, 'petite' => 4, 'tapis' => 2, 'torchon' => 6], 'in_use' => ['housse' => 1, 'drap' => 1, 'taie' => 2, 'grande' => 2, 'petite' => 2, 'tapis' => 1, 'torchon' => 2], 'dirty' => ['torchon' => 3]],
            self::VIGNES => ['clean' => ['housse' => 1, 'drap' => 2, 'taie' => 4, 'grande' => 2, 'petite' => 3, 'tapis' => 1], 'in_use' => ['housse' => 2, 'drap' => 2, 'taie' => 4, 'grande' => 2, 'petite' => 2, 'tapis' => 1], 'dirty' => ['housse' => 2, 'drap' => 2, 'taie' => 4]],
        ];
        foreach ($counts as $placeId => $states) {
            foreach ($states as $state => $qtys) {
                foreach ($qtys as $key => $qty) {
                    $this->ledger->move($placeId, $types[$key], null, $state, $qty, 'Inventaire (démo)', "demo-linen:$placeId:$state:$key", 'linen', 'rental');
                }
            }
        }
        $laundry = $this->em->find(Laundry::class, self::LAUNDRY) ?? (new Laundry('Blanchisserie du Port', self::LAUNDRY))
            ->setOrderEmail('commandes@blanchisserie-du-port.example')->setPricing(Laundry::PER_KG)->setPricePerKg(450)->setTurnaroundDays(3);
        $this->em->persist($laundry);
        $this->em->flush();
        if ([] === $this->em->getRepository(LinenBatch::class)->findBy(['placeId' => self::VIGNES])) {
            $batch = (new LinenBatch($laundry, self::VIGNES, 'Les vignes', [$line('housse', 2), $line('drap', 2), $line('taie', 4), $line('grande', 2)], new \DateTimeImmutable('-2 days 10:00')))
                ->setWeightGrams(6600)->setNote('Draps de la semaine.');
            $this->em->persist($batch);
            foreach ($batch->getSentLines() as $l) {
                $key = array_search($l['typeId'], array_map(static fn (array $t) => $t[0], self::TYPES), true);
                $this->ledger->move(self::VIGNES, $types[$key], null, LinenMovement::AT_LAUNDRY, $l['qty'], 'Envoi à la blanchisserie (démo)', 'batch:'.$batch->getId()->toRfc4122().':sent:'.$l['typeId'], 'linen', 'rental');
            }
        }
        $this->em->flush();

        $io->text('Rocket Clean · Linge : 7 types, 2 kits, besoins de 2 lieux, 1 blanchisserie, 1 lot en cours.');
    }
}
