<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * The interface choices a person makes once and expects on every device: theme,
 * accent colour and whether the sidebar is collapsed.
 *
 * Only known keys with known values are ever stored or returned, so the client can
 * trust what it reads and a stale or tampered value cannot reach the page.
 */
final class InterfacePreferences
{
    /** @var array<string, list<string>> */
    public const OPTIONS = [
        'theme' => ['system', 'light', 'dark'],
        'accent' => ['groen', 'terracotta', 'blauw', 'pruim', 'oker'],
        'sidebar' => ['expanded', 'collapsed'],
    ];

    /**
     * The valid stored choices; keys the person never chose are absent.
     *
     * @return array<string, string>
     */
    public static function for(User $user): array
    {
        $stored = $user->interface_preferences;

        return is_array($stored) ? self::sanitise($stored) : [];
    }

    /**
     * Validation rules for a partial update (any subset of the keys).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::OPTIONS as $key => $values) {
            $rules[$key] = ['sometimes', 'string', Rule::in($values)];
        }

        return $rules;
    }

    /**
     * Merges validated changes into the stored choices and saves them.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    public static function update(User $user, array $changes): array
    {
        $merged = self::sanitise(array_replace(self::for($user), self::sanitise($changes)));
        $user->forceFill(['interface_preferences' => $merged])->save();

        return $merged;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function sanitise(array $values): array
    {
        $clean = [];
        foreach (self::OPTIONS as $key => $allowed) {
            $value = $values[$key] ?? null;
            if (is_string($value) && in_array($value, $allowed, true)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
