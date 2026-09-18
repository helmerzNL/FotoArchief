(() => {
    'use strict';
    const key = 'vistora.theme';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = 'system';
    let storageUnavailable = false;

    const valid = value => ['system', 'light', 'dark'].includes(value);
    try {
        const stored = localStorage.getItem(key);
        if (valid(stored)) preference = stored;
    } catch (error) {
        storageUnavailable = true;
        console.warn('Vistora theme preference storage is unavailable.', error);
    }

    const apply = () => {
        const theme = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference;
        root.dataset.theme = theme;
        document.querySelectorAll('meta[name="theme-color"]').forEach(meta => {
            meta.content = getComputedStyle(root).getPropertyValue('--color-background').trim();
        });
        const select = document.getElementById('theme-preference');
        if (select) select.value = preference;
    };
    apply();
    system.addEventListener('change', () => { if (preference === 'system') apply(); });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) {
            preference = valid(event.newValue) ? event.newValue : 'system';
            apply();
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
        const control = document.getElementById('theme-control');
        const select = document.getElementById('theme-preference');
        const status = document.getElementById('theme-status');
        control.hidden = false;
        apply();
        const reportStorage = () => {
            status.textContent = storageUnavailable ? status.dataset.storageError : '';
        };
        reportStorage();
        select.addEventListener('change', () => {
            if (!valid(select.value)) return;
            preference = select.value;
            apply();
            try {
                localStorage.setItem(key, preference);
                storageUnavailable = false;
            } catch (error) {
                storageUnavailable = true;
                console.warn('Vistora theme preference could not be saved.', error);
            }
            reportStorage();
        });
    });
})();
