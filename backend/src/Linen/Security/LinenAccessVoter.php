<?php

namespace App\Linen\Security;

use Rocket\Core\Security\ApplicationUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions of the linen module (same rules as Clean's, kept here so the module can leave with them):
 * - LINEN_READ: any signed-in user, or an application acting on its own behalf;
 * - LINEN_MANAGE: an administrator, or an application acting on its own behalf (e.g. Rocket Host).
 *
 * @extends Voter<string, mixed>
 */
final class LinenAccessVoter extends Voter
{
    public const READ = 'LINEN_READ';
    public const MANAGE = 'LINEN_MANAGE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::READ === $attribute || self::MANAGE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($token->getUser() instanceof ApplicationUser) {
            return true;
        }

        return $this->security->isGranted(self::READ === $attribute ? 'ROLE_USER' : 'ROLE_ADMIN');
    }
}
