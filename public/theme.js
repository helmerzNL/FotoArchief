(() => {
    'use strict';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let storageUnavailable = false;
    let saveFailed = false;
    let report = () => {};

    // Signed-in people have their choices on the account (the server renders them into
    // data-user-prefs), so they follow the person across devices. localStorage stays as a
    // per-browser cache and as the only store for visitors without an account; a blocked
    // store or a failed save keeps the page fully usable and is reported next to the controls.
    const readUserPrefs = () => {
        try {
            const parsed = JSON.parse(root.dataset.userPrefs || 'null');
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            return null;
        }
    };
    const userPrefs = readUserPrefs();
    const saveUrl = userPrefs ? (root.dataset.prefsUrl || null) : null;

    const prefs = {
        theme: { key: 'vistora.theme', valid: ['system', 'light', 'dark'], value: 'system', fallback: 'system' },
        accent: { key: 'vistora.accent', valid: ['groen', 'terracotta', 'blauw', 'pruim', 'oker'], value: 'groen', fallback: 'groen' },
        sidebar: { key: 'vistora.sidebar', valid: ['expanded', 'collapsed'], value: 'expanded', fallback: 'expanded' },
    };
    const accept = (pref, value) => pref.valid.includes(value) ? value : null;
    const store = pref => {
        try {
            localStorage.setItem(pref.key, pref.value);
            return true;
        } catch (error) {
            console.warn('Vistora preference could not be saved in this browser.', error);
            return false;
        }
    };

    // Choices this browser already had that the account has not seen yet; sent once.
    const toSync = {};
    for (const [name, pref] of Object.entries(prefs)) {
        let local = null;
        try {
            local = accept(pref, localStorage.getItem(pref.key));
        } catch (error) {
            storageUnavailable = true;
            console.warn('Vistora preference storage is unavailable.', error);
        }
        const account = userPrefs ? accept(pref, userPrefs[name]) : null;
        if (account) {
            pref.value = account;
            if (local !== account) store(pref);
        } else if (local) {
            pref.value = local;
            if (userPrefs) toSync[name] = local;
        }
    }

    const syncToServer = changes => {
        if (!saveUrl) return;
        fetch(saveUrl, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(changes),
        }).then(response => { saveFailed = !response.ok; report(); }).catch(() => { saveFailed = true; report(); });
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
        report = () => {
            if (!status) return;
            if (saveFailed) status.textContent = status.dataset.saveError;
            else status.textContent = storageUnavailable && !saveUrl ? status.dataset.storageError : '';
        };
        report();
        const choose = (name, value) => {
            const pref = prefs[name];
            if (!accept(pref, value)) return;
            pref.value = value;
            apply();
            storageUnavailable = !store(pref);
            syncToServer({ [name]: value });
            report();
        };
        if (select) select.addEventListener('change', () => choose('theme', select.value));
        document.querySelectorAll('[data-accent-choice]').forEach(button => {
            button.addEventListener('click', () => choose('accent', button.dataset.accentChoice));
        });
        const toggle = document.getElementById('sidebar-toggle');
        if (toggle) {
            toggle.hidden = false;
            toggle.addEventListener('click', () => choose('sidebar', prefs.sidebar.value === 'collapsed' ? 'expanded' : 'collapsed'));
        }
        if (Object.keys(toSync).length > 0) syncToServer(toSync);

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
