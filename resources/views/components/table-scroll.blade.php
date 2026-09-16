{{--
    Bounded, keyboard-focusable horizontal scroll wrapper for wide staff
    tables at narrow viewports. Portal-local: intentionally not a dependency
    on Catalogue's x-catalogue-table component (different module/branch); this
    is a small inline equivalent kept inside Publication's own views so the
    two modules do not couple their view layers.

    tabindex="0" + role="region" + aria-label let a keyboard/AT user reach and
    scroll the region itself (WCAG 2.1 SC 1.4.10 / 2.1.1), instead of only a
    mouse-drag or an ineffective whole-page horizontal scroll.
--}}
@props(['label'])
<div class="table-scroll" role="region" tabindex="0" aria-label="{{ $label }}">
    {{ $slot }}
</div>
