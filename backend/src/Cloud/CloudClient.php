<?php

namespace App\Cloud;

use App\Secrets\IntegrationSecrets;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Cloud (rocket-middleware/rocket-cloud), used to store the cleaning photos of each place. Rocket Clean holds a
 * single application token (secret rocket.cloud.token, prefix rca_) and every place gets its own folder (created lazily,
 * id kept in Site::$cloudFolderId) under a app root folder (created lazily too, one per app instance).
 * The browser never talks to Rocket Cloud directly: this app's controllers proxy every call, scoped to the
 * place's folder, so a PMS user never needs a Cloud account or token. Without ROCKET_CLOUD_URL + secret rocket.cloud.token: DemoCloud
 * (no network call at all, keeps functional tests offline as the other integrations do).
 *
 * Suite mode (ROCKET_AUTH_URL + ROCKET_AUTH_CLIENT_SECRET): the calls carry an access token of Rocket Auth obtained
 * with the client credentials grant for the audience "rocket-cloud" (rocket-core ServiceTokenProvider) instead of
 * secret rocket.cloud.token, which stays the fallback (standalone mode, or Rocket Auth unreachable).
 */
final class CloudClient
{
    private const TIMEOUT = 8;
    private const ROOT_FOLDER_NAME = 'Rocket Clean';
    private const AUDIENCE = 'rocket-cloud';

    private ?string $rootFolderId = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DemoCloud $demo,
        private readonly string $cloudUrl,
        private readonly IntegrationSecrets $secrets,
        private readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    public function isDemo(): bool
    {
        return '' === $this->cloudUrl || ('' === $this->secrets->get('rocket.cloud.token') && !$this->usesSuiteTokens());
    }

    /** Whether calls use tokens of Rocket Auth (suite mode) rather than the static token rocket.cloud.token. */
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
                if ('' === $this->secrets->get('rocket.cloud.token')) {
                    throw new HttpException(502, 'Rocket Auth ne délivre pas de jeton pour Rocket Cloud : '.$e->getMessage());
                }
            }
        }

        return $this->secrets->get('rocket.cloud.token');
    }

    /** Folder of a place, created (and its parent app root folder, if missing) on first use. */
    public function ensureFolder(string $placeId, string $existingFolderId, string $placeName): string
    {
        if ('' !== $existingFolderId) {
            return $existingFolderId;
        }
        if ($this->isDemo()) {
            return $this->demo->ensureFolder($placeId, $placeName);
        }
        $root = $this->rootFolder();
        $folder = $this->request('POST', '/api/folders', ['name' => $placeName, 'parent' => "/api/folders/{$root}"]);

        return (string) $folder['id'];
    }

    /** @return list<array{id: string, kind: string, name: string, size: ?int, updatedAt: string}> */
    public function list(string $folderId): array
    {
        if ($this->isDemo()) {
            return $this->demo->list($folderId);
        }
        $folders = $this->request('GET', '/api/folders', null, ['parent' => $folderId, 'itemsPerPage' => 200]);
        $files = $this->request('GET', '/api/files', null, ['folder' => $folderId, 'itemsPerPage' => 200]);
        $items = [];
        foreach ($folders as $f) {
            $items[] = ['id' => (string) $f['id'], 'kind' => 'folder', 'name' => (string) $f['name'], 'size' => null, 'updatedAt' => (string) ($f['updatedAt'] ?? $f['createdAt'])];
        }
        foreach ($files as $f) {
            $items[] = ['id' => (string) $f['id'], 'kind' => 'file', 'name' => (string) $f['name'], 'size' => (int) ($f['size'] ?? 0), 'updatedAt' => (string) ($f['updatedAt'] ?? $f['createdAt'])];
        }

        return $items;
    }

    /** @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string} */
    public function createFolder(string $parentId, string $name): array
    {
        if ($this->isDemo()) {
            return $this->demo->createFolder($parentId, $name);
        }
        $folder = $this->request('POST', '/api/folders', ['name' => $name, 'parent' => "/api/folders/{$parentId}"]);

        return ['id' => (string) $folder['id'], 'kind' => 'folder', 'name' => (string) $folder['name'], 'size' => null, 'updatedAt' => (string) ($folder['updatedAt'] ?? $folder['createdAt'])];
    }

    /** @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string} */
    public function upload(string $folderId, UploadedFile $file): array
    {
        if ($this->isDemo()) {
            return $this->demo->upload($folderId, $file->getClientOriginalName(), $file->getSize() ?: 0);
        }
        $body = $this->request('POST', '/api/files', null, null, [
            'folder' => $folderId,
            'file' => fopen($file->getPathname(), 'r'),
        ], $file->getClientOriginalName());

        return ['id' => (string) $body['id'], 'kind' => 'file', 'name' => (string) $body['name'], 'size' => (int) ($body['size'] ?? 0), 'updatedAt' => (string) ($body['updatedAt'] ?? $body['createdAt'])];
    }

    public function remove(string $folderId, string $itemId, string $kind): void
    {
        if ($this->isDemo()) {
            $this->demo->remove($folderId, $itemId);

            return;
        }
        $this->request('DELETE', \sprintf('/api/%s/%s', 'file' === $kind ? 'files' : 'folders', $itemId));
    }

    public function rename(string $itemId, string $kind, string $name): void
    {
        if ($this->isDemo()) {
            $this->demo->rename($itemId, $name);

            return;
        }
        $this->request('PATCH', \sprintf('/api/%s/%s', 'file' === $kind ? 'files' : 'folders', $itemId), ['name' => $name]);
    }

    public function move(string $itemId, string $kind, ?string $targetFolderId): void
    {
        if ($this->isDemo()) {
            $this->demo->move($itemId, $targetFolderId);

            return;
        }
        $field = 'file' === $kind ? 'folder' : 'parent';
        $this->request('PATCH', \sprintf('/api/%s/%s', 'file' === $kind ? 'files' : 'folders', $itemId), [$field => null === $targetFolderId ? null : "/api/folders/{$targetFolderId}"]);
    }

    /**
     * Whether a folder or file is inside the subtree rooted at $rootFolderId (a place's own folder), directly or
     * through any number of parent folders. Used by the controller to reject any id belonging to another place
     * (or outside Rocket PMS's own folders altogether) with a 404, since Cloud item ids are otherwise opaque to it.
     * Any failure to resolve the item (not found, Cloud unreachable, ...) is treated as "does not belong" (fail closed).
     */
    public function belongsToPlace(string $itemId, string $kind, string $rootFolderId): bool
    {
        if ($this->isDemo()) {
            return $this->demo->belongsToPlace($itemId, $kind, $rootFolderId);
        }
        try {
            if ('file' === $kind) {
                $file = $this->request('GET', "/api/files/{$itemId}");
                $folderIri = (string) ($file['folder'] ?? '');
                if ('' === $folderIri) {
                    return false;
                }
                $folder = $this->request('GET', '/api/folders/'.$this->idFromIri($folderIri));
            } else {
                $folder = $this->request('GET', "/api/folders/{$itemId}");
            }
        } catch (\Throwable) {
            return false;
        }
        foreach ((array) ($folder['path'] ?? []) as $node) {
            if (\is_array($node) && ($node['id'] ?? null) === $rootFolderId) {
                return true;
            }
        }

        return false;
    }

    private function idFromIri(string $iriOrId): string
    {
        $pos = strrpos($iriOrId, '/');

        return false === $pos ? $iriOrId : substr($iriOrId, $pos + 1);
    }

    /** Byte content of a file, streamed back by the PMS controller (the browser never sees the Cloud token). */
    public function content(string $fileId): string
    {
        if ($this->isDemo()) {
            return "Document de démonstration.\n";
        }
        try {
            $response = $this->http->request('GET', rtrim($this->cloudUrl, '/')."/api/files/{$fileId}/content", [
                'headers' => ['Authorization' => 'Bearer '.$this->bearer()],
                'timeout' => self::TIMEOUT,
            ]);

            return $response->getContent(false);
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Cloud ne répond pas ou est injoignable depuis le serveur.');
        }
    }

    private function rootFolder(): string
    {
        if (null !== $this->rootFolderId) {
            return $this->rootFolderId;
        }
        $existing = $this->request('GET', '/api/folders', null, ['name' => self::ROOT_FOLDER_NAME, 'exists[parent]' => 'false', 'itemsPerPage' => 1]);
        if ([] !== $existing) {
            return $this->rootFolderId = (string) $existing[0]['id'];
        }
        $created = $this->request('POST', '/api/folders', ['name' => self::ROOT_FOLDER_NAME, 'parent' => null]);

        return $this->rootFolderId = (string) $created['id'];
    }

    /**
     * @param array<string, mixed>|null   $json
     * @param array<string, mixed>|null   $query
     * @param array<string, mixed>|null   $multipart
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $json = null, ?array $query = null, ?array $multipart = null, ?string $fileName = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->bearer(), 'Accept' => 'application/json'], 'timeout' => self::TIMEOUT];
        if (null !== $query) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }
        if (null !== $multipart) {
            $formData = new \Symfony\Component\Mime\Part\Multipart\FormDataPart([
                'folder' => $multipart['folder'],
                'file' => new \Symfony\Component\Mime\Part\DataPart($multipart['file'], $fileName ?? 'file'),
            ]);
            $options['headers'] = array_merge($options['headers'], $formData->getPreparedHeaders()->toArray());
            $options['body'] = $formData->bodyToIterable();
        }
        try {
            $response = $this->http->request($method, rtrim($this->cloudUrl, '/').$path, $options);
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Cloud ne répond pas ou est injoignable depuis le serveur.');
        }
        if (401 === $status || 403 === $status) {
            if ($this->usesSuiteTokens()) {
                $this->serviceTokens->forget(self::AUDIENCE);
            }
            throw new HttpException(502, 'Jeton Rocket Cloud refusé.');
        }
        if ($status >= 400) {
            throw new HttpException(502, \sprintf('Rocket Cloud a répondu avec l’erreur %d.', $status));
        }
        $content = $response->getContent(false);
        $data = '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : [];
    }
}
