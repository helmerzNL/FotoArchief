<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

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

        return app(OperationalIncidentService::class)->evaluate($payload, $dryRun);
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
                'detail' => (string) ($data['remediation'] ?? $data['message'] ?? __('operations.generated.t_9a1ee9bfc64830bf')),
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
                    'title' => __('operations.generated.t_0796d5c11ba7fcdf'),
                    'detail' => "Er staan {$pending} ingesttaken klaar; drempel is {$pendingThreshold}.",
                ];
            }
            if ($this->rank('warning') >= $minimum && $failed > $failedThreshold) {
                $incidents[] = [
                    'key' => 'worker.failed_ingest_jobs',
                    'severity' => 'warning',
                    'title' => __('operations.generated.t_8819f1c1ecebf706'),
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
            'php' => __('operations.generated.t_520492bc08bda94b'),
            'extensions' => __('operations.generated.t_9056d6cca64c1e21'),
            'storage' => __('operations.generated.t_0f9bd09fcb5c1fc9'),
            'database' => __('operations.generated.t_41b1aba040516b0c'),
            'limits' => __('operations.generated.t_df161173f1f9a899'),
            'scanner' => __('operations.generated.t_e8f4e33cc1abaeb9'),
            'worker' => __('operations.generated.t_0686c5770c1cecd2'),
            'activity' => __('operations.generated.t_9175b973b7bdee46'),
            default => __('operations.generated.t_0a15005d4d8f4355'),
        };
    }
}
