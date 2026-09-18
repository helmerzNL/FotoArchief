<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class OperationalIncidentService
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function evaluate(array $payload, bool $dryRun): array
    {
        if ($dryRun) {
            return ['sent' => false, 'dry_run' => true, 'reason' => 'dry-run', 'payload' => $payload];
        }
        DB::transaction(function () use ($payload): void {
            DB::table('operational_alert_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $keys = [];
            foreach ($payload['incidents'] as $incident) {
                $key = $incident['key'];
                $keys[] = $key;
                $existing = DB::table('operational_incidents')->where('incident_key', $key)->first();
                $values = ['severity' => $incident['severity'], 'status' => 'open', 'title' => $incident['title'],
                    'detail' => $incident['detail'], 'last_seen_at' => now(), 'updated_at' => now(),
                    'observations' => $existing === null ? 1 : (int) $existing->observations + 1];
                if ($existing === null || $existing->status !== 'open' || $existing->severity !== $incident['severity']) {
                    $values += ['opened_at' => now(), 'resolved_at' => null, 'acknowledged_at' => null,
                        'acknowledged_by_user_id' => null, 'notification_id' => (string) Str::ulid(), 'notified_at' => null, 'delivery_error' => null];
                }
                if ($existing === null) {
                    DB::table('operational_incidents')->insert(['id' => (string) Str::ulid(), 'incident_key' => $key, 'created_at' => now(), ...$values]);
                } else {
                    DB::table('operational_incidents')->where('id', $existing->id)->update($values);
                }
            }
            foreach (DB::table('operational_incidents')->where('status', 'open')->whereNotIn('incident_key', $keys)->get() as $resolved) {
                DB::table('operational_incidents')->where('id', $resolved->id)->update([
                    'status' => 'resolved', 'resolved_at' => now(), 'notification_id' => (string) Str::ulid(),
                    'notified_at' => null, 'delivery_error' => null, 'updated_at' => now(),
                ]);
            }
        });
        // Persist event identifiers before transport, including an ambiguous delivery/commit outcome.
        $result = DB::transaction(function () use ($payload): array {
            DB::table('operational_alert_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $pending = DB::table('operational_incidents')->whereNull('notified_at')->orderBy('incident_key')->get();
            $outgoing = $payload;
            $outgoing['incidents'] = $pending->map(fn ($row): array => [
                'key' => $row->incident_key, 'severity' => $row->severity, 'title' => $row->title, 'detail' => $row->detail,
                'state' => $row->status, 'event_id' => $row->notification_id,
            ])->all();
            $sent = false;
            $reason = 'no-incidents';
            $error = null;
            if ($pending->isNotEmpty()) {
                $url = (string) config('operations.alerts.webhook_url', '');
                if (! (bool) config('operations.alerts.enabled', false) || $url === '') {
                    $reason = config('operations.alerts.enabled', false) ? 'log-only' : 'disabled';
                    Log::warning($reason === 'disabled' ? __('operations.generated.t_cd2bd43263428fdd') : __('operations.generated.t_21f7679e1b5a75e1'), $outgoing);
                } else {
                    try {
                        $response = Http::timeout(5)->acceptJson()->asJson()->post($url, $outgoing);
                        if ($response->successful()) {
                            $sent = true;
                            $reason = 'webhook';
                            DB::table('operational_incidents')->whereIn('id', $pending->pluck('id'))->update(['notified_at' => now(), 'delivery_error' => null]);
                        } else {
                            $error = __('operations.generated.t_dc8bae9b0e84c0b8').$response->status().'.';
                        }
                    } catch (ConnectionException) {
                        $error = __('recovery.errors.webhook_connection');
                        if (! is_string($error)) {
                            throw new \UnexpectedValueException('recovery.errors.webhook_connection');
                        }
                    }
                    if ($error !== null) {
                        DB::table('operational_incidents')->whereIn('id', $pending->pluck('id'))->update(['delivery_error' => $error]);
                        Log::error($error);
                        $reason = 'delivery-failed';
                    }
                }
            } elseif ($payload['incidents'] !== []) {
                $reason = 'deduplicated';
            }

            return ['sent' => $sent, 'dry_run' => false, 'reason' => $reason, 'payload' => $outgoing, 'error' => $error];
        });
        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        return $result;
    }

    public function acknowledge(string $id, User $user): void
    {
        abort_unless($user->hasPermission('users.manage'), 403);
        DB::transaction(function () use ($id, $user): void {
            DB::table('operational_alert_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $incident = DB::table('operational_incidents')->where('id', $id)->firstOrFail();
            if ($incident->status !== 'open') {
                throw ValidationException::withMessages(['incident' => __('recovery.incident_closed')]);
            }
            if ($incident->acknowledged_at === null) {
                DB::table('operational_incidents')->where('id', $id)->update(['acknowledged_at' => now(), 'acknowledged_by_user_id' => $user->id, 'updated_at' => now()]);
                Log::info(__('recovery.incident_acknowledged'), ['incident_id' => $id, 'actor_user_id' => $user->id]);
            }
        });
    }
}
