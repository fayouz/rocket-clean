<?php

namespace App\Mailer;

use Symfony\Component\Uid\Uuid;

/**
 * Rocket Mailer used whenever ROCKET_MAILER_URL / ROCKET_MAILER_TOKEN (or suite mode) are not configured: a send is
 * only recorded in var/demo-mailer-<env>.json. Nothing ever leaves the server (keeps tests offline).
 */
final class DemoMailer
{
    public function __construct(private readonly string $demoMailerPath)
    {
    }

    public function reset(): void
    {
        if (is_file($this->demoMailerPath)) {
            unlink($this->demoMailerPath);
        }
    }

    /**
     * @param array<string, mixed> $email
     *
     * @return array<string, mixed>
     */
    public function send(array $email, string $asUser): array
    {
        $sent = $this->sent();
        $record = ['id' => Uuid::v7()->toRfc4122(), 'status' => 'demo', 'as' => $asUser, 'to' => $email['to'] ?? [], 'subject' => (string) ($email['subject'] ?? ''), 'htmlBody' => (string) ($email['htmlBody'] ?? ''), 'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
        $sent[] = $record;
        if (!is_dir(\dirname($this->demoMailerPath))) {
            mkdir(\dirname($this->demoMailerPath), 0o775, true);
        }
        file_put_contents($this->demoMailerPath, json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), \LOCK_EX);

        return $record;
    }

    /** @return list<array<string, mixed>> e-mails "sent" in demo mode */
    public function sent(): array
    {
        $raw = is_file($this->demoMailerPath) ? file_get_contents($this->demoMailerPath) : false;
        $data = false === $raw ? null : json_decode($raw, true);

        return \is_array($data) ? array_values($data) : [];
    }
}
