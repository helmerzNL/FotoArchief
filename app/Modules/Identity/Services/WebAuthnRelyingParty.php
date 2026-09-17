<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use lbuchs\WebAuthn\WebAuthn;
use RuntimeException;

class WebAuthnRelyingParty
{
    public function server(): WebAuthn
    {
        return new WebAuthn((string) config('app.name', 'FotoArchief'), $this->rpId(), ['none'], true);
    }

    public function rpId(): string
    {
        return $this->parts()['host'];
    }

    public function origin(): string
    {
        $parts = $this->parts();
        $origin = $parts['scheme'].'://'.$parts['host'];
        if ($parts['port'] !== null) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    public function assertClientDataOrigin(string $clientDataJson): void
    {
        $clientData = json_decode($clientDataJson, true);
        if (! is_array($clientData) || ($clientData['origin'] ?? null) !== $this->origin()) {
            throw new InvalidArgumentException(__('identity.generated.t_3950932aac4b5bdf'));
        }
    }

    /**
     * @return array{scheme: string, host: string, port: int|null}
     */
    private function parts(): array
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '.')) : '';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ($scheme === 'http' && ! $this->isLocalHost($host))) {
            throw new RuntimeException(__('identity.generated.t_4aa3dc4eca460ea2'));
        }
        if (! $this->isLocalHost($host) && ! Str::contains($host, '.')) {
            throw new RuntimeException(__('identity.generated.t_08d780e5e1d61bb6'));
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
