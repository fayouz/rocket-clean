<?php

namespace App\Cleaning;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Fixed-window limit of the public cleaning endpoints (/api/public/cleaning/…), per client IP (cache.app, no extra
 * package): 120 requests a minute overall (a checklist is many small calls), and 10 invalid tokens a minute
 * (token guessing), then 429.
 */
final class PublicRateLimiter
{
    private const LIMIT = 120;
    private const FAILURE_LIMIT = 10;

    public function __construct(#[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache)
    {
    }

    public function hit(Request $request): void
    {
        if ($this->count($request, 'all', false) >= self::LIMIT || $this->count($request, 'fail', false) >= self::FAILURE_LIMIT) {
            throw new TooManyRequestsHttpException(60, 'Trop de requêtes, réessaie dans une minute.');
        }
        $this->count($request, 'all', true);
    }

    public function failure(Request $request): void
    {
        $this->count($request, 'fail', true);
    }

    private function count(Request $request, string $kind, bool $increment): int
    {
        $item = $this->cache->getItem('clean_public_'.$kind.'_'.hash('xxh128', (string) $request->getClientIp()).'_'.intdiv(time(), 60));
        $n = (int) ($item->isHit() ? $item->get() : 0);
        if ($increment) {
            $item->set(++$n)->expiresAfter(70);
            $this->cache->save($item);
        }

        return $n;
    }
}
