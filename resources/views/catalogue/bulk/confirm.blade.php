@extends('layouts.app')
@section('title', 'Batch-bewerking bevestigen - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('admin.assets.index') }}">← Terug naar foto-overzicht</a></p>
<h1>Batch-bewerking foto’s ({{ $assets->count() }} geselecteerd)</h1>

@if($errors->any())
    <div class="card" style="border-color: #b91c1c; background-color: #fef2f2;">
        <h2>Fout bij verwerking</h2>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="post" action="{{ route('catalogue.bulk.apply') }}">
    @csrf

    <section class="card">
        <h2>Geselecteerde foto’s &amp; status</h2>
        <p>Elke foto wordt beschermd tegen gelijktijdige bewerking via een versieslot.</p>
        <ul class="asset-list">
            @foreach($assets as $asset)
                <li>
                    <strong>{{ $asset->title ?: $asset->accession_number }}</strong>
                    <small>
                        {{ $asset->accession_number }} · Status: {{ $asset->catalogue_status }} · Versie: {{ $asset->lock_version }}
                        @if($asset->tags->isNotEmpty()) · Tags: {{ $asset->tags->pluck('name')->join(', ') }} @endif
                    </small>
                    <input type="hidden" name="asset_ids[]" value="{{ $asset->id }}">
                    <input type="hidden" name="lock_versions[{{ $asset->id }}]" value="{{ $asset->lock_version }}">
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card">
        <h2>Tags &amp; Trefwoorden</h2>
        <div class="field">
            <label for="tags_to_add">Tags toevoegen aan alle geselecteerde foto’s (komma-gescheiden)</label>
            <input type="text" id="tags_to_add" name="tags_to_add" placeholder="bijv. monument, straatbeeld, restauratie" value="{{ old('tags_to_add') }}">
        </div>
        @if($tags->isNotEmpty())
            <div class="field">
                <label>Tags verwijderen van geselecteerde foto’s (optioneel):</label>
                <div class="grid">
                    @foreach($tags as $t)
                        <label>
                            <input type="checkbox" name="tags_to_remove[]" value="{{ $t->id }}">
                            {{ $t->name }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    <section class="card">
        <h2>Collectie / Album koppeling</h2>
        <div class="grid">
            <div>
                <label for="collection_id_to_add">Toevoegen aan collectie / album</label>
                <select id="collection_id_to_add" name="collection_id_to_add">
                    <option value="">-- Geen collectie toevoegen --</option>
                    @foreach($collections as $col)
                        <option value="{{ $col->id }}" {{ old('collection_id_to_add') === $col->id ? 'selected' : '' }}>{{ $col->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="collection_id_to_remove">Verwijderen uit collectie / album</label>
                <select id="collection_id_to_remove" name="collection_id_to_remove">
                    <option value="">-- Geen collectie verwijderen --</option>
                    @foreach($collections as $col)
                        <option value="{{ $col->id }}" {{ old('collection_id_to_remove') === $col->id ? 'selected' : '' }}>{{ $col->title }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Rechten &amp; Verificatie</h2>
        <label>
            <input type="checkbox" name="update_rights" value="1" {{ old('update_rights') ? 'checked' : '' }}>
            Rechtenstatus bijwerken voor alle geselecteerde foto’s
        </label>
        <div class="grid">
            <div>
                <label for="rights_status">Rechtenstatus</label>
                <select id="rights_status" name="rights_status">
                    <option value="unverified" {{ old('rights_status') === 'unverified' ? 'selected' : '' }}>Ongeverifieerd</option>
                    <option value="verified" {{ old('rights_status') === 'verified' ? 'selected' : '' }}>Geverifieerd</option>
                    <option value="disputed" {{ old('rights_status') === 'disputed' ? 'selected' : '' }}>Betwist</option>
                </select>
            </div>
            <div>
                <label for="rights_holder">Rechthebbende</label>
                <input type="text" id="rights_holder" name="rights_holder" value="{{ old('rights_holder') }}">
            </div>
        </div>
        <div class="field">
            <label for="rights_note">Rechtennotitie</label>
            <textarea id="rights_note" name="rights_note">{{ old('rights_note') }}</textarea>
        </div>
    </section>

    <section class="card">
        <h2>Datering &amp; Catalogusstatus</h2>
        <label>
            <input type="checkbox" name="update_dates" value="1" {{ old('update_dates') ? 'checked' : '' }}>
            Datering bijwerken
        </label>
        <div class="grid">
            <div>
                <label for="date_precision">Precisie</label>
                <select id="date_precision" name="date_precision">
                    <option value="unknown">Onbekend</option>
                    <option value="exact">Exact</option>
                    <option value="year">Jaar</option>
                    <option value="decade">Decennium</option>
                </select>
            </div>
            <div>
                <label for="date_display">Weergavedatum</label>
                <input type="text" id="date_display" name="date_display" value="{{ old('date_display') }}" placeholder="bijv. ca. 1930">
            </div>
        </div>
        <div class="grid">
            <div>
                <label for="date_earliest">Datum vroegst</label>
                <input type="date" id="date_earliest" name="date_earliest" value="{{ old('date_earliest') }}">
            </div>
            <div>
                <label for="date_latest">Datum laatst</label>
                <input type="date" id="date_latest" name="date_latest" value="{{ old('date_latest') }}">
            </div>
        </div>

        <label style="margin-top: 1rem; display: block;">
            <input type="checkbox" name="update_status" value="1" {{ old('update_status') ? 'checked' : '' }}>
            Catalogusstatus bijwerken
        </label>
        <div class="field">
            <label for="catalogue_status">Status</label>
            <select id="catalogue_status" name="catalogue_status">
                <option value="draft">Concept (draft)</option>
                <option value="under_review">In beoordeling (under_review)</option>
                <option value="catalogued">Gecatalogiseerd (catalogued)</option>
            </select>
        </div>
    </section>

    <div class="actions">
        <button type="submit">Batch-wijzigingen toepassen</button>
        <a href="{{ route('admin.assets.index') }}" class="button secondary">Annuleren</a>
    </div>
</form>
@endsection
