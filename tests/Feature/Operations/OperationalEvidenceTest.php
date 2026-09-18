<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationalIncidentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->create(['name' => 'Evidence admin', 'email' => 'evidence@example.test', 'password' => 'unused']);
    $this->admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $this->viewer = User::query()->create(['name' => 'Evidence viewer', 'email' => 'evidence-view@example.test', 'password' => 'unused']);
    $this->viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
    Http::preventStrayRequests();
});

it('keeps an append-only incident lifecycle and delivery timeline with stable notification identifiers', function (): void {
    config(['operations.alerts.enabled' => true, 'operations.alerts.webhook_url' => 'https://ops.example.test/hook']);
    Http::fake(['https://ops.example.test/hook' => Http::sequence()->push([], 503)->push([], 202)->push([], 202)->push([], 202)->push([], 202)]);
    $service = app(OperationalIncidentService::class);
    $payload = ['incidents' => [['key' => 'scanner', 'severity' => 'warning', 'title' => 'Scanner', 'detail' => 'Unavailable']]];
    $service->evaluate($payload, true);
    expect(DB::table('operational_incident_events')->count())->toBe(0);
    expect(fn () => $service->evaluate($payload, false))->toThrow(RuntimeException::class);
    $incident = DB::table('operational_incidents')->sole();
    $service->evaluate($payload, false);
    $service->acknowledge($incident->id, $this->admin);
    $service->acknowledge($incident->id, $this->admin);
    $service->evaluate($payload, false);
    $payload['incidents'][0]['severity'] = 'critical';
    $service->evaluate($payload, false);
    $service->evaluate(['incidents' => []], false);
    $service->evaluate($payload, false);
    $events = DB::table('operational_incident_events')->orderBy('id')->get();
    expect($events->pluck('event_type')->all())->toBe(['opened', 'delivery_failed', 'delivered', 'acknowledged',
        'severity_changed', 'delivered', 'resolved', 'delivered', 'reopened', 'delivered'])
        ->and($events[0]->notification_id)->toBe($events[1]->notification_id)->toBe($events[2]->notification_id)
        ->and($events[4]->notification_id)->not->toBe($events[0]->notification_id);
    $this->actingAs($this->viewer)->get('/admin/operations/recovery/incidents/'.$incident->id)->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/operations/recovery/incidents/'.$incident->id)->assertOk()->assertSee('Aflevering mislukt')->assertSee('Heropend');
});

it('keeps personal notification receipts scoped to the observed attempt and current account permissions', function (): void {
    $run = OperationRun::query()->create(['operation_type' => 'private-type-canary', 'requested_by_user_id' => $this->viewer->id, 'status' => 'failed', 'error_message' => 'secret-canary']);
    $foreign = OperationRun::query()->create(['operation_type' => 'foreign', 'requested_by_user_id' => $this->admin->id, 'status' => 'completed']);
    $page = $this->actingAs($this->viewer)->get('/admin/operations/notifications')->assertOk()->assertSee($run->id)->assertDontSee($foreign->id)->assertDontSee('secret-canary');
    $fingerprint = $page->viewData('fingerprints')[$run->id];
    $this->post('/admin/operations/notifications/'.$foreign->id.'/read', ['fingerprint' => $fingerprint])->assertForbidden();
    $this->post('/admin/operations/notifications/'.$run->id.'/read', ['fingerprint' => $fingerprint])->assertRedirect();
    $this->get('/admin/operations/notifications')->assertDontSee('Markeer als gelezen');
    $run->update(['attempts' => 2, 'status' => 'paused']);
    $this->post('/admin/operations/notifications/'.$run->id.'/read', ['fingerprint' => $fingerprint])->assertStatus(409);
    $this->get('/admin/operations/notifications')->assertSee('Ongelezen')->assertSee('Aandacht: gepauzeerd');
    $this->viewer->roles()->detach();
    $this->get('/admin/operations/notifications')->assertForbidden();
});

it('exports only explicitly selected allowlisted diagnostic data without canary secrets', function (): void {
    $run = OperationRun::query()->create(['operation_type' => 'secret-type-canary', 'status' => 'failed', 'payload' => ['token' => 'secret-payload-canary'],
        'result' => ['photo' => 'secret-photo-canary'], 'error_message' => 'secret-error-canary', 'failed_items' => 2]);
    $foreign = OperationRun::query()->create(['operation_type' => 'unselected', 'status' => 'running']);
    app(OperationalIncidentService::class)->evaluate(['incidents' => [['key' => 'secret-key-canary', 'severity' => 'warning',
        'title' => 'secret-title-canary', 'detail' => 'secret-detail-canary']]], false);
    $incident = DB::table('operational_incidents')->sole();
    $check = (string) Str::ulid();
    DB::table('recovery_checks')->insert(['id' => $check, 'kind' => 'installation', 'created_at' => now(), 'report' => json_encode([
        'secret' => 'secret-root-canary', 'checks' => [['key' => 'scanner', 'status' => 'blocked', 'detail' => 'secret-config-canary'],
            ['key' => 'secret-unknown-canary', 'status' => 'secret-status-canary']],
    ], JSON_THROW_ON_ERROR)]);
    $data = ['confirm' => 1, 'runs' => [$run->id], 'incidents' => [$incident->id], 'checks' => [$check]];
    $this->actingAs($this->viewer)->post('/admin/operations/support', $data)->assertForbidden();
    $response = $this->actingAs($this->admin)->post('/admin/operations/support', $data)->assertOk()->assertHeader('Content-Type', 'application/json');
    expect($response->getContent())->not->toContain('canary', $foreign->id)
        ->and($response->json('runs.0.failed'))->toBe(2)
        ->and($response->json('checks.0.checks'))->toBe([['key' => 'scanner', 'status' => 'blocked']])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->post('/admin/operations/support', ['runs' => array_fill(0, 26, $run->id), 'confirm' => 1])->assertSessionHasErrors('runs');
    $this->post('/admin/operations/support', ['runs' => [$run->id]])->assertSessionHasErrors('confirm');
});

it('records human evidence without allowing users to claim automated CI provenance', function (): void {
    $data = ['version' => '0.9.61', 'environment' => 'test', 'kind' => 'manual', 'result' => 'passed', 'reference' => 'Manual keyboard review',
        'source' => 'ci', 'confirm' => 1];
    $this->actingAs($this->viewer)->post('/admin/operations/evidence', $data)->assertForbidden();
    $this->actingAs($this->admin)->post('/admin/operations/evidence', $data)->assertRedirect();
    expect(DB::table('acceptance_evidence')->sole()->source)->toBe('human');
    $this->post('/admin/operations/evidence', array_replace($data, ['reference' => 'Correction: blocked', 'result' => 'blocked']))->assertRedirect();
    expect(DB::table('acceptance_evidence')->count())->toBe(2);
    $this->get('/admin/operations/evidence')->assertOk()->assertSee('Menselijke verklaring')->assertSee('Correction: blocked');
    $this->post('/admin/operations/evidence', array_replace($data, ['version' => 'invalid']))->assertSessionHasErrors('version');
    $this->delete('/admin/operations/evidence')->assertStatus(405);
});
