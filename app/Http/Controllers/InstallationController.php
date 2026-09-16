<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Installation\InstallationFailure;
use App\Modules\Installation\InstallationRunner;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class InstallationController extends Controller
{
    public function __construct(private readonly InstallationStore $store) {}

    public function show(Request $request): View
    {
        return view('installation.setup', [
            'authorized' => $this->authorized($request),
            'resuming' => $this->store->read()?->phase === 'installing',
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $key = 'installation-unlock:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts('installation-unlock', 30)) {
            abort(429, 'Te veel pogingen. Wacht een minuut.');
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit('installation-unlock', 60);
        $state = $this->store->read();
        if ($state === null || ! hash_equals($state->codeHash, hash('sha256', trim($data['code'])))) {
            throw ValidationException::withMessages(['code' => 'De installatiecode is niet geldig.']);
        }
        $request->session()->regenerate();
        $request->session()->put('installation_authorized_until', now()->addMinutes(20)->timestamp);

        return redirect('/setup');
    }

    public function check(Request $request, InstallationRunner $runner): RedirectResponse
    {
        $this->requireAuthorization($request);
        $settings = $this->settings($request);
        try {
            $runner->check($settings);
        } catch (Throwable $exception) {
            $this->connectionFailure($exception);
        }

        return redirect('/setup')->withInput($request->except(['db_password', 'secret_key', 'access_key', 'password', 'password_confirmation', '_token']))
            ->with('status', 'Database en opslag zijn bereikbaar. Het testbestand is geschreven, teruggelezen en verwijderd. Vul de geheimen opnieuw in om te installeren.');
    }

    public function complete(Request $request, InstallationRunner $runner): RedirectResponse
    {
        $this->requireAuthorization($request);
        $settings = $this->settings($request);
        $admin = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:14', 'max:128', 'confirmed'],
        ]);
        try {
            $runner->complete($settings, $admin['name'], $admin['email'], $admin['password']);
        } catch (Throwable $exception) {
            $this->connectionFailure($exception);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('status', 'Installatie voltooid. Log in met je beheerdersaccount.');
    }

    private function authorized(Request $request): bool
    {
        $expires = $request->session()->get('installation_authorized_until');

        return is_int($expires) && $expires > now()->timestamp;
    }

    private function requireAuthorization(Request $request): void
    {
        abort_unless($this->authorized($request), 403, 'Voer eerst de installatiecode in; toestemming verloopt na 20 minuten.');
    }

    private function settings(Request $request): InstallationSettings
    {
        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:\-\[\]]+$/'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'db_username' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'db_password' => ['required', 'string', 'max:1024'],
            'db_sslmode' => ['required', Rule::in(['disable', 'prefer', 'require', 'verify-full'])],
            'disk' => ['required', Rule::in(['local', 's3'])],
            'endpoint' => ['nullable', 'required_if:disk,s3', 'url:http,https', 'max:500'],
            'region' => ['nullable', 'required_if:disk,s3', 'string', 'max:100'],
            'bucket' => ['nullable', 'required_if:disk,s3', 'string', 'max:100'],
            'access_key' => ['nullable', 'required_if:disk,s3', 'string', 'max:1024'],
            'secret_key' => ['nullable', 'required_if:disk,s3', 'string', 'max:1024'],
            'path_style' => ['nullable', 'boolean'],
        ]);

        return new InstallationSettings(
            $data['db_host'], (int) $data['db_port'], $data['db_database'], $data['db_username'],
            $data['db_password'], $data['db_sslmode'], $data['disk'],
            $data['endpoint'] ?? '', $data['region'] ?? '', $data['bucket'] ?? '',
            $data['access_key'] ?? '', $data['secret_key'] ?? '', (bool) ($data['path_style'] ?? false),
        );
    }

    private function connectionFailure(Throwable $exception): never
    {
        $reference = bin2hex(random_bytes(6));
        // Exception messages from database and S3 clients may contain credentials.
        Log::error('Installation operation failed', ['reference' => $reference, 'exception_type' => $exception::class, 'cause_type' => $exception->getPrevious() ? $exception->getPrevious()::class : null]);
        $message = $exception instanceof InstallationFailure
            ? $exception->getMessage()
            : 'Controleer database- en bestandsrechten. Hervat een onderbroken installatie met exact dezelfde instellingen.';
        throw ValidationException::withMessages([
            'installation' => 'Installatiecontrole mislukt (referentie '.$reference.'). '.$message.' Er is niets als voltooid gemarkeerd.',
        ]);
    }
}
