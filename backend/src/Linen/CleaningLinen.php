<?php

namespace App\Linen;

use App\Linen\Contract\LinenJob;
use App\Linen\Entity\LinenMovement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Linen of a cleaning: kits/pieces removed (in_use → dirty), placed (clean → in_use) and found damaged
 * (in_use → damaged). A report is idempotent by its key: `cleaning:<id>:linen:<key>` (the key is given by the
 * client, e.g. for the offline queue, else the next number); replaying it changes nothing.
 */
final class CleaningLinen
{
    private const ACTIONS = [
        'removed' => [LinenMovement::IN_USE, LinenMovement::DIRTY, 'Linge retiré au ménage'],
        'placed' => [LinenMovement::CLEAN, LinenMovement::IN_USE, 'Linge mis en place au ménage'],
        'damaged' => [LinenMovement::IN_USE, LinenMovement::DAMAGED, 'Linge abîmé constaté au ménage'],
    ];

    public function __construct(
        private readonly LinenCatalog $catalog,
        private readonly LinenLedger $ledger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<mixed> $body {"key"?: string, "removed"?: [{kit|type, qty}], "placed"?: […], "damaged"?: […]}
     *
     * @return array{key: string, applied: int, alreadyRecorded: bool}
     */
    public function record(LinenJob $job, array $body, ?string $actor): array
    {
        $key = $body['key'] ?? null;
        if (null !== $key && (!\is_string($key) && !\is_int($key) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $key))) {
            throw new HttpException(422, '« key » : 1 à 64 caractères parmi A-Z a-z 0-9 _ -.');
        }
        $key = null === $key ? (string) $this->nextNumber($job) : (string) $key;
        $prefix = $this->prefix($job).$key;
        $lines = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            $lines[$action] = $this->catalog->expand($body, $action);
        }
        if ([] === array_merge(...array_values($lines))) {
            throw new HttpException(422, 'Rien à enregistrer (removed, placed, damaged).');
        }
        $applied = 0;
        $already = false;
        foreach ($lines as $action => $perType) {
            [$from, $to, $reason] = self::ACTIONS[$action];
            foreach ($perType as $typeId => $qty) {
                $m = $this->ledger->move($job->placeId, $this->catalog->type($typeId), $from, $to, $qty, $reason, "$prefix:$action:$typeId", 'clean', $job->usage, $actor, true);
                null === $m ? $already = true : ++$applied;
            }
        }

        return ['key' => $key, 'applied' => $applied, 'alreadyRecorded' => $already && 0 === $applied];
    }

    /** @return list<array<string, mixed>> the movements recorded by this cleaning */
    public function movements(LinenJob $job): array
    {
        $list = $this->em->createQuery('SELECT m FROM '.LinenMovement::class.' m WHERE m.externalRef LIKE :prefix ORDER BY m.createdAt ASC, m.id ASC')
            ->setParameter('prefix', addcslashes($this->prefix($job), '%_').'%')->getResult();

        return array_map(static fn (LinenMovement $m) => $m->toArray(), $list);
    }

    /** @return array<string, mixed> kits, types and what this cleaning already recorded */
    public function view(LinenJob $job): array
    {
        return [
            'kits' => array_map(static fn ($k) => $k->toArray(), $this->catalog->kits()),
            'types' => array_map(static fn ($t) => $t->toArray(), $this->catalog->types()),
            'movements' => $this->movements($job),
        ];
    }

    private function prefix(LinenJob $job): string
    {
        return 'cleaning:'.$job->id.':linen:';
    }

    private function nextNumber(LinenJob $job): int
    {
        $max = 0;
        foreach ($this->movements($job) as $m) {
            $n = explode(':', substr((string) $m['externalRef'], \strlen($this->prefix($job))))[0];
            if (ctype_digit($n)) {
                $max = max($max, (int) $n);
            }
        }

        return $max + 1;
    }
}
