{{-- Appearance controls: theme (Systeem/Licht/Donker) and accent colour. JavaScript reveals them; without it the light default stays. --}}
<div id="theme-control" class="theme-control" hidden>
    <label for="theme-preference">{{ __('shell.theme.label') }}</label>
    <select id="theme-preference">
        <option value="system">{{ __('shell.theme.system') }}</option>
        <option value="light">{{ __('shell.theme.light') }}</option>
        <option value="dark">{{ __('shell.theme.dark') }}</option>
    </select>
    <p id="theme-status" role="status" data-storage-error="{{ __('shell.theme.storage_error') }}"></p>
    <fieldset class="accent-choices">
        <legend>{{ __('shell.accent.label') }}</legend>
        <button type="button" class="quiet swatch-button" data-accent-choice="groen" aria-pressed="true"><span class="swatch swatch-groen" aria-hidden="true"></span>{{ __('shell.accent.groen') }}</button>
        <button type="button" class="quiet swatch-button" data-accent-choice="terracotta" aria-pressed="false"><span class="swatch swatch-terracotta" aria-hidden="true"></span>{{ __('shell.accent.terracotta') }}</button>
        <button type="button" class="quiet swatch-button" data-accent-choice="blauw" aria-pressed="false"><span class="swatch swatch-blauw" aria-hidden="true"></span>{{ __('shell.accent.blauw') }}</button>
        <button type="button" class="quiet swatch-button" data-accent-choice="pruim" aria-pressed="false"><span class="swatch swatch-pruim" aria-hidden="true"></span>{{ __('shell.accent.pruim') }}</button>
        <button type="button" class="quiet swatch-button" data-accent-choice="oker" aria-pressed="false"><span class="swatch swatch-oker" aria-hidden="true"></span>{{ __('shell.accent.oker') }}</button>
    </fieldset>
</div>
