<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Inloggen - FotoArchief',
        'area' => 'Beheeromgeving',
        'heading' => 'Welkom terug',
        'intro' => 'Log in met je passkey, herstelcode of tijdelijke wachtwoord.',
        'passkey_button' => 'Inloggen met passkey',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'submit' => 'Inloggen',
        'recovery_heading' => 'Herstelcode gebruiken',
        'recovery_intro' => 'Gebruik alleen een herstelcode als je geen passkey of wachtwoord kunt gebruiken. De code wordt daarna ongeldig.',
        'recovery_code' => 'Herstelcode',
        'recovery_submit' => 'Inloggen met herstelcode',
        'passkey_messages' => [
            'unsupported' => 'Deze browser ondersteunt geen passkeys.',
            'started' => 'Passkey-aanvraag gestart...',
            'rejected' => 'De passkey is niet geaccepteerd.',
        ],
        'errors' => [
            'too_many_attempts' => 'Te veel inlogpogingen. Wacht een minuut.',
            'invalid' => 'De inloggegevens zijn niet geldig.',
            'session_revoked' => 'Je sessie is ingetrokken. Log opnieuw in of neem contact op met een beheerder.',
        ],
    ],
];
