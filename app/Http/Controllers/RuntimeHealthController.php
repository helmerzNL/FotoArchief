<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Ingest\Models\JobOutboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RuntimeHealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'component' => 'application']);
    }

    public function ready(): JsonResponse
    {
        $components = [
            'database' => $this->database(),
            'storage' => $this->storage(),
            'queue' => $this->queue(),
        ];
        $ready = collect($components)->every(fn (array $component): bool => $component['status'] === 'ok');

        return response()->json([
            'status' => $ready ? 'ok' : 'blocked',
            'components' => $components,
        ], $ready ? 200 : 503);
    }

    /**
     * @return array{status: 'ok'}|array{status: 'blocked', error: class-string<Throwable>}
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return ['status' => 'blocked', 'error' => $exception::class];
        }
    }

    /**
     * @return array{status: 'ok', disk: string}|array{status: 'blocked', error: class-string<Throwable>}
     */
    private function storage(): array
    {
        try {
            $disk = (string) config('filesystems.default');
            Storage::disk($disk);

            return ['status' => 'ok', 'disk' => $disk];
        } catch (Throwable $exception) {
            return ['status' => 'blocked', 'error' => $exception::class];
        }
    }

    /**
     * @return array{status: 'ok'|'blocked', connection: string, stale_messages: int}|array{status: 'blocked', error: 'unknown_queue_connection'|class-string<Throwable>}
     */
    private function queue(): array
    {
        try {
            $connection = (string) config('outbox.connection', 'ingest');
            if (! is_array(config("queue.connections.{$connection}"))) {
                return ['status' => 'blocked', 'error' => 'unknown_queue_connection'];
            }
            $stale = JobOutboxMessage::query()
                ->whereIn('status', ['pending', 'leased'])
                ->where('created_at', '<=', now()->subMinutes((int) config('outbox.readiness_minutes', 10)))
                ->count();

            return ['status' => $stale === 0 ? 'ok' : 'blocked', 'connection' => $connection, 'stale_messages' => $stale];
        } catch (Throwable $exception) {
            return ['status' => 'blocked', 'error' => $exception::class];
        }
    }
}
