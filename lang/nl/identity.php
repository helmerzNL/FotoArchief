<?php

declare(strict_types=1);

return [
    'common' => [
        'name' => 'Naam',
        'email' => 'E-mailadres',
        'roles' => 'Rollen',
        'role_key' => '(:key)',
    ],
    'invitations' => [
        'accept' => [
            'title' => 'Uitnodiging accepteren - FotoArchief',
            'eyebrow' => 'Uitnodiging',
            'heading' => 'Account activeren',
            'account_for' => 'Je activeert een account voor',
            'expires_at' => 'Deze link kan één keer worden gebruikt en verloopt op :expires_at.',
            'password' => 'Tijdelijk wachtwoord',
            'password_confirmation' => 'Herhaal wachtwoord',
            'submit' => 'Account activeren',
        ],
        'create' => [
            'title' => 'Uitnodigen - FotoArchief',
            'eyebrow' => 'Geen mailprovider',
            'heading' => 'Gebruiker uitnodigen',
            'intro' => 'Maak een eenmalige link. De link wordt alleen direct na aanmaken aan jou getoond.',
            'submit' => 'Uitnodigingslink maken',
        ],
    ],
    'security' => [
        'title' => 'Beveiliging - FotoArchief',
        'eyebrow' => 'Accountbeveiliging',
        'heading' => 'Passkeys en herstelcodes',
        'intro' => 'Registreer een passkey voor veilig inloggen zonder wachtwoord. Bewaar herstelcodes offline; elke code werkt één keer.',
        'passkey_name' => 'Naam voor deze passkey',
        'default_passkey_name' => 'Mijn apparaat',
        'register_passkey' => 'Passkey registreren',
        'registered_passkeys' => 'Geregistreerde passkeys',
        'last_used' => 'Laatst gebruikt: :date',
        'never_used' => 'nog niet',
        'remove' => 'Verwijderen',
        'no_passkeys' => 'Er zijn nog geen passkeys geregistreerd.',
        'recovery_codes' => 'Herstelcodes',
        'available_recovery_codes' => 'Beschikbare ongebruikte codes: :count. Nieuwe codes vervangen alle bestaande codes.',
        'regenerate_recovery_codes' => 'Nieuwe herstelcodes maken',
        'passkey_messages' => [
            'unsupported' => 'Deze browser ondersteunt geen passkeys.',
            'started' => 'Passkey-registratie gestart...',
            'failed' => 'De passkey kon niet worden geregistreerd.',
            'default_name' => 'Passkey',
        ],
    ],
    'users' => [
        'title' => 'Identiteit - FotoArchief',
        'eyebrow' => 'Identiteit en toegang',
        'heading' => 'Gebruikers',
        'intro' => 'Beheer rollen, trek sessies direct in of deactiveer accounts. Minstens één actieve beheerder blijft verplicht.',
        'new_invitation' => 'Nieuwe uitnodiging',
        'active' => 'Actief',
        'deactivated' => 'Gedeactiveerd',
        'save_and_revoke_sessions' => 'Opslaan en sessies intrekken',
        'deactivate' => 'Deactiveren',
        'reactivate' => 'Heractiveren',
    ],
];
