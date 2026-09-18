@extends('layouts.app')
@section('title', 'Batch-bewerking bevestigen - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('admin.assets.index') }}">{{ __('catalogue.generated.t_65f8a04d2b51e459') }}</a></p>
<h1>{{ __('catalogue.generated.t_2d4e35046e927ab6') }}{{ $assets->count() }} geselecteerd)</h1>

@if($errors->any())
    <div class="card" style="border-color: #b91c1c; background-color: #fef2f2;">
        <h2>{{ __('catalogue.generated.t_ee8eee677f2e1cf3') }}</h2>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<p>{{ __('daily.bulk_hint') }}</p>
<form method="post" action="{{ route('catalogue.bulk.preview') }}">
    @csrf

    <section class="card">
        <h2>{{ __('catalogue.generated.t_78f0e940902276ce') }}</h2>
        <p>{{ __('catalogue.generated.t_c8b6e5e84cbdbcf2') }}</p>
        <ul class="asset-list">
            @foreach($assets as $asset)
                <li>
                    <strong>{{ $asset->title ?: $asset->accession_number }}</strong>
                    <small>
                        {{ $asset->accession_number }} {{ __('catalogue.generated.t_34ba21340c9968af') }} {{ $asset->catalogue_status }} {{ __('catalogue.generated.t_2bff4f1da3ccc83d') }} {{ $asset->lock_version }}
                        @if($asset->tags->isNotEmpty()) {{ __('catalogue.generated.t_ea0ad7387f9ad8ff') }} {{ $asset->tags->pluck('name')->join(', ') }} @endif
                    </small>
                    <input type="hidden" name="asset_ids[]" value="{{ $asset->id }}">
                    <input type="hidden" name="lock_versions[{{ $asset->id }}]" value="{{ $asset->lock_version }}">
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card">
        <h2>{{ __('catalogue.generated.t_edf0cac4b05289f5') }}</h2>
        <div class="field">
            <label for="tags_to_add">{{ __('catalogue.generated.t_e79e63c015e2b7d0') }}</label>
            <input type="text" id="tags_to_add" name="tags_to_add" placeholder="{{ __('catalogue.generated.t_0897de2ccf92dfc4') }}" value="{{ old('tags_to_add') }}">
        </div>
        @if($tags->isNotEmpty())
            <div class="field">
                <label>{{ __('catalogue.generated.t_f04eb9e77224112a') }}</label>
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
        <h2>{{ __('catalogue.generated.t_ed73d612d15290e4') }}</h2>
        <div class="grid">
            <div>
                <label for="collection_id_to_add">{{ __('catalogue.generated.t_19717475b3a29a76') }}</label>
                <select id="collection_id_to_add" name="collection_id_to_add">
                    <option value="">{{ __('catalogue.generated.t_735f984e26fbeeb8') }}</option>
                    @foreach($collections as $col)
                        <option value="{{ $col->id }}" {{ old('collection_id_to_add') === $col->id ? 'selected' : '' }}>{{ $col->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="collection_id_to_remove">{{ __('catalogue.generated.t_f5fd03fa83105a86') }}</label>
                <select id="collection_id_to_remove" name="collection_id_to_remove">
                    <option value="">{{ __('catalogue.generated.t_23bd85a9b32fde48') }}</option>
                    @foreach($collections as $col)
                        <option value="{{ $col->id }}" {{ old('collection_id_to_remove') === $col->id ? 'selected' : '' }}>{{ $col->title }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>{{ __('catalogue.generated.t_7629d04c7ecb9704') }}</h2>
        <label>
            <input type="checkbox" name="update_rights" value="1" {{ old('update_rights') ? 'checked' : '' }}>
            {{ __('catalogue.generated.t_50190a3c8df713d7') }}
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
        <h2>{{ __('catalogue.generated.t_a3b60f37daffade9') }}</h2>
        <label>
            <input type="checkbox" name="update_dates" value="1" {{ old('update_dates') ? 'checked' : '' }}>
            {{ __('catalogue.generated.t_fa3024c0c8d161e9') }}
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
                <input type="text" id="date_display" name="date_display" value="{{ old('date_display') }}" placeholder="{{ __('catalogue.generated.t_db30453be8fad24f') }}">
            </div>
        </div>
        <div class="grid">
            <div>
                <label for="date_earliest">{{ __('catalogue.generated.t_119ccc61ca53846d') }}</label>
                <input type="date" id="date_earliest" name="date_earliest" value="{{ old('date_earliest') }}">
            </div>
            <div>
                <label for="date_latest">{{ __('catalogue.generated.t_1413312e7df2bedd') }}</label>
                <input type="date" id="date_latest" name="date_latest" value="{{ old('date_latest') }}">
            </div>
        </div>

        <label style="margin-top: 1rem; display: block;">
            <input type="checkbox" name="update_status" value="1" {{ old('update_status') ? 'checked' : '' }}>
            {{ __('catalogue.generated.t_8a22e1b808cce8d0') }}
        </label>
        <div class="field">
            <label for="catalogue_status">Status</label>
            <select id="catalogue_status" name="catalogue_status">
                <option value="draft">{{ __('catalogue.generated.t_eb3d3711e6f803f9') }}</option>
                <option value="under_review">{{ __('catalogue.generated.t_928692b3d39adb88') }}</option>
                <option value="catalogued">{{ __('catalogue.generated.t_8611ae4f1ce07ab9') }}</option>
            </select>
        </div>
    </section>

    <div class="actions">
        <button type="submit">{{ __('daily.preview') }}</button>
        <a href="{{ route('admin.assets.index') }}" class="button secondary">Annuleren</a>
    </div>
</form>
@endsection
