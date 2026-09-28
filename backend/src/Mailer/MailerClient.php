<?php

namespace App\Mailer;

use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Mailer (rocket-middleware/rocket-mailer), the mail system of the Rocket suite: Clean never speaks
 * SMTP itself. POST /api/emails, on behalf of a user known to Rocket Mailer (X-Impersonate-User, the application
 * must be allowed to impersonate), with the application token ROCKET_MAILER_TOKEN (rma_…) or, in suite mode
 * (ROCKET_AUTH_URL + ROCKET_AUTH_CLIENT_SECRET), a token of Rocket Auth for the audience "rocket-mailer" (client
 * credentials, rocket-core ServiceTokenProvider), the static token staying the fallback.
 * ROCKET_MAILER_MAILBOX: optional sending mailbox. Without ROCKET_MAILER_URL/TOKEN: DemoMailer (no network).
 */
final class MailerClient
{
    private const TIMEOUT = 8;
    private const AUDIENCE = 'rocket-mailer';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DemoMailer $demo,
        private readonly string $mailerUrl,
        private readonly string $mailerToken,
        private readonly string $mailerMailbox,
        private readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    public function isDemo(): bool
    {
        return '' === trim($this->mailerUrl) || ('' === trim($this->mailerToken) && !$this->usesSuiteTokens());
    }

    public function usesSuiteTokens(): bool
    {
        return null !== $this->serviceTokens && $this->serviceTokens->isAvailable();
    }

    /**
     * Queues an e-mail in Rocket Mailer.
     *
     * @param array{to: list<string>, subject: string, htmlBody: string} $email
     *
     * @return array<string, mixed> the queued e-mail (id, status…)
     */
    public function send(string $asUser, array $email): array
    {
        if ($this->isDemo()) {
            return $this->demo->send($email, $asUser);
        }
        if ('' !== trim($this->mailerMailbox)) {
            $email['mailbox'] = '/api/mailboxes/'.rawurlencode(trim($this->mailerMailbox));
        }
        try {
            $response = $this->http->request('POST', rtrim($this->mailerUrl, '/').'/api/emails', [
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->bearer(), 'X-Impersonate-User' => $asUser],
                'json' => $email,
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Mailer ne répond pas ou est injoignable depuis le serveur.');
        }
        if (401 === $status && $this->usesSuiteTokens()) {
            $this->serviceTokens->forget(self::AUDIENCE);
        }
        if ($status >= 400) {
            throw new HttpException(502, \sprintf('Rocket Mailer a répondu avec l’erreur %d.', $status));
        }
        $data = json_decode($body, true);

        return \is_array($data) ? $data : [];
    }

    private function bearer(): string
    {
        if ($this->usesSuiteTokens()) {
            try {
                return $this->serviceTokens->tokenForClient(self::AUDIENCE);
            } catch (OidcException $e) {
                if ('' === trim($this->mailerToken)) {
                    throw new HttpException(502, 'Rocket Auth ne délivre pas de jeton pour Rocket Mailer : '.$e->getMessage());
                }
            }
        }

        return $this->mailerToken;
    }
}
