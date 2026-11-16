<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells administrators that a newer release exists, following App-Guidance
 * shared/guidelines/update-check.md. It reads one thing: the latest published, non-prerelease
 * release, from the github.com redirect (not the rate-limited API), and sends nothing about the
 * installation. It never updates, restarts or downloads anything; a page view never calls GitHub.
 */
final class UpdateCheckService
{
    private const string CACHE_KEY = 'operations.update_check';

    private const string LOCK_KEY = 'operations.update_check.fetch';

    private const string USER_AGENT = 'FotoArchief-update-check';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * The status from the cache only; never calls GitHub (for page renders).
     *
     * @return array<string, mixed>
     */
    public function cached(): array
    {
        return $this->present($this->state());
    }

    /**
     * The status, asking GitHub when the cached answer is due (or, with $force, when the manual
     * floor has passed).
     *
     * @return array<string, mixed>
     */
    public function status(bool $force = false): array
    {
        if (! $this->enabled()) {
            return $this->present([]);
        }
        $state = $this->state();
        if ($this->due($state, $force)) {
            // One request at a time: whoever holds the lock asks GitHub, the others read its answer.
            $result = Cache::lock(self::LOCK_KEY, 10)->get(function () use ($force): array {
                $fresh = $this->state();
                if (! $this->due($fresh, $force)) {
                    return $fresh;
                }
                $fresh = $this->fetch($fresh);
                Cache::forever(self::CACHE_KEY, $fresh);

                return $fresh;
            });
            $state = is_array($result) ? $result : $this->state();
        }

        return $this->present($state);
    }

    public function enabled(): bool
    {
        return (bool) config('operations.updates.enabled', true);
    }

    public function currentVersion(): string
    {
        return ltrim(trim((string) @file_get_contents(base_path('VERSION'))), 'v');
    }

    /** Numeric, part by part; a value that does not parse is never newer. */
    public static function isNewer(string $candidate, string $current): bool
    {
        $parse = static function (string $version): ?array {
            $version = ltrim(trim($version), 'vV');
            if (preg_match('/^\d+(\.\d+)*$/', $version) !== 1) {
                return null;
            }

            return array_map('intval', explode('.', $version));
        };
        $a = $parse($candidate);
        $b = $parse($current);
        if ($a === null || $b === null) {
            return false;
        }
        $length = max(count($a), count($b));
        $a = array_pad($a, $length, 0);
        $b = array_pad($b, $length, 0);

        return $a > $b;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function fetch(array $state): array
    {
        $repository = (string) config('operations.updates.repository');
        $state['attempted_at'] = now()->getTimestamp();
        try {
            $response = $this->http->withHeaders(['User-Agent' => self::USER_AGENT])
                ->withoutRedirecting()
                ->timeout((int) config('operations.updates.timeout_seconds', 5))
                ->get("https://github.com/{$repository}/releases/latest");
            $status = $response->status();
            $location = (string) $response->header('Location');
            if (in_array($status, [301, 302, 303, 307, 308], true) && preg_match('#/releases/tag/v?(\d+(?:\.\d+)*)$#', $location, $match) === 1) {
                return [...$state, 'ok' => true, 'latest' => $match[1], 'checked_at' => now()->getTimestamp(), 'error' => null, 'error_detail' => null];
            }
            if ((in_array($status, [301, 302, 303, 307, 308], true) && preg_match('#/releases/?$#', $location) === 1) || $status === 404) {
                return [...$state, 'ok' => true, 'latest' => null, 'checked_at' => now()->getTimestamp(), 'error' => null, 'error_detail' => null];
            }

            return $this->failure($state, in_array($status, [403, 429], true) ? "HTTP {$status} (rate limit)" : "HTTP {$status}", "Unexpected answer from github.com (status {$status}).");
        } catch (ConnectionException $exception) {
            $message = $exception->getMessage();
            $reason = match (true) {
                str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false => 'Timeout',
                stripos($message, 'SSL') !== false || stripos($message, 'certificate') !== false => 'SSLError (certificate)',
                default => 'ConnectionError (no connection or DNS)',
            };

            return $this->failure($state, $reason, $message);
        } catch (Throwable $exception) {
            return $this->failure($state, $exception::class, $exception->getMessage());
        }
    }

    /**
     * A failure keeps the last good answer and adds the reason.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function failure(array $state, string $reason, string $detail): array
    {
        Log::warning('Update check failed', ['reason' => $reason, 'detail' => $detail]);

        return [...$state, 'ok' => false, 'error' => $reason, 'error_detail' => $detail, 'failed_at' => now()->getTimestamp()];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function due(array $state, bool $force): bool
    {
        $attempted = $state['attempted_at'] ?? null;
        if (! is_int($attempted)) {
            return true;
        }
        $age = now()->getTimestamp() - $attempted;
        if ($force) {
            return $age >= (int) config('operations.updates.manual_floor_seconds', 30);
        }
        $ttl = ($state['ok'] ?? false) === true
            ? (int) config('operations.updates.success_ttl_seconds', 21600)
            : (int) config('operations.updates.failure_ttl_seconds', 600);

        return $age >= $ttl;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = Cache::get(self::CACHE_KEY);
        if (! is_array($state)) {
            return [];
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function present(array $state): array
    {
        $repository = (string) config('operations.updates.repository');
        $current = $this->currentVersion();
        $latest = is_string($state['latest'] ?? null) ? $state['latest'] : null;
        $available = $latest !== null && self::isNewer($latest, $current);
        $enabled = $this->enabled();
        $error = is_string($state['error'] ?? null) ? $state['error'] : null;

        return [
            'enabled' => $enabled,
            'current' => $current,
            'latest' => $latest,
            'available' => $enabled && $available,
            'url' => $latest !== null ? "https://github.com/{$repository}/releases/tag/v{$latest}" : null,
            'checkedAt' => is_int($state['checked_at'] ?? null) ? $state['checked_at'] : null,
            'error' => $enabled ? $error : null,
            'errorDetail' => $enabled && is_string($state['error_detail'] ?? null) ? $state['error_detail'] : null,
            'repo' => $repository,
            'state' => match (true) {
                ! $enabled => 'disabled',
                $available => 'available',
                $error !== null && ! isset($state['checked_at']) => 'failed',
                ! isset($state['checked_at']) => 'unchecked',
                $latest === null => 'no_release',
                default => 'current',
            },
        ];
    }
}
