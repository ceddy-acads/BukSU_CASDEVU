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

    /* ---------------------------------------------------------- Flipbook */

    /** One report as a book: StPageFlip turns the pages, PDF.js draws only
     *  the pages near the one being read, so a long report opens fast. */
    function openBook(book) {
        var stage   = book.querySelector('[data-flip-stage]');
        var prev    = book.querySelector('[data-flip-prev]');
        var next    = book.querySelector('[data-flip-next]');
        var status  = book.querySelector('[data-flip-status]');
        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var doc = null, flip = null, pages = [], drawn = [], drawing = Promise.resolve();

        // StPageFlip copies a page element (cloneNode) to turn it on a phone,
        // and a copied canvas comes out blank; a copied <img> keeps its
        // picture. So each page is drawn on a canvas and shown as an image.
        function draw(i) {
            var width = Math.round(flip.getBoundsRect().pageWidth * Math.min(window.devicePixelRatio || 1, 2));
            if (!width || drawn[i] >= width) {
                return Promise.resolve();
            }
            drawn[i] = width;
            return doc.getPage(i + 1)
                .then(function (page) {
                    var viewport = page.getViewport({ scale: width / page.getViewport({ scale: 1 }).width });
                    var canvas = document.createElement('canvas');
                    canvas.width  = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);
                    return page.render({ canvas: canvas, viewport: viewport }).promise.then(function () {
                        page.cleanup();
                        return new Promise(function (done) { canvas.toBlob(done, 'image/jpeg', 0.92); });
                    });
                })
                .then(function (blob) {
                    var img = pages[i].querySelector('img') || pages[i].appendChild(document.createElement('img'));
                    var old = img.src;
                    img.alt = 'Page ' + (i + 1);
                    img.draggable = false;
                    img.src = URL.createObjectURL(blob);
                    if (old) {
                        URL.revokeObjectURL(old);
                    }
                })
                .catch(function () {
                    drawn[i] = 0; // a page that fails stays blank; the next visit retries it
                });
        }

        /** The pages being read first, then the ones after, then before. */
        function drawAround(current) {
            var last = pages.length - 1;
            var order = [];
            for (var i = current; i <= Math.min(last, current + 3); i++) { order.push(i); }
            for (i = current - 1; i >= Math.max(0, current - 2); i--) { order.push(i); }
            order.forEach(function (n) {
                drawing = drawing.then(function () { return draw(n); });
            });
        }

        function update() {
            var i = flip.getCurrentPageIndex();
            var n = flip.getPageCount();
            // With showCover a spread is [odd, even]; the cover and an
            // unpaired last page stand alone.
            var pair = flip.getOrientation() === 'landscape' && i > 0 && i < n - 1;
            status.textContent = pair
                ? 'Pages ' + (i + 1) + '–' + (i + 2) + ' of ' + n
                : 'Page ' + (i + 1) + ' of ' + n;
            prev.disabled = i === 0;
            next.disabled = (pair ? i + 1 : i) >= n - 1;
            drawAround(i);
        }

        function fail(error) {
            console.error('Highlight report could not be opened:', error);
            var alert = document.createElement('div');
            alert.className = 'alert alert-error';
            alert.setAttribute('role', 'alert');
            alert.textContent = 'This report could not be displayed here. Use Download PDF below to read it.';
            book.replaceWith(alert);
        }

        openPdf(book.getAttribute('data-pdf'))
            .then(function (task) { return task.promise; })
            .then(function (d) {
                doc = d;
                return d.getPage(1);
            })
            .then(function (first) {
                if (!window.St || !window.St.PageFlip) {
                    throw new Error('StPageFlip did not load');
                }
                var base = first.getViewport({ scale: 1 });
                // The page shape caps the stage width, so a spread fits the screen height.
                stage.style.setProperty('--page-ratio', String(base.width / base.height));
                for (var i = 0; i < doc.numPages; i++) {
                    var page = document.createElement('div');
                    page.className = 'flip-page' + (i % 2 ? ' is-left' : (i ? ' is-right' : ''));
                    pages.push(page);
                }
                // StPageFlip sets inline sizes on the element it is given, so it
                // gets its own block inside the stage.
                var block = stage.appendChild(document.createElement('div'));
                flip = new window.St.PageFlip(block, {
                    width: Math.round(base.width),
                    height: Math.round(base.height),
                    size: 'stretch',
                    minWidth: 240, maxWidth: 1000, minHeight: 340, maxHeight: 1414,
                    showCover: true,
                    usePortrait: true,
                    mobileScrollSupport: false,
                    maxShadowOpacity: 0.5,
                    showPageCorners: !reduced,
                    flippingTime: reduced ? 1 : 700
                });
                flip.loadFromHTML(pages);
                flip.on('flip', update);
                flip.on('changeOrientation', update);
                update();

                prev.addEventListener('click', function () { flip.flipPrev(); });
                next.addEventListener('click', function () { flip.flipNext(); });
                document.addEventListener('keydown', function (e) {
                    if (e.altKey || e.ctrlKey || e.metaKey || e.defaultPrevented
                        || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')
                        || (e.target.closest && e.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])'))) {
                        return;
                    }
                    e.preventDefault();
                    if (e.key === 'ArrowLeft') { flip.flipPrev(); } else { flip.flipNext(); }
                });
                // A new page size (rotated phone, resized window) redraws the pages in view.
                var resizing = 0;
                window.addEventListener('resize', function () {
                    clearTimeout(resizing);
                    resizing = setTimeout(function () { drawAround(flip.getCurrentPageIndex()); }, 250);
                });
            })
            .catch(fail);
    }

    Array.prototype.forEach.call(books, openBook);
})();
