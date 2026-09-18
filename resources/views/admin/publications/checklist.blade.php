<section class="card">
    <h2>{{ __('publishwork.checklist') }}</h2>
    <p>{{ __('publishwork.embargo_hint') }}</p>
    <ul>
        @foreach($checks as $key => $passed)
            <li>{{ __('publishwork.checks')[$key] }}: <strong>{{ $passed ? __('publishwork.pass') : __('publishwork.block') }}</strong></li>
        @endforeach
    </ul>
</section>
