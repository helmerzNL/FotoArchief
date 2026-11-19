<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Models\User;
use App\Modules\Ingest\Exceptions\OneDriveDeltaExpired;
use App\Modules\Ingest\Exceptions\OneDriveDevicePending;
use App\Modules\Ingest\Exceptions\OneDriveReconnectRequired;
use App\Modules\Ingest\Exceptions\OneDriveThrottled;
use App\Modules\Ingest\Models\OneDriveConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the Microsoft identity platform and Microsoft Graph for one personal
 * OneDrive, read-only (ADR-0022, ADR-0023): tenant "consumers", a public client that
 * signs in with a code (device code flow), delegated Files.Read and offline_access,
 * and only GET requests to Graph. The device code and token endpoints are the only
 * places a POST goes, and codes or tokens never appear in an exception message.
 */
class OneDriveClient
{
    public const VERIFICATION_URI = 'https://www.microsoft.com/link';

    /**
     * Starts a sign-in with a code (OAuth 2.0 device code flow): the administrator opens
     * the verification page, enters the code and approves read access.
     *
     * @return array{device_code: string, user_code: string, verification_uri: string, expires_in: int, interval: int}
     */
    public function startDeviceLogin(OneDriveConnection $connection): array
    {
        try {
            $response = Http::asForm()->acceptJson()
                ->timeout((int) config('ingest.onedrive.timeout_seconds', 30))
                ->post($this->authority().'/devicecode', [
                    'client_id' => $connection->clientId(),
                    'scope' => $this->scopes(),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('operations.onedrive.errors.connection'), previous: $exception);
        }
        $error = $response->json('error');
        if (in_array($error, ['invalid_client', 'unauthorized_client'], true)) {
            throw new RuntimeException(__('operations.onedrive.errors.app_rejected'));
        }
        $deviceCode = $response->json('device_code');
        $userCode = $response->json('user_code');
        if (! $response->successful() || ! is_string($deviceCode) || $deviceCode === '' || ! is_string($userCode) || $userCode === '') {
            // Only the error code, never the body: it can echo request values.
            throw new RuntimeException(__('operations.onedrive.errors.token', ['code' => is_string($error) ? $error : 'HTTP '.$response->status()]));
        }
        $uri = $response->json('verification_uri');

        return [
            'device_code' => $deviceCode,
            'user_code' => mb_substr($userCode, 0, 40),
            'verification_uri' => self::safeVerificationUri(is_string($uri) ? $uri : ''),
            'expires_in' => min(max(60, (int) $response->json('expires_in', 900)), 3600),
            'interval' => min(max((int) config('ingest.onedrive.device_poll_min_seconds', 5), (int) $response->json('interval', 5)), 60),
        ];
    }

    /**
     * Asks once whether the administrator finished signing in. On success the tokens are
     * stored and the drive owner is read.
     *
     * @return 'pending'|'slow_down'|'connected'
     */
    public function pollDeviceLogin(OneDriveConnection $connection, string $deviceCode, User $user): string
    {
        try {
            $tokens = $this->token($connection, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $deviceCode,
            ]);
        } catch (OneDriveDevicePending $pending) {
            return $pending->slowDown ? 'slow_down' : 'pending';
        }
        $this->storeTokens($connection, $tokens);

        $drive = $this->get($connection, '/me/drive', ['$select' => 'id,owner']);
        $owner = $drive['owner']['user']['displayName'] ?? null;
        $connection->forceFill([
            'drive_id' => is_string($drive['id'] ?? null) ? $drive['id'] : null,
            'account_name' => is_string($owner) ? mb_substr($owner, 0, 255) : null,
            'connected_by_user_id' => $user->id,
            'connected_at' => now(),
            // A new account or app starts from the beginning.
            'folder_item_id' => null,
            'folder_name' => null,
            'folder_path' => null,
            'delta_link' => null,
            'resume_link' => null,
            'resume_offset' => 0,
        ])->save();

        return 'connected';
    }

    /**
     * The page the administrator opens to enter the code. Only an https page of
     * Microsoft is shown; anything else becomes Microsoft's own short link.
     */
    public static function safeVerificationUri(string $uri): string
    {
        $parts = parse_url($uri);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['port'])
            && ($host === 'microsoft.com' || str_ends_with($host, '.microsoft.com') || $host === 'live.com' || str_ends_with($host, '.live.com'))) {
            return $uri;
        }

        return self::VERIFICATION_URI;
    }

    /**
     * The folder with its subfolders, for choosing the import source.
     *
     * @return array{folder: array{id: string, name: string, path: string, parent: string|null}, children: list<array{id: string, name: string, folders: int}>}
     */
    public function folder(OneDriveConnection $connection, ?string $itemId): array
    {
        $base = $itemId === null ? '/me/drive/root' : '/me/drive/items/'.rawurlencode($itemId);
        $item = $this->get($connection, $base, ['$select' => 'id,name,folder,parentReference,root']);
        if (! isset($item['folder']) && ! isset($item['root'])) {
            throw new RuntimeException(__('operations.onedrive.errors.not_a_folder'));
        }
        $children = [];
        $next = $this->graph().$base.'/children?'.http_build_query(['$select' => 'id,name,folder', '$top' => 200]);
        for ($page = 0; $next !== null && $page < 10; $page++) {
            $response = $this->get($connection, $next);
            foreach ((array) ($response['value'] ?? []) as $child) {
                if (is_array($child) && isset($child['folder']) && is_string($child['id'] ?? null) && is_string($child['name'] ?? null)) {
                    $children[] = ['id' => $child['id'], 'name' => $child['name'], 'folders' => (int) ($child['folder']['childCount'] ?? 0)];
                }
            }
            $next = is_string($response['@odata.nextLink'] ?? null) ? $response['@odata.nextLink'] : null;
        }
        usort($children, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $isRoot = isset($item['root']);
        $parentPath = is_string($item['parentReference']['path'] ?? null) ? $item['parentReference']['path'] : '/drive/root:';
        $name = $isRoot ? '' : (string) ($item['name'] ?? '');

        return [
            'folder' => [
                'id' => (string) ($item['id'] ?? ''),
                'name' => $name,
                'path' => $isRoot ? '/' : self::displayPath($parentPath.'/'.$name),
                'parent' => $isRoot ? null : (is_string($item['parentReference']['id'] ?? null) ? $item['parentReference']['id'] : null),
            ],
            'children' => $children,
        ];
    }

    /**
     * GET a Graph path or a link Graph returned (nextLink, deltaLink). A link that does
     * not point at Graph is refused, so the access token is never sent elsewhere.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    public function get(OneDriveConnection $connection, string $pathOrLink, array $query = []): array
    {
        $url = str_starts_with($pathOrLink, '/') ? $this->graph().$pathOrLink : $pathOrLink;
        if (! str_starts_with($url, $this->graph().'/')) {
            throw new RuntimeException(__('operations.onedrive.errors.foreign_link'));
        }

        // An empty query array would replace the query string of a nextLink or
        // deltaLink (and with it the token that marks the position), so pass it only
        // when there is one.
        $response = $this->send($connection, fn (string $token): Response => $query === []
            ? Http::withToken($token)->acceptJson()->timeout((int) config('ingest.onedrive.timeout_seconds', 30))->get($url)
            : Http::withToken($token)->acceptJson()->timeout((int) config('ingest.onedrive.timeout_seconds', 30))->get($url, $query));
        if ($response->status() === 410) {
            throw new OneDriveDeltaExpired(__('operations.onedrive.errors.delta_expired'));
        }
        $this->guard($response);
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Streams a file's content to $target. Graph answers with a redirect to a short-lived
     * pre-authenticated download URL; the HTTP client drops the Authorization header on
     * that cross-host redirect.
     *
     * @return int bytes written
     */
    public function download(OneDriveConnection $connection, string $itemId, string $target, int $maxBytes, int $timeoutSeconds): int
    {
        $response = $this->send($connection, fn (string $token): Response => Http::withToken($token)
            ->withOptions(['stream' => true])
            ->timeout(max(5, $timeoutSeconds))
            ->get($this->graph().'/me/drive/items/'.rawurlencode($itemId).'/content'));
        $this->guard($response);

        $body = $response->toPsrResponse()->getBody();
        $output = fopen($target, 'wb');
        if ($output === false) {
            throw new RuntimeException(__('uploads.storage_error'));
        }
        $written = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(1_048_576);
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new RuntimeException(__('operations.onedrive.errors.too_large'));
                }
                fwrite($output, $chunk);
            }
        } finally {
            fclose($output);
            $body->close();
        }

        return $written;
    }

    /**
     * Runs a Graph request with a valid token; one refresh and retry on 401.
     *
     * @param  \Closure(string): Response  $request
     */
    private function send(OneDriveConnection $connection, \Closure $request): Response
    {
        try {
            $response = $request($this->accessToken($connection));
            if ($response->status() === 401) {
                $connection->forceFill(['access_token' => null, 'access_token_expires_at' => null])->save();
                $response = $request($this->accessToken($connection));
            }
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('operations.onedrive.errors.connection'), previous: $exception);
        }

        return $response;
    }

    private function guard(Response $response): void
    {
        if (in_array($response->status(), [429, 503], true)) {
            $retryAfter = (int) $response->header('Retry-After');
            throw new OneDriveThrottled(min(max($retryAfter, 30), (int) config('ingest.onedrive.max_retry_after_seconds', 900)));
        }
        if ($response->status() === 401) {
            throw new OneDriveReconnectRequired(__('operations.onedrive.errors.reconnect'));
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('operations.onedrive.errors.http', ['status' => $response->status()]));
        }
    }

    private function accessToken(OneDriveConnection $connection): string
    {
        $connection->refresh();
        if (is_string($connection->access_token) && $connection->access_token !== ''
            && $connection->access_token_expires_at !== null && $connection->access_token_expires_at->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }
        if (! $connection->isConnected()) {
            throw new OneDriveReconnectRequired(__('operations.onedrive.errors.reconnect'));
        }

        // Microsoft replaces the refresh token on every use; two workers refreshing at
        // once would each store a different one, so only one refreshes at a time.
        return Cache::lock('onedrive.token-refresh', 30)->block(20, function () use ($connection): string {
            $connection->refresh();
            if (is_string($connection->access_token) && $connection->access_token !== ''
                && $connection->access_token_expires_at !== null && $connection->access_token_expires_at->isAfter(now()->addMinute())) {
                return $connection->access_token;
            }
            $tokens = $this->token($connection, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $connection->refresh_token,
            ]);
            $this->storeTokens($connection, $tokens);

            return (string) $connection->access_token;
        });
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    private function token(OneDriveConnection $connection, array $form): array
    {
        try {
            $response = Http::asForm()->acceptJson()
                ->timeout((int) config('ingest.onedrive.timeout_seconds', 30))
                ->post($this->authority().'/token', $form + [
                    'client_id' => $connection->clientId(),
                    'scope' => $this->scopes(),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('operations.onedrive.errors.connection'), previous: $exception);
        }
        $error = $response->json('error');
        if ($error === 'authorization_pending' || $error === 'slow_down') {
            throw new OneDriveDevicePending($error === 'slow_down');
        }
        if ($error === 'authorization_declined') {
            throw new RuntimeException(__('operations.onedrive.errors.declined'));
        }
        if ($error === 'expired_token' || $error === 'bad_verification_code' || $error === 'code_expired') {
            throw new RuntimeException(__('operations.onedrive.errors.expired'));
        }
        if (in_array($error, ['invalid_grant', 'interaction_required', 'consent_required'], true)) {
            throw new OneDriveReconnectRequired(__('operations.onedrive.errors.reconnect'));
        }
        if (in_array($error, ['invalid_client', 'unauthorized_client'], true)) {
            throw new OneDriveReconnectRequired(__('operations.onedrive.errors.app_rejected'));
        }
        $accessToken = $response->json('access_token');
        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            // Only the error code, never the body: it can echo request values.
            throw new RuntimeException(__('operations.onedrive.errors.token', ['code' => is_string($error) ? $error : 'HTTP '.$response->status()]));
        }
        $refreshToken = $response->json('refresh_token');

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_in' => max(60, (int) $response->json('expires_in', 3600)),
        ];
    }

    /** @param array{access_token: string, refresh_token: string|null, expires_in: int} $tokens */
    private function storeTokens(OneDriveConnection $connection, array $tokens): void
    {
        DB::transaction(function () use ($connection, $tokens): void {
            $connection->forceFill([
                'access_token' => $tokens['access_token'],
                'access_token_expires_at' => now()->addSeconds($tokens['expires_in']),
                // Keep the old refresh token only when Microsoft did not send a new one.
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            ])->save();
        });
    }

    /** "/drive/root:/Foto's/Straten" → "/Foto's/Straten" */
    public static function displayPath(string $graphPath): string
    {
        $position = strpos($graphPath, ':');
        $path = $position === false ? $graphPath : substr($graphPath, $position + 1);
        $path = '/'.ltrim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function authority(): string
    {
        return rtrim((string) config('ingest.onedrive.authority'), '/');
    }

    private function graph(): string
    {
        return rtrim((string) config('ingest.onedrive.graph'), '/');
    }

    private function scopes(): string
    {
        return (string) config('ingest.onedrive.scopes', 'Files.Read offline_access');
    }
}
