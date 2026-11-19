<?php

declare(strict_types=1);

return [
    'invitation_ttl_minutes' => env('IDENTITY_INVITATION_TTL_MINUTES', 60 * 24 * 7),
    'webauthn_challenge_ttl_minutes' => env('IDENTITY_WEBAUTHN_CHALLENGE_TTL_MINUTES', 5),
    'recovery_code_count' => 10,
    // Caps from App-Guidance user-management.md: active users plus pending new-account
    // invitations, and open invitations of either kind.
    'max_users' => (int) env('IDENTITY_MAX_USERS', 50),
    'max_open_invitations' => (int) env('IDENTITY_MAX_OPEN_INVITATIONS', 50),
];
