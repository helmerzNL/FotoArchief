<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Services\ReviewReceipt;
use App\Modules\Publication\Models\Publication;
use App\Modules\Publication\Services\PublicationReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicationWorkbenchController extends Controller
{
    public function preview(Request $request, Asset $asset): Response
    {
        $this->authorize('view', $asset);
        $asset->load(['files', 'rights.license', 'rights.rightsStatement', 'publication']);
        $publication = $asset->publication ?? new Publication(['download_policy' => 'none']);
        $file = $asset->currentPublicFile();
        $right = $asset->rights->firstWhere('verification_status', 'verified');

        return response()->view('public.photo', ['asset' => $asset, 'publication' => $publication, 'file' => $file, 'right' => $right, 'staffPreview' => true])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function bulkPreview(Request $request, PublicationReviewService $reviews, ReviewReceipt $receipts): View
    {
        $user = $this->reviewer($request);
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1', 'max:25'], 'asset_ids.*' => ['required', 'ulid', 'distinct'],
            'decision' => ['required', 'in:publish,reject'], 'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
        ]);
        $rows = [];
        $fingerprints = [];
        foreach ($data['asset_ids'] as $id) {
            $asset = Asset::query()->whereKey($id)->firstOrFail();
            $this->authorize('view', $asset);
            $rows[] = ['asset' => $asset, 'checks' => $reviews->checklist($asset)];
            $fingerprints[$id] = $reviews->fingerprint($asset);
        }
        $receipt = $receipts->issue($user, 'publication-bulk', $data + ['fingerprints' => $fingerprints]);

        return view('admin.publications.bulk', compact('rows', 'receipt', 'data'));
    }

    public function bulkApply(Request $request, PublicationReviewService $reviews, ReviewReceipt $receipts): View
    {
        $user = $this->reviewer($request);
        $input = $request->validate(['receipt' => ['required', 'string', 'max:100000'], 'confirm' => ['required', 'accepted']]);
        $data = $receipts->read($user, 'publication-bulk', $input['receipt']);
        $results = [];
        foreach ($data['asset_ids'] as $id) {
            try {
                $asset = $reviews->decide($user, $id, $data['decision'], $data['reason'] ?? null, $data['fingerprints'][$id]);
                $results[] = ['id' => $id, 'label' => $asset->accession_number, 'ok' => true, 'message' => __('publishwork.decided')];
            } catch (ValidationException|AuthorizationException|ModelNotFoundException $error) {
                $results[] = ['id' => $id, 'label' => $id, 'ok' => false, 'message' => __('publishwork.changed')];
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 409, 422], true)) {
                    throw $error;
                }
                $results[] = ['id' => $id, 'label' => $id, 'ok' => false, 'message' => __('publishwork.blocked')];
            }
        }

        return view('catalogue.bulk.results', compact('results'));
    }

    private function reviewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.publish'), 403);

        return $user;
    }
}
