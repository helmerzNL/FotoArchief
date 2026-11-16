// Update check banner (App-Guidance shared/guidelines/update-check.md). Only rendered for
// administrators. Asks the server once per browser session; the server asks GitHub at most
// every six hours. "Later" hides the banner for that version only.
(function () {
    'use strict';
    var banner = document.querySelector('[data-update-banner]');
    if (!banner) {
        return;
    }
    var dismissedKey = 'fotoarchief.update.dismissed';
    var askedKey = 'fotoarchief.update.asked';

    function read(storage, key) {
        try { return window[storage].getItem(key); } catch (error) { return null; }
    }
    function write(storage, key, value) {
        try { window[storage].setItem(key, value); } catch (error) { /* storage can be absent */ }
    }

    function render(status) {
        if (!status || !status.available || !status.latest) {
            return;
        }
        document.querySelectorAll('[data-nav-key="admin"], .subnav a[href$="/admin/operations/system"]').forEach(function (link) {
            link.classList.add('has-update');
        });
        if (read('localStorage', dismissedKey) === status.latest) {
            return;
        }
        banner.querySelector('[data-update-text]').textContent = banner.dataset.template
            .replace(':latest', status.latest)
            .replace(':current', status.current);
        banner.hidden = false;
        banner.querySelector('[data-update-later]').onclick = function () {
            write('localStorage', dismissedKey, status.latest);
            banner.hidden = true;
        };
    }

    render({
        available: banner.dataset.available === '1',
        latest: banner.dataset.latest,
        current: banner.dataset.current,
    });

    if (read('sessionStorage', askedKey) !== null || !window.fetch) {
        return;
    }
    write('sessionStorage', askedKey, '1');
    window.fetch(banner.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(render)
        .catch(function () { /* the System page shows the reason */ });
}());
