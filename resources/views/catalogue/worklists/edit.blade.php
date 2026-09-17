@extends('layouts.app')
@section('title', 'Werklijst bewerken: ' . $worklist->title . ' - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.worklists.show', $worklist) }}">{{ __('catalogue.generated.t_98543cab14630d77') }}</a></p>
<h1>{{ __('catalogue.generated.t_06faef7436924547') }} {{ $worklist->title }}</h1>

@if($errors->any())
    <div class="card" style="border-color: #b91c1c; background-color: #fef2f2;">
        <h2>Invoerfouten</h2>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="post" action="{{ route('catalogue.worklists.update', $worklist) }}">
    @csrf
    @method('put')
    <section class="card">
        <div class="field">
            <label for="title">{{ __('catalogue.generated.t_a1710a47def742fc') }}</label>
            <input type="text" id="title" name="title" value="{{ old('title', $worklist->title) }}" required maxlength="255">
        </div>

        <div class="grid">
            <div>
                <label for="status">{{ __('catalogue.generated.t_0c96f85b407c44d9') }}</label>
                <select id="status" name="status" required>
                    <option value="active" {{ old('status', $worklist->status) === 'active' ? 'selected' : '' }}>{{ __('catalogue.generated.t_79ef6839808fec2d') }}</option>
                    <option value="in_progress" {{ old('status', $worklist->status) === 'in_progress' ? 'selected' : '' }}>{{ __('catalogue.generated.t_4076298f63065e81') }}</option>
                    <option value="completed" {{ old('status', $worklist->status) === 'completed' ? 'selected' : '' }}>{{ __('catalogue.generated.t_f370e13e63f29dbf') }}</option>
                    <option value="archived" {{ old('status', $worklist->status) === 'archived' ? 'selected' : '' }}>{{ __('catalogue.generated.t_901f00339deb75ff') }}</option>
                </select>
            </div>
            <div>
                <label for="assigned_to_user_id">{{ __('catalogue.generated.t_55ae154f87892bc7') }}</label>
                <select id="assigned_to_user_id" name="assigned_to_user_id">
                    <option value="">{{ __('catalogue.generated.t_86274edafc6b08c0') }}</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ old('assigned_to_user_id', $worklist->assigned_to_user_id) == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="field">
            <label for="description">{{ __('catalogue.generated.t_2b33f1edf6e17a27') }}</label>
            <textarea id="description" name="description">{{ old('description', $worklist->description) }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.worklists.show', $worklist) }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>

<section class="card">
    <h2>{{ __('catalogue.generated.t_0c3fee1ce16c1371') }}</h2>
    <form method="post" action="{{ route('catalogue.worklists.destroy', $worklist) }}">
        @csrf
        @method('delete')
        <button type="submit" class="button secondary" style="color: #b91c1c;" onclick="return confirm('Weet je zeker dat je deze werklijst wilt verwijderen?')">{{ __('catalogue.generated.t_0c3fee1ce16c1371') }}</button>
    </form>
</section>
@endsection
