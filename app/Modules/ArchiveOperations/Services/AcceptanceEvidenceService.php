<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class AcceptanceEvidenceService
{
    /** @param array<string, mixed> $data */
    public function record(array $data, string $source, ?User $actor = null): string
    {
        $values = Validator::make($data + ['source' => $source], [
            'version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+$/D'],
            'environment' => ['required', 'in:test,staging,production'],
            'kind' => ['required', 'in:installation,upgrade,restore,browser,manual'],
            'result' => ['required', 'in:passed,failed,blocked'],
            'reference' => ['required', 'string', 'max:500'],
        ])->validate();
        abort_unless(in_array($source, ['human', 'test-installation', 'ci'], true), 422);
        $id = (string) Str::ulid();
        DB::table('acceptance_evidence')->insert($values + [
            'id' => $id, 'source' => $source, 'actor_user_id' => $actor?->id, 'created_at' => now(),
        ]);

        return $id;
    }
}
