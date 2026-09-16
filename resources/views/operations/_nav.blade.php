{{--
    Navigation for every archive operation.

    The global header carries a single "Operaties" link into diagnostics; without
    this partial the other seven features have no route a user could discover, and
    an operator would have to know the URLs by heart. Each entry is rendered only
    when the signed-in user may actually open it, so the menu never advertises a
    page that answers 403.
--}}
@php
    $operationsNavItems = [
        ['route' => 'admin.operations.diagnostics', 'pattern' => 'admin.operations.diagnostics', 'label' => 'Diagnose', 'abilities' => ['users.manage', 'audit.view']],
        ['route' => 'admin.operations.duplicates.index', 'pattern' => 'admin.operations.duplicates.*', 'label' => 'Duplicaten', 'abilities' => ['assets.update', 'catalogue.manage']],
        ['route' => 'admin.operations.processing.index', 'pattern' => 'admin.operations.processing.*', 'label' => 'Verwerking', 'abilities' => ['assets.update', 'catalogue.manage', 'users.manage']],
        ['route' => 'admin.operations.integrity.index', 'pattern' => 'admin.operations.integrity.*', 'label' => 'Integriteit', 'abilities' => ['assets.update', 'catalogue.manage', 'users.manage']],
        ['route' => 'admin.operations.storage.index', 'pattern' => 'admin.operations.storage.*', 'label' => 'Opslagmigratie', 'abilities' => ['users.manage', 'catalogue.manage']],
        ['route' => 'admin.operations.trash.index', 'pattern' => 'admin.operations.trash.*', 'label' => 'Prullenbak', 'abilities' => ['catalogue.manage', 'users.manage']],
        ['route' => 'admin.operations.ocr.index', 'pattern' => 'admin.operations.ocr.*', 'label' => 'OCR-tekst', 'abilities' => ['catalogue.manage', 'users.manage', 'assets.view']],
        ['route' => 'admin.operations.runs.index', 'pattern' => 'admin.operations.runs.*', 'label' => 'Achtergrondtaken', 'abilities' => ['users.manage', 'catalogue.manage', 'assets.update']],
        ['route' => 'admin.assets.index', 'pattern' => 'admin.operations.versions.*', 'label' => 'Bestandsversies per foto', 'abilities' => ['assets.view']],
    ];
@endphp
<nav aria-label="Archiefbewerkingen" style="margin: 0 0 2rem;">
    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 0.5rem;">
        @foreach($operationsNavItems as $item)
            @canany($item['abilities'])
                @php($isCurrent = request()->routeIs($item['pattern']))
                <li style="max-width: 100%;">
                    <a href="{{ route($item['route']) }}"
                       @if($isCurrent) aria-current="page" @endif
                       style="display: inline-block; padding: 0.45rem 0.85rem; border: 1px solid var(--border); border-radius: 2rem; font-size: 0.9rem; font-weight: 650; text-decoration: none; overflow-wrap: anywhere; {{ $isCurrent ? 'background: var(--accent); color: var(--surface); border-color: var(--accent);' : '' }}">
                        {{ $item['label'] }}
                    </a>
                </li>
            @endcanany
        @endforeach
    </ul>
</nav>
