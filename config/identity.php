<?php

declare(strict_types=1);

return [
    'invitation_ttl_minutes' => env('IDENTITY_INVITATION_TTL_MINUTES', 60 * 24 * 7),
    'webauthn_challenge_ttl_minutes' => env('IDENTITY_WEBAUTHN_CHALLENGE_TTL_MINUTES', 5),
    'recovery_code_count' => 10,
];
