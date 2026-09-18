<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiIndexGenerationService;
use App\Modules\Ai\Services\AiIndexWorkbench;
use App\Modules\Ai\Services\AiOperationAuditService;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AiIndexWorkbenchController extends Controller
{
    public function index(Request $request, AiIndexWorkbench $workbench): View
    {
        $user = $this->user($request);
        $filters = $request->validate(['collection' => ['nullable', 'ulid', 'exists:collections,id']]);
        $collection = $filters['collection'] ?? null;
        $collections = Collection::query()->whereHas('assets', fn ($q) => $q->when(! $user->hasPermission('assets.publish'), fn ($q) => $q->where('created_by_user_id', $user->id)))->orderBy('title')->get(['id', 'title']);
        $coverage = $workbench->coverage($user, $collection);
        $counts = DB::query()->fromSub(clone $coverage, 'coverage')->selectRaw('coverage_status, COUNT(*) AS total')->groupBy('coverage_status')->pluck('total', 'coverage_status');
        $items = $coverage->orderBy('id')->paginate(25)->withQueryString();
        $settings = app(AiConfigurationService::class)->effective();
        $active = app(AiIndexGenerationService::class)->activeForProvider((string) ($settings['embeddings_provider'] ?? ''));
        $available = app(PgvectorEmbeddingStore::class)->available();
        $configuration = $workbench->configurationFingerprint();
        $generations = AiEmbeddingGeneration::query()
            ->when(! $user->hasPermission('users.manage'), fn ($q) => $q->whereHas('operationRun', fn ($q) => $q->where('requested_by_user_id', $user->id)))
            ->with(['operationRun.auditEvents' => fn ($q) => $q->whereIn('event_type', ['ai.index.generation_failed', 'ai.index.generation_activated'])])->latest('id')->paginate(10, ['*'], 'generation_page');

        return view('ai.index-workbench', compact('collections', 'collection', 'counts', 'items', 'active', 'available', 'generations', 'settings', 'configuration'));
    }

    public function repair(Request $request, AiIndexWorkbench $workbench): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'collection' => ['required', 'ulid', 'exists:collections,id'],
            'selected' => ['required', 'array', 'min:1', 'max:25'], 'selected.*' => ['required', 'ulid', 'distinct'],
            'head' => ['nullable', 'ulid'], 'confirm' => ['accepted'],
            'configuration' => ['required', 'string', 'size:64'],
        ]);
        $run = $workbench->repair($user, $data['collection'], $data['selected'], $data['head'] ?? null, $data['configuration']);

        return redirect()->route('admin.operations.runs.show', $run);
    }

    public function activate(Request $request, AiEmbeddingGeneration $generation): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('users.manage'), 403);
        $request->validate(['confirm' => ['accepted']]);
        $run = $generation->operationRun;
        abort_unless($run instanceof OperationRun && in_array($run->status, ['completed', 'failed'], true), 422);
        try {
            app(AiIndexGenerationService::class)->activate($generation);
        } catch (\RuntimeException $exception) {
            app(AiOperationAuditService::class)->generation($run, $generation, $exception);
            throw ValidationException::withMessages(['generation' => $exception->getMessage()]);
        }

        return back()->with('status', __('indexwork.activated'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.view')
            && ($user->hasPermission('users.manage') || $user->hasPermission('catalogue.manage')), 403);

        return $user;
    }
}
