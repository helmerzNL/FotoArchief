<?php

declare(strict_types=1);

return [
    'detail' => 'Taakdetails',
    'timings' => 'Aangemaakt / laatste poging gestart / afgerond',
    'attempts' => 'Workerclaims (inclusief vervolgbatches)',
    'settings' => 'Opgeslagen taakinstellingen',
    'items' => 'Fotoselectie en laatste bekende uitkomst',
    'processed' => 'Verwerkt', 'failed' => 'Mislukt', 'waiting' => 'Wachtend', 'skipped' => 'Overgeslagen',
    'not_processed' => 'Nog geen verwerkingsresultaat geregistreerd.',
    'cancelled_item' => 'Niet verwerkt: de taak is geannuleerd.',
    'no_selection' => 'Deze taak heeft geen opgeslagen AI-fotoselectie. Raadpleeg de taakinstellingen en gebeurtenissen.',
    'pause' => 'Pauzeren', 'resume' => 'Hervatten', 'paused' => 'Gepauzeerd',
    'pausing' => 'Pauze aangevraagd; lopend werk wordt eerst afgerond.',
    'pause_notice' => 'AI en integriteitscontrole pauzeren voor de volgende foto. Opslagkopie pauzeert na de huidige batch van maximaal 25 bestanden. Een lopend verzoek wordt niet afgebroken. Een volledig afgeronde taak blijft voltooid.',
    'control_saved' => 'Taakbesturing opgeslagen.',
    'select_failed' => 'Dit mislukte item selecteren',
    'confirm_retry' => 'Ik bevestig een nieuwe taak voor uitsluitend de geselecteerde mislukte items; eventuele providerkosten gelden opnieuw.',
    'retry_selected' => 'Geselecteerde fouten opnieuw proberen',
    'retry_saved' => 'Een vervolgtaak is aangemaakt; de oorspronkelijke taak en historie blijven bewaard.',
    'only_failed' => 'Selecteer uitsluitend items waarvan de laatste uitkomst in deze taak mislukt is.',
    'already_retried' => 'Deze selectie bevat al opnieuw gestarte items. Gebruik de vervolgtaak voor een volgende poging.',
    'audit' => 'Doorzoekbaar auditlog',
    'asset' => 'Foto-ID of archiefnummer', 'run' => 'Taak-ID', 'actor' => 'Gebruiker-ID', 'event' => 'Exact gebeurtenistype',
    'export' => 'JSONL exporteren',
    'export_notice' => 'Maximaal 10000 gebeurtenissen. Export bevat uitsluitend identificaties, gebeurtenistype en tijdstip; geen vrije tekst, technische context, providersleutels of fotometadata.',
    'export_limit' => 'Meer dan 10000 gebeurtenissen: beperk eerst de filters.',
];
