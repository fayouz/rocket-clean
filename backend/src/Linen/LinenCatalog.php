<?php

namespace App\Linen;

use App\Linen\Entity\LinenItemType;
use App\Linen\Entity\LinenKit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/** Types of linen and kits: lookup, and lines {kit|type, qty} expanded into quantities per type. */
final class LinenCatalog
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<LinenItemType> */
    public function types(): array
    {
        return $this->em->getRepository(LinenItemType::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return list<LinenKit> */
    public function kits(): array
    {
        return $this->em->getRepository(LinenKit::class)->findBy([], ['name' => 'ASC']);
    }

    public function type(mixed $id): LinenItemType
    {
        $type = \is_string($id) && Uuid::isValid($id) ? $this->em->find(LinenItemType::class, $id) : null;

        return $type ?? throw new HttpException(422, 'Type de linge inconnu.');
    }

    public function kit(mixed $id): LinenKit
    {
        $kit = \is_string($id) && Uuid::isValid($id) ? $this->em->find(LinenKit::class, $id) : null;

        return $kit ?? throw new HttpException(422, 'Kit inconnu.');
    }

    /**
     * Validated composition lines [{"typeId"|"type": uuid, "qty": int ≥ 1}] (a type once, quantities summed).
     *
     * @return list<array{typeId: string, qty: int}>
     */
    public function lines(mixed $lines): array
    {
        $out = [];
        foreach ($this->expand(['lines' => $lines], 'lines', false) as $typeId => $qty) {
            $out[] = ['typeId' => $typeId, 'qty' => $qty];
        }

        return $out;
    }

    /**
     * Expands $body[$key], a list of {"kit": uuid, "qty"} (a kit counts for its composition) or {"type"|"typeId": uuid, "qty"},
     * into quantities per type id.
     *
     * @param array<mixed> $body
     *
     * @return array<string, int>
     */
    public function expand(array $body, string $key, bool $kits = true): array
    {
        $list = $body[$key] ?? [];
        if (!\is_array($list) || !array_is_list($list) || \count($list) > 100) {
            throw new HttpException(422, "« $key » attend une liste (100 lignes au plus).");
        }
        $out = [];
        foreach ($list as $line) {
            $qty = \is_array($line) ? ($line['qty'] ?? 1) : null;
            if (!\is_int($qty) || $qty < 1 || $qty > 10000) {
                throw new HttpException(422, "« $key » : chaque ligne demande une quantité entière entre 1 et 10000.");
            }
            if ($kits && isset($line['kit'])) {
                foreach ($this->kit($line['kit'])->getLines() as $l) {
                    $out[$l['typeId']] = ($out[$l['typeId']] ?? 0) + $l['qty'] * $qty;
                }
                continue;
            }
            $type = $this->type($line['type'] ?? $line['typeId'] ?? null);
            $id = $type->getId()->toRfc4122();
            $out[$id] = ($out[$id] ?? 0) + $qty;
        }

        return $out;
    }

    /**
     * Number of whole kits a stock per type makes (the scarcest line decides).
     *
     * @param array<string, int> $stock
     */
    public static function kitsFrom(LinenKit $kit, array $stock): int
    {
        $n = null;
        foreach ($kit->getLines() as $l) {
            if ($l['qty'] > 0) {
                $can = intdiv(max(0, $stock[$l['typeId']] ?? 0), $l['qty']);
                $n = null === $n ? $can : min($n, $can);
            }
        }

        return $n ?? 0;
    }
}
