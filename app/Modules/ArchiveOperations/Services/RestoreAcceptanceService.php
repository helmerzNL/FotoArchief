<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\RestoreDrill;
use App\Modules\Installation\InstallationStore;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class RestoreAcceptanceService
{
    public function __construct(private readonly AcceptanceEvidenceService $evidence) {}

    /** @param array<string, mixed> $credentials
     * @return array<string, bool|string>
     */
    public function run(RestoreDrill $drill, string $asset, array $credentials, bool $confirmed): array
    {
        $credentials = Validator::make($credentials, ['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:128']])->validate();
        Validator::make(['asset' => $asset], ['asset' => ['required', 'ulid']])->validate();
        $drill->refresh();
        $existingReport = $drill->report;
        $version = trim(File::get(base_path('VERSION')));
        $target = realpath($drill->target_directory);
        $database = $drill->target_database;
        if (! $confirmed || $drill->status !== 'verified' || ! is_array($existingReport) || ($existingReport['version'] ?? null) !== $version
            || $target === false || is_link($drill->target_directory)
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! preg_match('/^[a-z][a-z0-9_]*_restore_drill$/D', $database)
            || $database === DB::connection()->getDatabaseName()) {
            throw new RuntimeException(__('evidence.restore_invalid'));
        }
        $backup = $drill->backup()->firstOrFail();
        foreach ([base_path(), storage_path(), $backup->location] as $protected) {
            $protected = realpath($protected);
            if ($protected !== false && str_starts_with(strtolower($target).DIRECTORY_SEPARATOR, strtolower($protected).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(__('evidence.restore_invalid'));
            }
        }
        $installation = $target.'/storage/app/installation';
        if (is_link($installation) || ! (new InstallationStore($installation))->completed()) {
            throw new RuntimeException(__('evidence.restore_invalid'));
        }
        $runtime = $target.'/.acceptance-'.bin2hex(random_bytes(12));
        $process = null;
        $phase = 'start';
        $attemptReport = array_merge($existingReport, [
            'transport' => 'loopback-http', 'application_login_verified' => false, 'installer_locked' => false,
            'anonymous_denied' => false, 'authorized_asset_verified' => false,
            'acceptance_result' => 'running', 'acceptance_at' => now()->toIso8601String(),
        ]);
        $drill->update(['report' => $attemptReport]);
        try {
            File::makeDirectory($runtime, 0700);
            foreach (['cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
                File::makeDirectory($runtime.'/'.$path, 0700, true);
            }
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            if ($socket === false) {
                throw new RuntimeException(__('evidence.restore_failed'));
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            if (! is_string($address)) {
                throw new RuntimeException(__('evidence.restore_failed'));
            }
            $url = 'http://'.$address;
            $configuration = ['runtime' => $runtime, 'target' => $target, 'asset' => $asset, 'url' => $url,
                'database' => [...DB::connection()->getConfig(), 'url' => null, 'database' => $database, 'read' => null, 'write' => null]];
            $process = new Process([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, base_path('scripts/restore-acceptance-router.php')],
                base_path(), ['FOTOARCHIEF_RESTORE_ACCEPTANCE' => json_encode($configuration, JSON_THROW_ON_ERROR)], null, 90);
            $process->disableOutput();
            $process->start();
            $ready = false;
            for ($attempt = 0; $attempt < 100 && $process->isRunning(); $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorText, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            if (! $ready) {
                throw new RuntimeException(__('evidence.restore_failed'));
            }
            $client = new Client(['base_uri' => $url, 'timeout' => 20, 'connect_timeout' => 5, 'http_errors' => false,
                'allow_redirects' => false, 'cookies' => new CookieJar]);
            $phase = 'login-page';
            $login = $client->get('/login');
            $html = (string) $login->getBody();
            if ($login->getStatusCode() !== 200 || ! preg_match('/name="_token"[^>]*value="([^"]+)"/', $html, $token)) {
                throw new RuntimeException(__('evidence.restore_failed'));
            }
            $phase = 'installer-lock';
            $this->requireStatus($client, '/setup', 404);
            $phase = 'anonymous-denial';
            $this->requireStatus($client, '/admin/assets/'.$asset, 302);
            $phase = 'login-submit';
            $response = $client->post('/login', ['form_params' => $credentials + ['_token' => html_entity_decode($token[1], ENT_QUOTES)]]);
            if ($response->getStatusCode() !== 302) {
                $phase .= '-http-'.$response->getStatusCode();
                throw new RuntimeException(__('evidence.restore_failed'));
            }
            $phase = 'authorized-asset';
            $this->requireStatus($client, '/admin/assets/'.$asset, 200);
            $report = ['transport' => 'loopback-http', 'application_login_verified' => true, 'installer_locked' => true,
                'anonymous_denied' => true, 'authorized_asset_verified' => true];
            DB::transaction(function () use ($drill, $attemptReport, $report, $version): void {
                $drill->update(['report' => array_merge($attemptReport, $report, ['acceptance_result' => 'passed'])]);
                $this->evidence->record(['version' => $version, 'environment' => 'test', 'kind' => 'restore', 'result' => 'passed',
                    'reference' => $drill->id], 'test-installation');
            });

            return $report;
        } catch (Throwable $exception) {
            DB::transaction(function () use ($drill, $attemptReport, $version, $phase): void {
                $drill->update(['report' => array_merge($attemptReport, ['acceptance_result' => 'failed', 'acceptance_phase' => $phase])]);
                $this->evidence->record(['version' => $version, 'environment' => 'test', 'kind' => 'restore', 'result' => 'failed',
                    'reference' => $drill->id], 'test-installation');
            });
            Log::error(__('evidence.restore_failed'), ['drill_id' => $drill->id, 'phase' => $phase, 'exception_class' => $exception::class]);
            throw new RuntimeException(__('evidence.restore_failed').' ('.$phase.')');
        } finally {
            $process?->stop();
            if (File::isDirectory($runtime) && ! File::deleteDirectory($runtime)) {
                throw new RuntimeException(__('evidence.restore_cleanup'));
            }
        }

    }

    private function requireStatus(Client $client, string $path, int $status): void
    {
        if ($client->get($path)->getStatusCode() !== $status) {
            throw new RuntimeException(__('evidence.restore_failed'));
        }
    }
}
