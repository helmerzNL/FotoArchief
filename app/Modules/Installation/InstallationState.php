<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use RuntimeException;
use stdClass;

final class InstallationState
{
    public function __construct(
        public string $id,
        public string $key,
        public string $codeHash,
        public string $phase = 'pending',
        public ?InstallationSettings $settings = null,
        public ?string $fingerprint = null,
    ) {}

    public static function decode(string $json): self
    {
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass) {
            throw new RuntimeException(InstallationText::get('onboarding.setup.errors.state_corrupt'));
        }
        foreach (['id', 'key', 'codeHash', 'phase'] as $field) {
            if (! isset($data->$field) || ! is_string($data->$field)) {
                throw new RuntimeException(InstallationText::get('onboarding.setup.errors.state_incomplete'));
            }
        }
        if (! in_array($data->phase, ['pending', 'installing', 'complete'], true)
            || ! str_starts_with($data->key, 'base64:')
            || strlen((string) base64_decode(substr($data->key, 7), true)) !== 32
            || ! preg_match('/^[a-f0-9]{64}$/', $data->codeHash)) {
            throw new RuntimeException(InstallationText::get('onboarding.setup.errors.state_invalid'));
        }
        $settings = isset($data->settings) && $data->settings instanceof stdClass
            ? InstallationSettings::fromObject($data->settings) : null;
        $fingerprint = isset($data->fingerprint) && is_string($data->fingerprint) ? $data->fingerprint : null;
        if ($data->phase !== 'pending' && ($settings === null || $fingerprint === null)) {
            throw new RuntimeException(InstallationText::get('onboarding.setup.errors.config_missing'));
        }

        return new self($data->id, $data->key, $data->codeHash, $data->phase, $settings, $fingerprint);
    }
}
