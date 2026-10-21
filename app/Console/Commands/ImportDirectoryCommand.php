<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Ingest\Support\OriginalPath;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Brings an existing folder of images into quarantine, keeping each file's
 * relative folder path as provenance.
 *
 * Built for archives too large for the browser (hundreds of GB): the operator
 * copies or syncs the folder to the server and points this command at it. The
 * folder is only read. Files enter the normal ingest pipeline (quarantine, scan,
 * checksum, derivatives), so nothing is published and duplicates are still caught.
 * A re-run skips paths that were already imported, so it can be resumed in batches.
 */
class ImportDirectoryCommand extends Command
{
    private const SUPPORTED = ['jpg', 'jpeg', 'png', 'webp'];

    /** Types the archive holds but ingest cannot process yet; counted, never imported. */
    private const NOT_YET_SUPPORTED = ['bmp', 'pdf', 'tif', 'tiff', 'heic'];

    protected $signature = 'ingest:import-directory
        {path : Folder on the server with the image files (read only)}
        {--user= : E-mail address of the account the new photos are created for}
        {--limit=500 : Maximum number of new files to import in this run}
        {--dry-run : Only count and report; store nothing}';

    protected $description = 'Import an existing folder of images, keeping the relative folder path as provenance';

    public function handle(QuarantineUploadService $uploads): int
    {
        $root = realpath((string) $this->argument('path'));
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            $this->error('De map bestaat niet of is niet leesbaar.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $user = null;
        if (! $dryRun) {
            $user = User::query()->where('email', strtolower((string) $this->option('user')))->first();
            if ($user === null || ! $user->hasPermission('assets.create') || ! $user->hasPermission('assets.view')) {
                $this->error('Geef met --user een bestaand account op dat foto\'s mag aanmaken.');

                return self::FAILURE;
            }
        }

        $maxBytes = (int) config('ingest.max_upload_bytes');
        $counts = ['new' => 0, 'imported' => 0, 'already' => 0, 'too_large' => 0, 'rejected' => 0, 'failed' => 0, 'ignored' => 0];
        $unsupported = [];
        $problems = [];
        $newBytes = 0;
        $limitReached = false;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            // Symlinks could lead outside the import folder.
            if ($entry->isLink() || ! $entry->isFile()) {
                continue;
            }
            $extension = strtolower($entry->getExtension());
            if (in_array($extension, self::NOT_YET_SUPPORTED, true)) {
                $unsupported[$extension] = ($unsupported[$extension] ?? 0) + 1;

                continue;
            }
            if (! in_array($extension, self::SUPPORTED, true)) {
                $counts['ignored']++;

                continue;
            }
            $size = $entry->getSize();
            if ($size === false || $size <= 0 || $size > $maxBytes) {
                $counts['too_large']++;
                $problems[] = $this->relative($root, $entry).' (leeg of groter dan '.$maxBytes.' bytes)';

                continue;
            }
            try {
                $relative = OriginalPath::normalize($this->relative($root, $entry));
            } catch (InvalidArgumentException) {
                $counts['rejected']++;
                $problems[] = $this->relative($root, $entry).' (pad niet bruikbaar)';

                continue;
            }
            if (QuarantineUpload::query()->where('original_path', $relative)->exists()) {
                $counts['already']++;

                continue;
            }
            if ($counts['new'] >= $limit) {
                $limitReached = true;
                break;
            }
            $counts['new']++;
            $newBytes += $size;
            if ($dryRun) {
                continue;
            }
            try {
                DB::transaction(function () use ($uploads, $entry, $relative, $user): void {
                    $asset = Asset::query()->create([
                        'accession_number' => 'FA-'.str()->ulid(),
                        'title' => mb_substr(pathinfo($entry->getFilename(), PATHINFO_FILENAME), 0, 255),
                        'created_by_user_id' => $user->id,
                    ]);
                    $uploads->quarantine($asset, new UploadedFile($entry->getPathname(), $entry->getFilename(), test: true), $user->id, $relative);
                });
                $counts['imported']++;
            } catch (ValidationException) {
                $counts['rejected']++;
                $problems[] = $relative.' (afgewezen door de controle)';
            } catch (Throwable $exception) {
                $counts['failed']++;
                $problems[] = $relative.' ('.$exception::class.')';
            }
        }

        $this->report($counts, $unsupported, $problems, $newBytes, $dryRun, $limitReached);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function relative(string $root, SplFileInfo $entry): string
    {
        return substr($entry->getPathname(), strlen($root) + 1);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $unsupported
     * @param  list<string>  $problems
     */
    private function report(array $counts, array $unsupported, array $problems, int $newBytes, bool $dryRun, bool $limitReached): void
    {
        $this->info($dryRun ? 'Proefdraai: er is niets opgeslagen.' : 'Import afgerond.');
        $this->line(($dryRun ? 'Nieuw te importeren: ' : 'Nieuw aangeboden: ').$counts['new'].' ('.number_format($newBytes / 1048576, 1, ',', '.').' MiB)');
        if (! $dryRun) {
            $this->line('In quarantaine geplaatst: '.$counts['imported']);
        }
        $this->line('Al eerder geïmporteerd (zelfde pad): '.$counts['already']);
        $this->line('Te groot of leeg: '.$counts['too_large'].' · Afgewezen: '.$counts['rejected'].' · Mislukt: '.$counts['failed']);
        $this->line('Overige bestanden genegeerd: '.$counts['ignored']);
        foreach ($unsupported as $extension => $count) {
            $this->warn("Nog niet ondersteund en overgeslagen: {$count} × .{$extension}");
        }
        foreach (array_slice($problems, 0, 20) as $problem) {
            $this->line('  - '.$problem);
        }
        if (count($problems) > 20) {
            $this->line('  … en '.(count($problems) - 20).' meer.');
        }
        if ($limitReached) {
            $this->warn('De limiet is bereikt. Draai het commando opnieuw om verder te gaan; reeds geïmporteerde paden worden overgeslagen.');
        }
    }
}
