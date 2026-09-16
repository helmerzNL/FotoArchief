<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OperationalAlertService
{
    private const SEVERITY_RANK = [
        'info' => 0,
        'warning' => 1,
        'critical' => 2,
    ];

    public function __construct(
        private readonly SystemDiagnosticsService $diagnostics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(bool $dryRun = false): array
    {
        $diagnostics = $this->diagnostics->getAllDiagnostics();
        $incidents = $this->incidents($diagnostics);
        $payload = [
            'application' => (string) config('app.name', 'FotoArchief'),
            'environment' => (string) config('app.env', 'production'),
            'timestamp' => now()->toIso8601String(),
            'overall_status' => $diagnostics['overall_status'] ?? 'unknown',
            'incidents' => $incidents,
        ];

        if ($dryRun || $incidents === []) {
            return [
                'sent' => false,
                'dry_run' => $dryRun,
                'reason' => $dryRun ? 'dry-run' : 'no-incidents',
                'payload' => $payload,
            ];
        }

        if (! (bool) config('operations.alerts.enabled', false)) {
            Log::warning('Operationele FotoArchief melding gedetecteerd, verzending staat uit.', $payload);

            return [
                'sent' => false,
                'dry_run' => false,
                'reason' => 'disabled',
                'payload' => $payload,
            ];
        }

        $webhookUrl = (string) config('operations.alerts.webhook_url', '');
        if ($webhookUrl === '') {
            Log::warning('Operationele FotoArchief melding gedetecteerd.', $payload);

            return [
                'sent' => false,
                'dry_run' => false,
                'reason' => 'log-only',
                'payload' => $payload,
            ];
        }

        $response = Http::timeout(5)->acceptJson()->asJson()->post($webhookUrl, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Operations alert webhook failed with status '.$response->status().'.');
        }

        return [
            'sent' => true,
            'dry_run' => false,
            'reason' => 'webhook',
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return list<array{key: string, severity: string, title: string, detail: string}>
     */
    private function incidents(array $diagnostics): array
    {
        $minimum = $this->rank((string) config('operations.alerts.minimum_severity', 'warning'));
        $incidents = [];

        foreach (['php', 'extensions', 'storage', 'database', 'limits', 'scanner', 'worker', 'activity'] as $section) {
            $data = $diagnostics[$section] ?? null;
            if (! is_array($data)) {
                continue;
            }
            $severity = ($data['status'] ?? 'ok') === 'critical' ? 'critical' : (($data['status'] ?? 'ok') === 'warning' ? 'warning' : 'info');
            if ($this->rank($severity) < $minimum) {
                continue;
            }
            if ($severity === 'info') {
                continue;
            }

            $incidents[] = [
                'key' => $section,
                'severity' => $severity,
                'title' => $this->title($section),
                'detail' => (string) ($data['remediation'] ?? $data['message'] ?? 'Controleer de operationele diagnose.'),
            ];
        }

        $worker = $diagnostics['worker'] ?? [];
        if (is_array($worker)) {
            $pending = (int) ($worker['pending_ingest_jobs'] ?? 0);
            $failed = (int) ($worker['failed_ingest_jobs'] ?? 0);
            $pendingThreshold = (int) config('operations.alerts.pending_ingest_threshold', 100);
            $failedThreshold = (int) config('operations.alerts.failed_ingest_threshold', 5);
            if ($this->rank('warning') >= $minimum && $pending > $pendingThreshold) {
                $incidents[] = [
                    'key' => 'worker.pending_ingest_jobs',
                    'severity' => 'warning',
                    'title' => 'Ingestwachtrij loopt op',
                    'detail' => "Er staan {$pending} ingesttaken klaar; drempel is {$pendingThreshold}.",
                ];
            }
            if ($this->rank('warning') >= $minimum && $failed > $failedThreshold) {
                $incidents[] = [
                    'key' => 'worker.failed_ingest_jobs',
                    'severity' => 'warning',
                    'title' => 'Mislukte ingesttaken boven drempel',
                    'detail' => "Er zijn {$failed} mislukte ingesttaken; drempel is {$failedThreshold}.",
                ];
            }
        }

        return $incidents;
    }

    private function rank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? self::SEVERITY_RANK['warning'];
    }

    private function title(string $section): string
    {
        return match ($section) {
            'php' => 'PHP-runtime vraagt aandacht',
            'extensions' => 'PHP-extensies ontbreken',
            'storage' => 'Opslagprobe faalt',
            'database' => 'Databaseprobe faalt',
            'limits' => 'Uploadlimieten zijn onveilig laag',
            'scanner' => 'Malwarescanner vraagt aandacht',
            'worker' => 'Verwerkingswachtrij vraagt aandacht',
            'activity' => 'Scheduler of worker heartbeat is oud',
            default => 'Operationele diagnose vraagt aandacht',
        };
    }
}
