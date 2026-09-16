<?php

declare(strict_types=1);

return [
    /*
     * Locales that must carry an identical set of keys. Dutch is the reference
     * catalogue today; adding 'en', 'fr' or 'de' here is enough to make the
     * parity check demand a complete catalogue for that locale as well.
     */
    'locales' => ['nl'],

    'reference_locale' => 'nl',

    /*
     * Directories scanned for translation references, relative to the project
     * root. Everything that can call __(), trans(), trans_choice(), @lang() or
     * the Lang facade belongs here.
     */
    'scan_paths' => [
        'app',
        'config',
        'database',
        'resources/views',
        'routes',
    ],

    'scan_extensions' => ['php', 'blade.php'],

    /*
     * Fail-closed escape hatches. Both lists are explicit on purpose: a key that
     * is only reachable through a variable, and a catalogue key that is only
     * used from outside the scanned source, must be named here or the check
     * fails. Entries that no longer match anything are reported as stale, so the
     * allowlist cannot quietly outlive the code it was written for.
     */
    'allowlist' => [
        /*
         * Dynamic references: keys built at runtime. Each entry names the file
         * that builds them, the key patterns it can produce and why. The
         * patterns also mark those catalogue keys as used.
         *
         * ['file' => 'resources/views/x.blade.php', 'keys' => ['status.*'], 'reason' => '...'],
         */
        'dynamic' => [
            [
                'file' => 'app/Modules/Installation/InstallationText.php',
                'keys' => [],
                'reason' => 'This translation wrapper forwards keys whose literal call sites are scanned separately.',
            ],
            [
                'file' => 'app/Modules/Installation/InstallationPlatform.php',
                'keys' => [
                    'onboarding.setup.platform.purposes.pdo',
                    'onboarding.setup.platform.purposes.pdo_pgsql',
                    'onboarding.setup.platform.purposes.gd',
                    'onboarding.setup.platform.purposes.exif',
                    'onboarding.setup.platform.purposes.fileinfo',
                    'onboarding.setup.platform.purposes.intl',
                    'onboarding.setup.platform.purposes.mbstring',
                    'onboarding.setup.platform.purposes.openssl',
                    'onboarding.setup.platform.purposes.zip',
                ],
                'reason' => 'The required extension map selects one of these fixed purpose labels at runtime.',
            ],
        ],

        /*
         * Catalogue keys that may exist without a scanned reference, such as
         * framework validation messages. Supports a trailing '*' wildcard.
         */
        'unused' => [],
    ],
];
