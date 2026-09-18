<?php

declare(strict_types=1);

return [
    'title' => 'Zoekindex beheren', 'coverage' => 'Indexdekking', 'space' => 'Provider / gevraagd model / actieve modelruimte',
    'current' => 'Actueel', 'stale' => 'Verouderd', 'missing' => 'Ontbreekt', 'excluded' => 'Uitgesloten', 'failed' => 'Mislukt',
    'coverage_notice' => 'Alleen schone, verwerkte primaire bestanden komen in aanmerking. Actueel betekent een bruikbare vector in de actieve generatie voor het ingestelde model. Ontbrekende/verouderde items zijn gericht herstelbaar; mislukte items via hun taakdetails.',
    'select' => 'Voor herstel selecteren',
    'confirm' => 'Ik bevestig verwerking van deze selectie binnen de gekozen collectie met de ingestelde provider. Eventuele providerkosten gelden.',
    'repair' => 'Geselecteerde indexitems herstellen',
    'changed' => 'De actieve generatie of modelconfiguratie is gewijzigd. Ververs de pagina voordat u herstel start.',
    'selection_changed' => 'De selectie bevat niet uitsluitend ontbrekende of verouderde items uit deze collectie.',
    'generations' => 'Generaties en omschakeling', 'activate' => 'Omschakeling opnieuw proberen',
    'confirm_activation' => 'Ik bevestig omschakeling; bron- en generatiecontroles blijven verplicht.',
    'activated' => 'Generatie veilig geactiveerd.',
    'mode' => 'Zoekmethode', 'text' => 'Tekst in catalogusmetadata', 'semantic' => 'Semantische gelijkenis',
    'search_notice' => 'Tekstzoeken gebruikt opgeslagen metadata zonder AI-verzoek. Semantisch zoeken gebruikt uitsluitend de ingestelde provider en actieve modelruimte. Geen automatische fallback naar een externe dienst.',
    'empty' => 'Geen resultaten voor deze zoekopdracht en collectie. Pas de filters aan of probeer expliciet tekstzoeken.',
    'not_searched' => 'Vul een zoekopdracht in om te zoeken.',
    'label' => 'Relevantie beoordelen', 'irrelevant' => 'Niet relevant', 'partial' => 'Gedeeltelijk relevant', 'relevant' => 'Relevant',
    'label_saved' => 'Uw relevantiebeoordeling is opgeslagen.',
    'invalid_receipt' => 'Dit zoekresultaat is verlopen of ongeldig. Zoek opnieuw voordat u beoordeelt.',
    'export_labels' => 'Mijn relevantielabels exporteren (JSONL)',
    'labels_notice' => 'De export bevat uw zoekteksten, foto-ID, modelruimte en beoordeling. Dit is een interne beoordelingsset, geen objectief kwaliteitsbewijs.',
];
