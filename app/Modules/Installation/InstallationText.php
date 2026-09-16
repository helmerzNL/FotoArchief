<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use LogicException;

final class InstallationText
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        if (function_exists('app') && app()->bound('translator')) {
            return __($key, $replace);
        }

        $value = require dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'nl'.DIRECTORY_SEPARATOR.'onboarding.php';
        foreach (explode('.', substr($key, strlen('onboarding.'))) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new LogicException('Missing onboarding translation key: '.$key);
            }
            $value = $value[$segment];
        }
        if (! is_string($value)) {
            throw new LogicException('Onboarding translation key is not a string: '.$key);
        }

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, $replacement, $value);
        }

        return $value;
    }
}
