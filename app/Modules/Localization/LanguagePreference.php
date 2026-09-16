<?php

declare(strict_types=1);

namespace App\Modules\Localization;

use App\Models\User;
use Illuminate\Http\Request;

class LanguagePreference
{
    public function __construct(private readonly SupportedLocaleRegistry $locales) {}

    public function resolve(Request $request): string
    {
        $userLocale = $this->normalizeUserLocale($request->user());
        if ($userLocale !== null) {
            return $userLocale;
        }

        $session = $request->hasSession() ? $request->session() : null;
        if ($session !== null) {
            $sessionLocale = $this->locales->normalize($session->get($this->locales->sessionKey()));
            if ($sessionLocale !== null) {
                return $sessionLocale;
            }

            $session->forget($this->locales->sessionKey());
        }

        return $this->locales->defaultLocale();
    }

    public function rememberAnonymous(Request $request, mixed $locale): string
    {
        $supportedLocale = $this->locales->requireSupported($locale);
        $request->session()->put($this->locales->sessionKey(), $supportedLocale);

        return $supportedLocale;
    }

    public function persistForUser(User $user, mixed $locale): string
    {
        $supportedLocale = $this->locales->requireSupported($locale, 'preferred_locale');
        $user->forceFill(['preferred_locale' => $supportedLocale])->save();

        return $supportedLocale;
    }

    private function normalizeUserLocale(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return $this->locales->normalize($user->preferredLocale());
    }
}
