<?php

declare(strict_types=1);

return [
    'public' => [
        'label' => 'Zoeken op beeldinhoud',
        'placeholder' => 'bijvoorbeeld: kinderen bij een molen',
        'notice' => 'Semantisch zoeken stuurt je zoektekst naar een externe AI-provider om te vergelijken met beeldbeschrijvingen. Zonder toestemming hieronder wordt alleen op titel/beschrijving gezocht.',
        'consent' => 'Ik geef toestemming om deze zoektekst naar de AI-provider te sturen voor semantisch zoeken.',
        'submit' => 'Semantisch zoeken',
        'unavailable' => 'Semantisch zoeken is nu niet beschikbaar: :error',
        'consent_required' => 'Geef eerst toestemming om je zoektekst naar een AI-provider te sturen voor semantisch zoeken.',
    ],
    'search' => [
        'title' => 'AI semantisch zoeken',
        'intro' => 'Zoekt met een tekstembedding in hetzelfde model_space als de beeldindex. Resultaten blijven beperkt tot assets die u mag zien.',
        'query' => 'Zoekvraag',
        'placeholder' => 'bijvoorbeeld: groepsfoto op dorpsplein',
        'provider_before' => 'Provider: :provider (ingesteld via ',
        'provider_after' => '; hier niet los te kiezen zodat tekst- en beeldembeddings altijd dezelfde modelruimte gebruiken).',
        'unconfigured' => 'niet geconfigureerd',
        'settings' => 'AI-instellingen',
        'submit' => 'Zoeken',
        'not_ready' => 'Embeddings-provider is niet gereed (toestemming, model of budget ontbreekt) — configureer deze eerst.',
        'failed' => 'Zoeken mislukt.',
        'results' => 'Resultaten',
        'empty' => 'Geen semantische resultaten.',
        'score' => 'Score :score · :space',
    ],
    'suggestions' => [
        'title' => 'AI-suggesties beoordelen',
        'intro' => 'Suggesties wijzigen niets totdat een bevoegde gebruiker ze accepteert. Controleer bron, context en lock-versie.',
        'failed' => 'Beoordeling mislukt.',
        'open' => 'Open suggesties',
        'source' => 'Bronversie :version · checksum :checksum',
        'accept' => 'Accepteren',
        'reject' => 'Afwijzen',
        'note_placeholder' => 'Optionele afwijsreden',
        'empty' => 'Er staan geen AI-suggesties klaar voor beoordeling.',
        'accepted' => 'AI-suggestie geaccepteerd en als metadatawijziging opgeslagen.',
        'rejected' => 'AI-suggestie afgewezen zonder metadata te wijzigen.',
    ],
];
