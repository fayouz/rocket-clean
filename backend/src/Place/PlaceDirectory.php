<?php

namespace App\Place;

use App\Entity\Site;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * The places cleanings are about, and their stock, whatever their source:
 * - Rocket Place configured (PlaceClient::isConfigured): places and stock levels are Place's (GET /api/places,
 *   /api/stock-levels); each place read is mirrored in a Site of source "place" (name, Rocket Cloud folder).
 * - standalone: local Sites only (created in Rocket Clean), no stock.
 * Callers flush.
 */
final class PlaceDirectory
{
    public function __construct(
        private readonly PlaceClient $place,
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isRemote(): bool
    {
        return $this->place->isConfigured();
    }

    /** @return list<array{id: string, name: string, source: string}> */
    public function all(): array
    {
        if ($this->isRemote()) {
            return array_values(array_map(static fn (array $p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'source' => Site::PLACE],
                array_filter($this->place->request('GET', '/api/places'), static fn ($p) => \is_array($p) && isset($p['id']))));
        }

        return array_map(static fn (Site $s) => $s->toArray(), $this->sites->findBy(['source' => Site::LOCAL], ['name' => 'ASC']));
    }

    /** The Site of a place (a 404 if unknown), refreshed from Rocket Place when configured. */
    public function get(string $placeId): Site
    {
        if (!Uuid::isValid($placeId)) {
            throw new HttpException(404, 'Lieu inconnu.');
        }
        $site = $this->sites->find($placeId);
        if ($this->isRemote()) {
            $data = $this->place->request('GET', '/api/places/'.$placeId);
            $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 160) ?: 'Lieu';
            if (null === $site) {
                $this->em->persist($site = new Site($name, Site::PLACE, $placeId));
            }
            $site->setName($name);

            return $site;
        }
        if (null === $site || !$site->isLocal()) {
            throw new HttpException(404, 'Lieu inconnu.');
        }

        return $site;
    }

    /** @return list<array{id: string, name: string, level: string}> stock levels of a place (none standalone) */
    public function stock(string $placeId): array
    {
        if (!$this->isRemote()) {
            return [];
        }
        $names = [];
        foreach ($this->place->request('GET', '/api/stock-items') as $item) {
            if (\is_array($item) && isset($item['id'])) {
                $names['/api/stock-items/'.$item['id']] = (string) ($item['name'] ?? '?');
            }
        }
        $out = [];
        foreach ($this->place->request('GET', '/api/stock-levels', null, ['place' => '/api/places/'.$placeId]) as $level) {
            if (\is_array($level) && isset($level['id'])) {
                $out[] = ['id' => (string) $level['id'], 'name' => $names[(string) ($level['item'] ?? '')] ?? '?', 'level' => (string) ($level['level'] ?? 'ok')];
            }
        }

        return $out;
    }

    /** Sets a stock level of the place on Rocket Place. @return array{id: string, name: string, level: string} */
    public function setStock(string $placeId, string $levelId, string $level): array
    {
        $current = null;
        foreach ($this->isRemote() && Uuid::isValid($levelId) ? $this->stock($placeId) : [] as $l) {
            if ($l['id'] === $levelId) {
                $current = $l;
            }
        }
        $current ?? throw new HttpException(404, 'Article de stock inconnu pour ce lieu.');
        $this->place->request('PATCH', '/api/stock-levels/'.$levelId, ['level' => $level]);

        return ['id' => $levelId, 'name' => $current['name'], 'level' => $level];
    }
}
