<?php

namespace App\Place;

use App\Secrets\IntegrationSecrets;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Place (rocket-apps/rocket-place), the owner of the places and their stock. Rocket Clean holds one
 * application token (secret rocket.place.token, prefix rpl_) and only keeps the id of each place (CleaningTask::$placeId).
 * Without ROCKET_PLACE_URL (or without any token): not configured, Rocket Clean runs standalone on its local Sites
 * (App\Place\PlaceDirectory), no network call at all.
 *
 * Responses are streamed and capped (MAX_BYTES), errors mapped: 4xx of Place (404, 409, 422...) are passed through
 * with their message, token refused / 5xx / unreachable become a 502 with a clear French message.
 *
 * Suite mode (ROCKET_AUTH_URL + ROCKET_AUTH_CLIENT_SECRET): calls carry an access token of Rocket Auth obtained with
 * the client credentials grant for the audience "rocket-place" (rocket-core ServiceTokenProvider); secret rocket.place.token
 * stays the fallback (standalone mode, or Rocket Auth unreachable).
 */
final class PlaceClient
{
    private const TIMEOUT = 8;
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const AUDIENCE = 'rocket-place';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $placeUrl,
        private readonly IntegrationSecrets $secrets,
        private readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    /** Whether Rocket Place is configured (else Rocket Clean runs standalone on its local sites). */
    public function isConfigured(): bool
    {
        return '' !== trim($this->placeUrl) && ('' !== trim($this->secrets->get('rocket.place.token')) || $this->usesSuiteTokens());
    }

    /** Whether calls use tokens of Rocket Auth (suite mode) rather than the static token rocket.place.token. */
    public function usesSuiteTokens(): bool
    {
        return null !== $this->serviceTokens && $this->serviceTokens->isAvailable();
    }

    /** Bearer of the next call: a token of Rocket Auth in suite mode, else (or if Rocket Auth fails) the static token. */
    private function bearer(): string
    {
        if ($this->usesSuiteTokens()) {
            try {
                return $this->serviceTokens->tokenForClient(self::AUDIENCE);
            } catch (OidcException $e) {
                if ('' === trim($this->secrets->get('rocket.place.token'))) {
                    throw new HttpException(502, 'Rocket Auth ne délivre pas de jeton pour Rocket Place : '.$e->getMessage());
                }
            }
        }

        return $this->secrets->get('rocket.place.token');
    }

    /**
     * JSON call to Rocket Place. $path starts with /api/.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>      $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        if (!$this->isConfigured()) {
            throw new HttpException(409, 'Rocket Place n’est pas configuré (ROCKET_PLACE_URL).');
        }
        $options = ['headers' => ['Accept' => 'application/json']];
        if ([] !== $query) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['body'] = json_encode($json, \JSON_THROW_ON_ERROR);
            $options['headers']['Content-Type'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json';
        }

        return $this->decode($this->send($method, $path, $options, self::MAX_BYTES));
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options, int $maxBytes): string
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer '.$this->bearer()];
        $options['timeout'] = self::TIMEOUT;
        try {
            $response = $this->http->request($method, rtrim($this->placeUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new HttpException(502, 'Réponse de Rocket Place trop volumineuse.');
                }
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Place ne répond pas ou est injoignable depuis le serveur.');
        }
        if (401 === $status) {
            if ($this->usesSuiteTokens()) {
                $this->serviceTokens->forget(self::AUDIENCE);
                throw new HttpException(502, 'Jeton Rocket Auth refusé par Rocket Place (client rocket-clean lié à une application ?).');
            }
            throw new HttpException(502, 'Jeton Rocket Place refusé (secret rocket.place.token).');
        }
        if (403 === $status) {
            throw new HttpException(502, 'Rocket Place refuse cette action à l’application Rocket Clean.');
        }
        if ($status >= 400 && $status < 500) {
            throw new HttpException($status, self::message($body) ?? \sprintf('Rocket Place a répondu avec l’erreur %d.', $status));
        }
        if ($status >= 500) {
            throw new HttpException(502, \sprintf('Rocket Place a répondu avec l’erreur %d.', $status));
        }

        return $body;
    }

    /** @return array<mixed> */
    private function decode(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(502, 'Réponse illisible de Rocket Place.');
        }

        return \is_array($data) ? $data : [];
    }

    private static function message(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            return null;
        }
        $m = $data['detail'] ?? $data['hydra:description'] ?? $data['message'] ?? null;

        return \is_string($m) && '' !== $m ? mb_substr($m, 0, 300) : null;
    }
}
