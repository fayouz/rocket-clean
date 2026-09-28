<?php

namespace App\Cleaning;

use App\Entity\CleaningTask;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Secret link of one cleaning ("/m/<token>"), for a cleaner without an account: base64url(cleaning uuid (16 bytes) .
 * HMAC-SHA256 truncated to 16 bytes (128 bits)), keyed by the application secret and the cleaning's own link salt.
 * Nothing else is stored: regenerating the salt revokes the previous link, clearing it revokes the link altogether.
 * Valid until the end of the day after the cleaning's due date (or its scheduled day).
 */
final class CleaningLinkSigner
{
    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    public function sign(CleaningTask $task): ?string
    {
        if (null === $task->getLinkSalt()) {
            return null;
        }
        $payload = $task->getId()->toBinary();

        return self::b64($payload.$this->mac($task, $payload));
    }

    /** Cleaning id (RFC 4122) of a well-formed token, unverified: call ::verify with the cleaning. */
    public static function parse(string $token): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);

        return false === $raw || 32 !== \strlen($raw) ? null : Uuid::fromBinary(substr($raw, 0, 16))->toRfc4122();
    }

    public function verify(CleaningTask $task, string $token, \DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        if (null === $task->getLinkSalt() || $now > self::expiresAt($task)) {
            return false;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);

        return false !== $raw && 32 === \strlen($raw) && hash_equals($this->mac($task, substr($raw, 0, 16)), substr($raw, 16));
    }

    public static function expiresAt(CleaningTask $task): \DateTimeImmutable
    {
        return ($task->getDueAt() ?? $task->getScheduledAt())->modify('+1 day')->setTime(23, 59, 59);
    }

    private function mac(CleaningTask $task, string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->secret.'|cleaning-link|'.$task->getLinkSalt(), true), 0, 16);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
