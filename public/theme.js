(() => {
    'use strict';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let storageUnavailable = false;

    // Preferences live in this browser only (localStorage); a blocked store keeps the
    // page fully usable and is reported next to the controls.
    const prefs = {
        theme: { key: 'vistora.theme', valid: ['system', 'light', 'dark'], value: 'system', fallback: 'system' },
        accent: { key: 'vistora.accent', valid: ['groen', 'terracotta', 'blauw', 'pruim', 'oker'], value: 'groen', fallback: 'groen' },
        sidebar: { key: 'vistora.sidebar', valid: ['expanded', 'collapsed'], value: 'expanded', fallback: 'expanded' },
    };
    const accept = (pref, value) => pref.valid.includes(value) ? value : null;
    for (const pref of Object.values(prefs)) {
        try {
            const stored = accept(pref, localStorage.getItem(pref.key));
            if (stored) pref.value = stored;
        } catch (error) {
            storageUnavailable = true;
            console.warn('Vistora preference storage is unavailable.', error);
        }
    }
    const save = pref => {
        try {
            localStorage.setItem(pref.key, pref.value);
            storageUnavailable = false;
        } catch (error) {
            storageUnavailable = true;
            console.warn('Vistora preference could not be saved.', error);
        }
    };

    const apply = () => {
        const theme = prefs.theme.value === 'system' ? (system.matches ? 'dark' : 'light') : prefs.theme.value;
        root.dataset.theme = theme;
        // Archiefgroen is the default and carries no attribute.
        if (prefs.accent.value === 'groen') delete root.dataset.accent; else root.dataset.accent = prefs.accent.value;
        root.dataset.sidebar = prefs.sidebar.value;
        document.querySelectorAll('meta[name="theme-color"]').forEach(meta => {
            meta.content = getComputedStyle(root).getPropertyValue('--color-background').trim();
        });
        const select = document.getElementById('theme-preference');
        if (select) select.value = prefs.theme.value;
        document.querySelectorAll('[data-accent-choice]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.accentChoice === prefs.accent.value));
        });
        const toggle = document.getElementById('sidebar-toggle');
        if (toggle) {
            const collapsed = prefs.sidebar.value === 'collapsed';
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.querySelector('[data-toggle-label]').textContent = collapsed ? toggle.dataset.labelExpand : toggle.dataset.labelCollapse;
        }
    };
    apply();
    system.addEventListener('change', () => { if (prefs.theme.value === 'system') apply(); });
    window.addEventListener('storage', event => {
        for (const pref of Object.values(prefs)) {
            if (event.key === pref.key || event.key === null) pref.value = accept(pref, event.key === null ? null : event.newValue) ?? pref.fallback;
        }
        apply();
    });

    document.addEventListener('DOMContentLoaded', () => {
        const control = document.getElementById('theme-control');
        const select = document.getElementById('theme-preference');
        const status = document.getElementById('theme-status');
        if (control) control.hidden = false;
        apply();
        const reportStorage = () => {
            if (status) status.textContent = storageUnavailable ? status.dataset.storageError : '';
        };
        reportStorage();
        const choose = (pref, value) => {
            if (!accept(pref, value)) return;
            pref.value = value;
            apply();
            save(pref);
            reportStorage();
        };
        if (select) select.addEventListener('change', () => choose(prefs.theme, select.value));
        document.querySelectorAll('[data-accent-choice]').forEach(button => {
            button.addEventListener('click', () => choose(prefs.accent, button.dataset.accentChoice));
        });
        const toggle = document.getElementById('sidebar-toggle');
        if (toggle) toggle.hidden = false;
        if (toggle) toggle.addEventListener('click', () => choose(prefs.sidebar, prefs.sidebar.value === 'collapsed' ? 'expanded' : 'collapsed'));

        // Native <details> menus: close on outside click and on Escape, returning focus.
        const menus = [...document.querySelectorAll('details.menu')];
        document.addEventListener('click', event => {
            menus.forEach(menu => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            menus.forEach(menu => {
                if (!menu.open) return;
                menu.open = false;
                menu.querySelector('summary')?.focus();
            });
        });
    });
})();
