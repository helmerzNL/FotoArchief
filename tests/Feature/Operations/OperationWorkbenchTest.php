<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationWorkbenchService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->create(['name' => 'Workbench', 'email' => 'workbench@example.test', 'password' => 'unused']);
    $this->admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $this->outsider = User::query()->create(['name' => 'Outside', 'email' => 'outside@example.test', 'password' => 'unused']);
    $this->assets = collect(range(1, 3))->map(fn (int $n) => Asset::query()->create([
        'title' => "Workbench {$n}", 'accession_number' => "WB-{$n}", 'created_by_user_id' => $this->admin->id,
    ]));
    $this->run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE, 'status' => 'failed',
        'requested_by_user_id' => $this->admin->id, 'total_items' => 3,
        'payload' => ['asset_ids' => $this->assets->pluck('id')->all(), 'provider' => 'local', 'model' => 'fixture', 'secret' => 'private-value'],
    ]);
    foreach ($this->assets as $n => $asset) {
        $this->run->auditEvents()->create([
            'asset_id' => $asset->id, 'event_type' => 'ai.analysis.item_failed', 'severity' => 'error',
            'message' => 'Fixture failure '.$n, 'context' => ['secret' => 'private-context'],
        ]);
    }
    $this->run->auditEvents()->create([
        'asset_id' => $this->assets[1]->id, 'event_type' => 'ai.analysis.item_succeeded', 'severity' => 'info', 'message' => 'Recovered photo',
        'created_at' => now()->addSecond(),
    ]);
});

it('shows safe settings selection latest outcomes and guarded task access', function (): void {
    $this->actingAs($this->admin)->get("/admin/operations/runs/{$this->run->id}")
        ->assertOk()->assertSee('WB-1')->assertSee('Recovered photo')->assertSee('Verwerkt')
        ->assertDontSee('private-value')->assertDontSee('private-context');
    $this->actingAs($this->outsider)->get("/admin/operations/runs/{$this->run->id}")->assertForbidden();
});

it('creates one child with only selected current failures and keeps history', function (): void {
    Queue::fake();
    $data = ['selected' => [$this->assets[0]->id], 'confirm' => '1'];
    $this->actingAs($this->admin)->post("/admin/operations/runs/{$this->run->id}/retry-selected", $data)->assertRedirect();
    $child = OperationRun::query()->whereKeyNot($this->run->id)->firstOrFail();
    expect($child->payload['asset_ids'])->toBe([$this->assets[0]->id])
        ->and($child->payload['parent_run_id'])->toBe($this->run->id)
        ->and($child->payload)->not->toHaveKey('secret')
        ->and($this->run->fresh()->status)->toBe('failed');
    Queue::assertPushed(ProcessAiAnalysisJob::class, 1);
    $this->post("/admin/operations/runs/{$this->run->id}/retry-selected", $data)->assertSessionHasErrors('selected');
    Queue::assertPushed(ProcessAiAnalysisJob::class, 1);
});

it('rejects successful foreign unconfirmed and unauthorized retries without dispatch', function (): void {
    Queue::fake();
    $url = "/admin/operations/runs/{$this->run->id}/retry-selected";
    $this->actingAs($this->admin)->post($url, ['selected' => [$this->assets[1]->id], 'confirm' => '1'])->assertSessionHasErrors('selected');
    $this->post($url, ['selected' => [(string) Str::ulid()], 'confirm' => '1'])->assertSessionHasErrors('selected');
    $this->post($url, ['selected' => [$this->assets[0]->id]])->assertSessionHasErrors('confirm');
    $this->actingAs($this->outsider)->post($url, ['selected' => [$this->assets[0]->id], 'confirm' => '1'])->assertForbidden();
    Queue::assertNothingPushed();
});

it('retains a missing-photo failure through its audit reference', function (): void {
    $id = (string) Str::ulid();
    $this->run->update(['payload' => ['asset_ids' => [$id]]]);
    $this->run->auditEvents()->create([
        'event_type' => 'ai.analysis.item_failed', 'severity' => 'error',
        'message' => 'The source no longer exists', 'context' => ['asset_id' => $id],
    ]);
    $items = app(OperationWorkbenchService::class)->items($this->run, 1);
    expect($items[0]['status'])->toBe('failed')->and($items[0]['reason'])->toBe('The source no longer exists');
});

it('pauses queued work and resumes once without resetting its cursor', function (): void {
    Queue::fake();
    $this->run->update(['status' => 'queued', 'payload' => ['cursor' => 2]]);
    $url = "/admin/operations/runs/{$this->run->id}/control";
    $this->actingAs($this->admin)->post($url, ['action' => 'pause'])->assertRedirect();
    expect($this->run->fresh()->status)->toBe('paused');
    $job = new ProcessAiAnalysisJob($this->run->id);
    $job->handle();
    $job->failed(new RuntimeException('duplicate delivery'));
    expect($this->run->fresh()->status)->toBe('paused')->and($this->run->fresh()->attempts)->toBe(0);
    $this->post($url, ['action' => 'resume'])->assertRedirect();
    expect($this->run->fresh()->payload['cursor'])->toBe(2)->and($this->run->fresh()->pause_requested)->toBeFalse();
    $this->post($url, ['action' => 'resume'])->assertUnprocessable();
    Queue::assertPushed(ProcessAiAnalysisJob::class, 1);
});

it('keeps a running claim until its worker reaches a safe pause checkpoint', function (): void {
    $this->run->update(['status' => 'running', 'claim_token' => 'in-flight', 'started_at' => now()]);
    $this->actingAs($this->admin)->post("/admin/operations/runs/{$this->run->id}/control", ['action' => 'pause'])->assertRedirect();
    expect($this->run->fresh()->status)->toBe('running')
        ->and($this->run->fresh()->claim_token)->toBe('in-flight')
        ->and($this->run->fresh()->pause_requested)->toBeTrue();
});

it('does not claim to pause non-checkpointable cleanup or let nonadmins control work', function (): void {
    $this->run->update(['status' => 'queued', 'operation_type' => 'storage.cleanup']);
    $url = "/admin/operations/runs/{$this->run->id}/control";
    $this->actingAs($this->admin)->post($url, ['action' => 'pause'])->assertUnprocessable();
    $this->actingAs($this->outsider)->post($url, ['action' => 'pause'])->assertForbidden();
});

it('searches both audit sources and exports only an explicit safe envelope', function (): void {
    AssetAuditEvent::query()->create([
        'asset_id' => $this->assets[0]->id, 'actor_user_id' => $this->admin->id,
        'event_type' => 'asset.updated', 'details' => ['description' => 'private-description'],
    ]);
    $this->actingAs($this->admin)->get('/admin/operations/audit?asset=WB-1&actor='.$this->admin->id)
        ->assertOk()->assertSee('asset.updated')->assertSee('ai.analysis.item_failed');
    $response = $this->get('/admin/operations/audit?asset=WB-1&export=jsonl')->assertOk();
    $lines = array_map(fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($response->streamedContent())));
    expect($lines)->toHaveCount(2);
    foreach ($lines as $line) {
        expect(array_keys($line))->toBe(['id', 'created_at', 'asset_id', 'actor_user_id', 'event_type', 'operation_run_id']);
    }
    expect($response->streamedContent())->not->toContain('private-');
    $this->get('/admin/operations/audit?run='.$this->run->id.'&event=asset.updated')->assertOk()->assertDontSee('asset.updated</p>', false);
    $this->actingAs($this->outsider)->get('/admin/operations/audit?export=jsonl')->assertForbidden();
});

it('rolls back resume state when queue insertion fails', function (): void {
    $this->run->update(['status' => 'paused', 'pause_requested' => true, 'operation_type' => VerifyIntegrityJob::TYPE]);
    Queue::shouldReceive('connection')->with('ingest')->andReturnSelf();
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue unavailable'));
    expect(fn () => app(OperationWorkbenchService::class)->control($this->run, $this->admin, 'resume'))->toThrow(RuntimeException::class);
    expect($this->run->fresh()->status)->toBe('paused')->and(DB::table('jobs')->count())->toBe(0);
});

it('enforces the export ceiling and exact date and event filters', function (): void {
    $event = [
        'operation_run_id' => $this->run->id, 'asset_id' => null,
        'event_type' => 'test.ceiling', 'severity' => 'info',
        'message' => 'fixture', 'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00',
    ];
    foreach (array_chunk(range(1, 10001), 250) as $chunk) {
        DB::table('operation_run_audit_events')->insert(array_map(fn () => ['id' => (string) Str::ulid(), ...$event], $chunk));
    }
    $this->actingAs($this->admin)->get('/admin/operations/audit?event=test.ceiling&export=jsonl')->assertSessionHasErrors('export');
    DB::table('operation_run_audit_events')->where('event_type', 'test.ceiling')->where('id', DB::table('operation_run_audit_events')->where('event_type', 'test.ceiling')->value('id'))->delete();
    $export = $this->get('/admin/operations/audit?event=test.ceiling&from=2026-09-01&until=2026-09-01&export=jsonl')->assertOk();
    expect(substr_count($export->streamedContent(), "\n"))->toBe(10000);
    $this->get('/admin/operations/audit?event=test.ceiling&from=2026-09-02&export=jsonl')->assertOk()->assertStreamedContent('');
    $this->get('/admin/operations/audit?from=2026-09-02&until=2026-09-01')->assertSessionHasErrors('until');
});
