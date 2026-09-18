<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $upload_session_id
 * @property string $filename
 * @property int $byte_size
 * @property string $sha256
 * @property string $status
 * @property string|null $error_code
 * @property string|null $quarantine_upload_id
 * @property-read QuarantineUpload|null $upload
 */
class UploadSessionItem extends CatalogueModel
{
    public static function stateLabel(string $state): string
    {
        $label = match ($state) {
            'receiving' => __('uploads.states.receiving'),
            'queued' => __('uploads.states.queued'),
            'running' => __('uploads.states.running'),
            'received' => __('uploads.states.received'),
            'completed' => __('uploads.states.completed'),
            'rejected' => __('uploads.states.rejected'),
            'failed' => __('uploads.states.failed'),
            'expired' => __('uploads.states.expired'),
            default => throw new \LogicException('Unknown upload state: '.$state),
        };

        return is_string($label) ? $label : throw new \LogicException('Upload state translation must be a string.');
    }

    public function errorLabel(): ?string
    {
        $label = match ($this->error_code) {
            null => null,
            'closed' => __('uploads.closed'),
            'integrity' => __('uploads.integrity'),
            'assembly_failed' => __('uploads.assembly_failed'),
            default => throw new \LogicException('Unknown upload error code.'),
        };

        return is_string($label) || $label === null ? $label : throw new \LogicException('Upload error translation must be a string.');
    }

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }

    /** @return BelongsTo<QuarantineUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(QuarantineUpload::class, 'quarantine_upload_id');
    }
}
