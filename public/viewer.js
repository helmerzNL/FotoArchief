// Minimal, dependency-free zoom toggle and permalink copy for the public
// photo viewer. No tiling/deep-zoom protocol is implemented here: this is a
// plain CSS scale toggle over the existing bounded JPEG preview.
(function () {
    'use strict';
    var image = document.getElementById('viewer-image');
    var zoomButton = document.getElementById('viewer-zoom');

    function toggleZoom() {
        var zoomed = image.classList.toggle('zoomed');
        image.setAttribute('aria-pressed', zoomed ? 'true' : 'false');
    }

    if (image && zoomButton) {
        zoomButton.addEventListener('click', toggleZoom);
        image.addEventListener('click', toggleZoom);
        image.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleZoom();
            }
        });
    }

    var copyButton = document.getElementById('copy-permalink');
    if (copyButton && navigator.clipboard) {
        copyButton.addEventListener('click', function () {
            navigator.clipboard.writeText(copyButton.dataset.url || '').then(function () {
                copyButton.textContent = 'Link gekopieerd';
            }).catch(function () {
                // Clipboard access denied or unavailable; the permalink text
                // is still visible and selectable for a manual copy.
            });
        });
    }
})();
