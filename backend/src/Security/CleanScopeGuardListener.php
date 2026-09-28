<?php

namespace App\Security;

use Rocket\Core\Security\ApplicationUser;
use Rocket\Core\Security\ScopeGuardListener;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * rocket-core only lets an application that does not impersonate anyone call GET /api/me. Clean opens its own
 * business endpoints (places, cleanings, checklists, stock, occupancy, costs, recurrences, cost export) to such applications, so a PMS can drive
 * cleanings server-to-server; every other endpoint (users, applications, settings, assignees…) stays guarded by
 * rocket-core's listener.
 */
#[AsDecorator(ScopeGuardListener::class)]
final class CleanScopeGuardListener
{
    private const APPLICATION_PATTERN = '#^/api/(places(/[^/]+(/(cleanings|cleaning-checklist|stock|occupancy|cleaning-costs|recurrences))?)?|cleanings(/[^/]+(/(photos(/[^/]+)?|stock|link))?)?|recurrences/[^/]+)$#';

    public function __construct(
        #[AutowireDecorated] private readonly ScopeGuardListener $inner,
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->security->getUser() instanceof ApplicationUser
            && preg_match(self::APPLICATION_PATTERN, $event->getRequest()->getPathInfo())) {
            return;
        }

        ($this->inner)($event);
    }
}
