<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Ingest\Support\OriginalPath;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
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
 *
 * For a large archive on object storage the command also paces itself: it stops after
 * --time-limit minutes or --max-gib GiB, pauses while the scan and derivative workers are
 * behind (--max-pending), and retries an object-store write a few times before it counts
 * a file as failed. Each file is written to storage first and recorded in one short
 * database transaction afterwards, so a slow store never holds a transaction open.
 */
class ImportDirectoryCommand extends Command
{
    private const SUPPORTED = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'pdf'];

    /** Types the archive holds but ingest cannot process yet; counted, never imported. */
    private const NOT_YET_SUPPORTED = ['tif', 'tiff', 'heic'];

    protected $signature = 'ingest:import-directory
        {path : Folder on the server with the image files (read only)}
        {--user= : E-mail address of the account the new photos are created for}
        {--limit=500 : Maximum number of new files to import in this run}
        {--dry-run : Only count and report; store nothing}
        {--time-limit=0 : Stop after this many minutes (0 = no limit)}
        {--max-gib=0 : Stop after this many GiB of new files (0 = no limit)}
        {--max-pending= : Pause while this many uploads are still queued or running (0 = never pause)}
        {--wait= : Longest pause in seconds before stopping when the queue stays full}';

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
        $timeLimit = max(0.0, (float) $this->option('time-limit')) * 60;
        $byteLimit = (int) round(max(0.0, (float) $this->option('max-gib')) * 1073741824);
        $maxPending = $this->option('max-pending') === null ? (int) config('ingest.import.max_pending', 200) : max(0, (int) $this->option('max-pending'));
        $waitSeconds = $this->option('wait') === null ? (int) config('ingest.import.wait_seconds', 600) : max(0, (int) $this->option('wait'));
        $started = microtime(true);
        $counts = ['new' => 0, 'imported' => 0, 'already' => 0, 'too_large' => 0, 'rejected' => 0, 'failed' => 0, 'ignored' => 0];
        $unsupported = [];
        $problems = [];
        $newBytes = 0;
        $stopReason = null;

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
                $stopReason = 'limit';
                break;
            }
            if ($timeLimit > 0 && microtime(true) - $started >= $timeLimit) {
                $stopReason = 'time';
                break;
            }
            if ($byteLimit > 0 && $newBytes >= $byteLimit) {
                $stopReason = 'bytes';
                break;
            }
            // The backlog is only measured every 25 files; counting on every file would
            // cost more than the pause saves.
            if (! $dryRun && $maxPending > 0 && $counts['new'] % 25 === 0 && ! $this->waitForQueue($maxPending, $waitSeconds)) {
                $stopReason = 'queue';
                break;
            }
            $counts['new']++;
            $newBytes += $size;
            if ($dryRun || $user === null) {
                continue;
            }
            try {
                $this->importWithRetry($uploads, $entry, $relative, $user);
                $counts['imported']++;
                if ($counts['imported'] % 100 === 0) {
                    $this->progress($counts['imported'], $newBytes, $started);
                }
            } catch (ValidationException) {
                $counts['rejected']++;
                $problems[] = $relative.' (afgewezen door de controle)';
            } catch (Throwable $exception) {
                $counts['failed']++;
                $problems[] = $relative.' ('.$exception::class.')';
            }
        }

        $this->report($counts, $unsupported, $problems, $newBytes, $dryRun, $stopReason, $waitSeconds);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Writes the file to storage and records asset, upload and outbox entry. An
     * object-store hiccup is retried after the configured delays; a file the checks
     * reject, or a path that is not usable, is not retried.
     */
    private function importWithRetry(QuarantineUploadService $uploads, SplFileInfo $entry, string $relative, User $user): void
    {
        $delays = array_values(array_filter((array) config('ingest.import.retry_delays', [2, 10, 30]), 'is_int'));
        $attempt = 0;
        while (true) {
            try {
                $uploads->quarantineNewAsset([
                    'accession_number' => 'FA-'.str()->ulid(),
                    'title' => mb_substr(pathinfo($entry->getFilename(), PATHINFO_FILENAME), 0, 255),
                    'created_by_user_id' => $user->id,
                ], new UploadedFile($entry->getPathname(), $entry->getFilename(), test: true), $user->id, $relative, allowExtendedTypes: true);

                return;
            } catch (ValidationException|InvalidArgumentException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                if (! isset($delays[$attempt])) {
                    throw $exception;
                }
                sleep($delays[$attempt]);
                $attempt++;
            }
        }
    }

    /** Uploads that are accepted but not yet scanned and processed. */
    private function backlog(): int
    {
        return QuarantineUpload::query()->whereIn('status', ['queued', 'running'])->count();
    }

    /** Pauses while the workers are behind; false when the queue stays full for $waitSeconds. */
    private function waitForQueue(int $maxPending, int $waitSeconds): bool
    {
        $deadline = microtime(true) + $waitSeconds;
        while (($pending = $this->backlog()) >= $maxPending) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $this->line("Wachtrij vol ({$pending} uploads in behandeling); wacht 15 seconden.");
            sleep(15);
        }

        return true;
    }

    private function progress(int $imported, int $bytes, float $started): void
    {
        $seconds = max(1.0, microtime(true) - $started);
        $this->line(sprintf('%d geïmporteerd · %s MiB · %s MiB/s', $imported, number_format($bytes / 1048576, 0, ',', '.'), number_format($bytes / 1048576 / $seconds, 1, ',', '.')));
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
    private function report(array $counts, array $unsupported, array $problems, int $newBytes, bool $dryRun, ?string $stopReason, int $waitSeconds): void
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
        $resume = 'Draai het commando opnieuw om verder te gaan; reeds geïmporteerde paden worden overgeslagen.';
        if ($stopReason === 'limit') {
            $this->warn('De limiet is bereikt. '.$resume);
        } elseif ($stopReason === 'time') {
            $this->warn('De tijdslimiet is bereikt. '.$resume);
        } elseif ($stopReason === 'bytes') {
            $this->warn('De bytelimiet is bereikt. '.$resume);
        } elseif ($stopReason === 'queue') {
            $this->warn("De wachtrij bleef {$waitSeconds} seconden vol. Controleer of de workers draaien (php artisan queue:work ingest) en draai het commando daarna opnieuw.");
        }
    }
}
