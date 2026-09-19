(function () {
    'use strict';

    var viewer = document.querySelector('[data-viewer]');
    var viewport = document.getElementById('viewer-viewport');
    var image = document.getElementById('viewer-image');
    var status = document.getElementById('viewer-status');
    var zoom = 1;
    var panX = 0;
    var panY = 0;

    function render() {
        image.dataset.zoom = String(zoom);
        image.dataset.panX = String(panX);
        image.dataset.panY = String(panY);
        image.style.transform = 'translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + ')';
    }

    function reset() {
        zoom = 1;
        panX = 0;
        panY = 0;
        render();
    }

    function setZoom(nextZoom) {
        zoom = Math.min(4, Math.max(1, nextZoom));
        if (zoom === 1) {
            panX = 0;
            panY = 0;
        }
        render();
    }

    if (viewer && viewport && image) {
        document.getElementById('viewer-zoom-in').addEventListener('click', function () {
            setZoom(zoom + 0.5);
        });
        document.getElementById('viewer-zoom-out').addEventListener('click', function () {
            setZoom(zoom - 0.5);
        });
        document.getElementById('viewer-reset').addEventListener('click', reset);
        viewport.addEventListener('keydown', function (event) {
            var movements = {
                ArrowLeft: [40, 0],
                ArrowRight: [-40, 0],
                ArrowUp: [0, 40],
                ArrowDown: [0, -40]
            };
            if (!movements[event.key] || zoom === 1) {
                return;
            }
            event.preventDefault();
            panX += movements[event.key][0];
            panY += movements[event.key][1];
            render();
        });
        render();

        if (viewer.dataset.manifestUrl && status) {
            status.textContent = status.dataset.loading;
            fetch(viewer.dataset.manifestUrl, { headers: { Accept: 'application/ld+json' } })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('IIIF manifest returned HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (manifest) {
                    var body = manifest.items[0].items[0].items[0].body;
                    if (body.type !== 'Image' || typeof body.id !== 'string') {
                        throw new Error('IIIF manifest has no supported image body');
                    }
                    image.src = body.id;
                    status.textContent = status.dataset.loaded;
                })
                .catch(function (error) {
                    status.textContent = status.dataset.fallback;
                    console.error(error);
                });
        }
    }

    var copyButton = document.getElementById('copy-permalink');
    if (copyButton && navigator.clipboard) {
        copyButton.addEventListener('click', function () {
            navigator.clipboard.writeText(copyButton.dataset.url || '').then(function () {
                copyButton.textContent = 'Link gekopieerd';
            }).catch(function (error) {
                console.error(error);
            });
        });
    }
})();
