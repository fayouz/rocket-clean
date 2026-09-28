<?php

namespace App\Stock;

use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Stock (rocket-apps/rocket-stock), the owner of the stock of the places (same paths as the former
 * stock of Rocket Place: /api/places/{placeId}/stock, PATCH /api/stock-levels/{id}, plus POST /api/movements).
 * ROCKET_STOCK_URL + ROCKET_STOCK_TOKEN (rst_…); suite mode: Rocket Auth token for the audience "rocket-stock",
 * the static token stays the fallback. Not configured: App\Stock\CleaningStock falls back to Rocket Place, then none.
 * Responses streamed and capped, 4xx relayed, token refused / 5xx / unreachable → 502 with a French message.
 */
final class StockClient
{
    private const TIMEOUT = 8;
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const AUDIENCE = 'rocket-stock';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $stockUrl,
        private readonly string $stockToken,
        private readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    /** Whether Rocket Stock is configured (else stock reports go to Rocket Place, or nowhere). */
    public function isConfigured(): bool
    {
        return '' !== trim($this->stockUrl) && ('' !== trim($this->stockToken) || $this->usesSuiteTokens());
    }

    /** Whether calls use tokens of Rocket Auth (suite mode) rather than the static ROCKET_STOCK_TOKEN. */
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
                if ('' === trim($this->stockToken)) {
                    throw new HttpException(502, 'Rocket Auth ne délivre pas de jeton pour Rocket Stock : '.$e->getMessage());
                }
            }
        }

        return $this->stockToken;
    }

    /**
     * JSON call to Rocket Stock. $path starts with /api/.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>      $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        if (!$this->isConfigured()) {
            throw new HttpException(409, 'Rocket Stock n’est pas configuré (ROCKET_STOCK_URL).');
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
            $response = $this->http->request($method, rtrim($this->stockUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new HttpException(502, 'Réponse de Rocket Stock trop volumineuse.');
                }
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Stock ne répond pas ou est injoignable depuis le serveur.');
        }
        if (401 === $status) {
            if ($this->usesSuiteTokens()) {
                $this->serviceTokens->forget(self::AUDIENCE);
                throw new HttpException(502, 'Jeton Rocket Auth refusé par Rocket Stock (client rocket-clean lié à une application ?).');
            }
            throw new HttpException(502, 'Jeton Rocket Stock refusé (ROCKET_STOCK_TOKEN).');
        }
        if (403 === $status) {
            throw new HttpException(502, 'Rocket Stock refuse cette action à l’application Rocket Clean.');
        }
        if ($status >= 400 && $status < 500) {
            throw new HttpException($status, self::message($body) ?? \sprintf('Rocket Stock a répondu avec l’erreur %d.', $status));
        }
        if ($status >= 500) {
            throw new HttpException(502, \sprintf('Rocket Stock a répondu avec l’erreur %d.', $status));
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
            throw new HttpException(502, 'Réponse illisible de Rocket Stock.');
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
