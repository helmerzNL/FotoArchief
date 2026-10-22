<section class="card">
    <h2>{{ __('daily.quality') }}</h2>
    <ul class="chips">
        <li><a class="chip" href="{{ route('admin.assets.index', ['missing' => 'description']) }}"@if(request('missing') === 'description') aria-current="true"@endif>{{ __('daily.description_missing') }}</a></li>
        <li><a class="chip" href="{{ route('admin.assets.index', ['missing' => 'dating']) }}"@if(request('missing') === 'dating') aria-current="true"@endif>{{ __('daily.dating_missing') }}</a></li>
        <li><a class="chip" href="{{ route('admin.assets.index', ['missing' => 'collection']) }}"@if(request('missing') === 'collection') aria-current="true"@endif>{{ __('daily.collection_missing') }}</a></li>
        <li><a class="chip" href="{{ route('admin.assets.index', ['missing' => 'rights']) }}"@if(request('missing') === 'rights') aria-current="true"@endif>{{ __('daily.rights_missing') }}</a></li>
    </ul>
    <details class="saved-searches">
    <summary>{{ __('daily.searches') }}</summary>
    <form method="post" action="{{ route('catalogue.searches.store') }}">
        @csrf
        <input type="hidden" name="filters[q]" value="{{ $searchFilters['q'] ?? '' }}">
        @foreach($searchFilters as $key => $value)
            @if($key !== 'q')
                <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
            @endif
        @endforeach
        <label for="saved-search-name">{{ __('daily.search_name') }}</label>
        <input id="saved-search-name" name="name" maxlength="100" required>
        <button>{{ __('daily.save_search') }}</button>
    </form>
    <ul>
        @foreach($savedSearches as $search)
            <li>
                <a href="{{ route('catalogue.searches.run', $search) }}">{{ $search->name }}</a>
                <form method="post" action="{{ route('catalogue.searches.destroy', $search) }}">
                    @csrf
                    @method('DELETE')
                    <label><input type="checkbox" name="confirm" value="1" required> {{ __('daily.confirm') }}</label>
                    <button class="secondary">{{ __('daily.delete') }}</button>
                </form>
            </li>
        @endforeach
    </ul>
    </details>
</section>
