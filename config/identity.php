<?php

declare(strict_types=1);

return [
    'invitation_ttl_minutes' => env('IDENTITY_INVITATION_TTL_MINUTES', 60 * 24 * 7),
];
