<?php

declare(strict_types=1);

namespace App\Modules\Localization;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use LogicException;

class SupportedLocaleRegistry
{
    public function defaultLocale(): string
    {
        return $this->requireConfiguredLocale(
            config('localization.default_locale', 'nl'),
            __('shared.generated.t_32f57484e141bb43'),
        );
    }

    public function fallbackLocale(): string
    {
        return $this->requireConfiguredLocale(
            config('localization.fallback_locale', $this->defaultLocale()),
            __('shared.generated.t_dd68529520d6a493'),
        );
    }

    public function sessionKey(): string
    {
        return (string) config('localization.session_key', 'localization.locale_preference');
    }

    /**
     * @return list<string>
     */
    public function supportedLocaleCodes(): array
    {
        return array_map(
            static fn (int|string $locale): string => (string) $locale,
            array_keys((array) config('localization.supported_locales', ['nl' => []])),
        );
    }

    public function normalize(mixed $locale): ?string
    {
        if (! is_string($locale)) {
            return null;
        }

        $normalized = strtolower(trim($locale));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, $this->supportedLocaleCodes(), true) ? $normalized : null;
    }

    public function requireSupported(mixed $locale, string $field = 'locale'): string
    {
        $normalized = $this->normalize($locale);
        if ($normalized !== null) {
            return $normalized;
        }

        throw ValidationException::withMessages([
            $field => __('shared.generated.t_26e5295c17c52120'),
        ]);
    }

    public function validationRule(): In
    {
        return Rule::in($this->supportedLocaleCodes());
    }

    private function requireConfiguredLocale(mixed $locale, string $message): string
    {
        $normalized = $this->normalize($locale);
        if ($normalized === null) {
            throw new LogicException($message);
        }

        return $normalized;
    }
}
