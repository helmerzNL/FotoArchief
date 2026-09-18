<?php

declare(strict_types=1);

return [
    'filters' => 'Reviewwerkvoorraad filteren',
    'status' => 'Beoordelingsstatus',
    'collection' => 'Collectie',
    'all' => 'Alle',
    'provider' => 'Provideridentificatie',
    'from' => 'Vanaf datum',
    'until' => 'Tot en met datum',
    'apply' => 'Filters toepassen',
    'states' => ['reviewable' => 'Te beoordelen', 'pending' => 'Openstaand', 'accepted' => 'Geaccepteerd', 'rejected' => 'Afgewezen', 'superseded' => 'Verouderd', 'reverted' => 'Teruggedraaid'],
    'current' => 'Huidige metadata',
    'proposed' => 'Oorspronkelijk AI-voorstel',
    'description' => 'Te accepteren beschrijving (vervangt de huidige beschrijving)',
    'tag_effect' => 'Deze tag wordt alleen toegevoegd als deze nog niet gekoppeld is. Bestaande tags blijven behouden.',
    'select' => 'Voorstel :id selecteren',
    'confirm' => 'Ik bevestig de geselecteerde voorstellen en beslissing.',
    'bulk' => 'Toepassen op geselecteerde voorstellen',
    'decision' => 'Beslissing',
    'processed' => 'Beslissing opgeslagen.',
    'results' => 'Resultaten per voorstel',
    'undo' => 'Mijn acceptatie terugdraaien',
    'undone' => 'Acceptatie teruggedraaid; het oorspronkelijke voorstel en auditlog blijven bewaard.',
    'undo_unavailable' => 'Alleen eigen geaccepteerde voorstellen met een herstelbewijs kunnen worden teruggedraaid.',
    'undo_conflict' => 'De foto is na acceptatie gewijzigd. Er is geen metadata overschreven.',
    'invalid_description' => 'Vul een beschrijving van 1 tot 10000 tekens in.',
    'invalid_tag' => 'Deze tag levert geen geldige identificatie op.',
];
