<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class InstallationStorage
{
    public function check(InstallationSettings $settings): void
    {
        $settings->apply(config());
        Storage::forgetDisk($settings->disk);
        $disk = Storage::disk($settings->disk);
        $key = '.installation-probe/'.bin2hex(random_bytes(16));
        $content = random_bytes(32);
        $written = false;
        try {
            $written = $disk->put($key, $content, ['visibility' => 'private']);
            if (! $written || $disk->get($key) !== $content) {
                throw new RuntimeException('Opslagcontrole kon het testbestand niet teruglezen.');
            }
        } finally {
            if ($written && ! $disk->delete($key)) {
                throw new RuntimeException('Opslagcontrole kon het tijdelijke testbestand niet verwijderen.');
            }
        }
    }
}
