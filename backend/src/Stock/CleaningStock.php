<?php

namespace App\Stock;

use App\Entity\CleaningTask;
use App\Place\PlaceDirectory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Stock of the place of a cleaning, and the stock reports made during it, whatever the owner of the stock:
 * - Rocket Stock configured (StockClient::isConfigured): levels read from GET /api/places/{placeId}/stock; a report
 *   records a consumption (POST /api/movements, type "consume", usage "rental" for a rental cleaning else "personal",
 *   externalRef "cleaning:<id>:<levelId>" so it is never counted twice, origin "clean") then sets the level's state
 *   (PATCH /api/stock-levels/{id} {"level"}).
 * - else Rocket Place configured: its stock levels (PlaceDirectory), state only.
 * - else no stock.
 */
final class CleaningStock
{
    public function __construct(
        private readonly StockClient $stock,
        private readonly PlaceDirectory $places,
    ) {
    }

    /** @return list<array{id: string, name: string, level: string}> */
    public function levels(string $placeId): array
    {
        if (!$this->stock->isConfigured()) {
            return $this->places->stock($placeId);
        }

        return array_map(static fn (array $l) => ['id' => (string) $l['id'], 'name' => (string) ($l['name'] ?? $l['itemName'] ?? '?'), 'level' => (string) ($l['level'] ?? 'ok')], $this->raw($placeId));
    }

    /**
     * Report of a stock level during a cleaning.
     *
     * @param float|null $quantity consumed quantity (default 1)
     *
     * @return array{id: string, name: string, level: string}
     */
    public function report(CleaningTask $task, string $levelId, string $level, ?float $quantity = null): array
    {
        $placeId = $task->getPlaceId();
        if (!$this->stock->isConfigured()) {
            return $this->places->setStock($placeId, $levelId, $level);
        }
        $current = null;
        foreach (Uuid::isValid($levelId) ? $this->raw($placeId) : [] as $l) {
            if ((string) $l['id'] === $levelId) {
                $current = $l;
            }
        }
        $current ?? throw new HttpException(404, 'Article de stock inconnu pour ce lieu.');
        $movement = [
            'item' => $current['item'] ?? null, 'placeId' => $placeId, 'type' => 'consume',
            'quantity' => null !== $quantity && $quantity > 0 ? $quantity : 1,
            'usage' => CleaningTask::RENTAL === $task->getType() ? 'rental' : 'personal',
            'externalRef' => 'cleaning:'.$task->getId()->toRfc4122().':'.$levelId, 'origin' => 'clean',
            'reason' => mb_substr('Ménage · '.$task->getLabel(), 0, 255),
        ];
        if (\is_string($current['location']['name'] ?? null) && '' !== $current['location']['name']) {
            $movement['location'] = $current['location']['name'];
        }
        $this->stock->request('POST', '/api/movements', $movement);
        $this->stock->request('PATCH', '/api/stock-levels/'.$levelId, ['level' => $level]);

        return ['id' => $levelId, 'name' => (string) ($current['name'] ?? $current['itemName'] ?? '?'), 'level' => $level];
    }

    /** @return list<array<string, mixed>> */
    private function raw(string $placeId): array
    {
        return array_values(array_filter($this->stock->request('GET', '/api/places/'.$placeId.'/stock'), static fn ($l) => \is_array($l) && isset($l['id'])));
    }
}
