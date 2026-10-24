/* CASDevU — Highlight reports in the browser.
 *
 * Safe on any page: it does nothing unless the page has a cover
 * ([data-hl-cover]) or a flipbook ([data-flipbook]), and only then loads
 * PDF.js from the URLs on the [data-hl-lib] element. Nothing is converted on
 * the server; every picture is drawn here from the PDF itself.
 */
(function () {
    'use strict';

    var covers = document.querySelectorAll('[data-hl-cover]');
    var books  = document.querySelectorAll('[data-flipbook]');
    var root   = document.querySelector('[data-hl-lib]');
    if ((!covers.length && !books.length) || !root) {
        return;
    }

    var lib = null;
    var worker = null;

    /** PDF.js, imported once, with one worker shared by every document. */
    function pdfjs() {
        if (!lib) {
            lib = import(root.getAttribute('data-hl-lib')).then(function (m) {
                m.GlobalWorkerOptions.workerSrc = root.getAttribute('data-hl-worker');
                worker = new m.PDFWorker();
                return m;
            });
        }
        return lib;
    }

    /** Start loading a PDF by range requests; resolves to the loading task
     *  (task.promise is the document, task.destroy() frees it). Reports are
     *  posted by staff but read by the public, so PDF.js never compiles code
     *  from a file (isEvalSupported). */
    function openPdf(url) {
        return pdfjs().then(function (m) {
            return m.getDocument({
                url: url,
                worker: worker,
                isEvalSupported: false,
                disableAutoFetch: true,
                disableStream: true
            });
        });
    }

    /** Page 1 at the cover's CSS size times the screen density (at most 2). */
    function drawCover(canvas) {
        var card = canvas.closest('.hl-card') || canvas.parentNode;
        var task = null;
        return openPdf(canvas.getAttribute('data-pdf'))
            .then(function (t) {
                task = t;
                return t.promise;
            })
            .then(function (doc) {
                return doc.getPage(1);
            })
            .then(function (page) {
                var base  = page.getViewport({ scale: 1 });
                var ratio = Math.min(window.devicePixelRatio || 1, 2);
                // Fill the box (object-fit: cover crops the rest), so a page
                // that is not A4 still draws sharp.
                var scale = Math.max(canvas.clientWidth / base.width, canvas.clientHeight / base.height) * ratio;
                var viewport = page.getViewport({ scale: scale || ratio });
                canvas.width  = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                return page.render({ canvas: canvas, viewport: viewport }).promise;
            })
            .then(function () {
                card.classList.add('is-drawn');
            })
            .catch(function () {
                // Any failure leaves the navy title card in place.
            })
            .then(function () {
                if (task) {
                    task.destroy();
                }
            });
    }

    // One cover at a time: the PDF route may be served by a single process.
    var queue = Promise.resolve();
    function enqueue(canvas) {
        queue = queue.then(function () { return drawCover(canvas); });
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    enqueue(entry.target);
                }
            });
        }, { rootMargin: '200px 0px' });
        Array.prototype.forEach.call(covers, function (canvas) { observer.observe(canvas); });
    } else {
        Array.prototype.forEach.call(covers, enqueue);
    }
})();
