/* CASDevU: small progressive enhancements. Every page works without this
   file; it only adds behaviour on top.

   1. Account menu: a <details> element, closed by Esc, a click outside,
      or focus leaving it.
   2. Navigation drawer for phones and tablets (below). */
(function () {
    'use strict';

    var menus = document.querySelectorAll('details[data-menu]');
    menus.forEach(function (menu) {
        menu.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.open) {
                menu.open = false;
                menu.querySelector('summary').focus();
            }
        });
        menu.addEventListener('focusout', function (event) {
            if (menu.open && event.relatedTarget && !menu.contains(event.relatedTarget)) {
                menu.open = false;
            }
        });
    });
    document.addEventListener('click', function (event) {
        menus.forEach(function (menu) {
            if (menu.open && !menu.contains(event.target)) { menu.open = false; }
        });
    });
})();

/* Navigation drawer for phones and tablets.
   The sidebar is always in the page; below 960px it slides in over the
   content. Esc, the backdrop, and the close button all dismiss it, and
   focus returns to the Menu button. */
(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var opener = document.querySelector('[data-nav-open]');
    if (!sidebar || !opener) { return; }

    var desktop = window.matchMedia('(min-width: 960px)');

    function open() {
        body.classList.add('nav-open');
        opener.setAttribute('aria-expanded', 'true');
        var target = sidebar.querySelector('[aria-current="page"]') || sidebar.querySelector('a, button');
        if (target) { target.focus(); }
    }

    function close(returnFocus) {
        if (!body.classList.contains('nav-open')) { return; }
        body.classList.remove('nav-open');
        opener.setAttribute('aria-expanded', 'false');
        if (returnFocus) { opener.focus(); }
    }

    opener.addEventListener('click', open);

    document.querySelectorAll('[data-nav-close]').forEach(function (el) {
        el.addEventListener('click', function () { close(true); });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { close(true); return; }

        // Keep Tab inside the open drawer on small screens.
        if (event.key === 'Tab' && body.classList.contains('nav-open') && !desktop.matches) {
            var items = sidebar.querySelectorAll('a, button');
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    desktop.addEventListener('change', function () { close(false); });
})();

/* Submit feedback for forms that change data (POST).
   The pressed button is disabled and relabelled so a second click cannot
   send the form twice. Confirm dialogs that answer "Cancel" stop the
   submit before this runs, so the button stays untouched. The state is
   restored if the page comes back from the browser cache or the request
   does not navigate away. GET filter forms are left alone. */
(function () {
    'use strict';

    function reset(button) {
        if (!button || !button.hasAttribute('data-busy')) { return; }
        button.disabled = false;
        button.removeAttribute('data-busy');
        button.removeAttribute('aria-busy');
        button.textContent = button.getAttribute('data-label');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (event.defaultPrevented || (form.getAttribute('method') || '').toLowerCase() !== 'post') { return; }

        var button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
        if (!button || button.tagName !== 'BUTTON') { return; }

        // A named button's value only travels while it is enabled; keep it.
        if (button.name) {
            var carry = document.createElement('input');
            carry.type = 'hidden';
            carry.name = button.name;
            carry.value = button.value;
            form.appendChild(carry);
        }

        button.setAttribute('data-label', button.textContent);
        button.setAttribute('data-busy', '');
        button.setAttribute('aria-busy', 'true');
        button.textContent = button.getAttribute('data-loading') ||
            (button.classList.contains('btn-danger') ? 'Working…' : 'Saving…');
        // Disable after the browser has collected the form data.
        window.setTimeout(function () { button.disabled = true; }, 0);
        // If nothing navigates (a network failure), give the button back.
        window.setTimeout(function () { reset(button); }, 20000);
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('button[data-busy]').forEach(reset);
        }
    });
})();

/* Sortable tables: <table data-sortable> with <th data-sort="text|number|date">.
   Without JavaScript the headers are plain text and the server order stands.
   Each sortable header becomes a button, and aria-sort reports the order.
   A cell can supply its own key with data-sort-value. */
window.CASDevU = window.CASDevU || {};

(function () {
    'use strict';

    function key(cell, type) {
        var raw = cell ? (cell.getAttribute('data-sort-value') || cell.textContent) : '';
        raw = raw.trim();
        if (type === 'number') { var n = parseFloat(raw.replace(/[^0-9.\-]/g, '')); return isNaN(n) ? -Infinity : n; }
        if (type === 'date') { var t = Date.parse(raw); return isNaN(t) ? -Infinity : t; }
        return raw.toLowerCase();
    }

    function initSortable(root) {
        (root || document).querySelectorAll('table[data-sortable]:not([data-sort-ready])').forEach(function (table) {
            table.setAttribute('data-sort-ready', '');
            var body = table.tBodies[0];
            if (!body) { return; }
            var headers = table.tHead ? table.tHead.rows[0].cells : [];

            Array.prototype.forEach.call(headers, function (th, index) {
                var type = th.getAttribute('data-sort');
                if (!type) { return; }

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'sort-btn';
                button.innerHTML = th.innerHTML +
                    '<svg class="sort-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="sort-up" d="m8 10 4-4 4 4"/><path class="sort-down" d="m8 14 4 4 4-4"/></svg>';
                th.innerHTML = '';
                th.appendChild(button);
                th.setAttribute('aria-sort', 'none');

                button.addEventListener('click', function () {
                    var ascending = th.getAttribute('aria-sort') !== 'ascending';
                    Array.prototype.forEach.call(headers, function (other) {
                        if (other.hasAttribute('aria-sort')) { other.setAttribute('aria-sort', 'none'); }
                    });
                    th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

                    var rows = Array.prototype.slice.call(body.rows);
                    rows.sort(function (a, b) {
                        var x = key(a.cells[index], type), y = key(b.cells[index], type);
                        if (x < y) { return ascending ? -1 : 1; }
                        if (x > y) { return ascending ? 1 : -1; }
                        return 0;
                    });
                    rows.forEach(function (row) { body.appendChild(row); });
                });
            });
        });
    }

    window.CASDevU.initSortable = initSortable;
    initSortable(document);
})();

/* Live search for filter forms: <form data-live-search>.
   Typing (debounced) or changing a filter fetches the same page with the
   form's query and swaps only the parts marked data-live-region="name".
   The server renders the results exactly as for a normal visit, so the same
   authorization, filtering and escaping apply; nothing is filtered in the
   browser. The URL is kept in step (reload, share, pagination all work),
   stale requests are cancelled, the new count is announced, and any
   failure falls back to an ordinary form submit. */
(function () {
    'use strict';
    if (!window.fetch || !window.DOMParser) { return; }

    var DELAY = 300;

    document.querySelectorAll('form[data-live-search]').forEach(function (form) {
        var timer = null;
        var controller = null;

        // The query a search would send: filled fields only, never the page.
        function queryOf() {
            var params = new URLSearchParams();
            new FormData(form).forEach(function (value, name) {
                if (value !== '' && name !== 'page') { params.append(name, value); }
            });
            return params.toString();
        }
        var lastQuery = queryOf();

        // One polite status line per form for screen readers.
        var status = document.createElement('p');
        status.className = 'sr-only';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        form.appendChild(status);

        function regions(doc) { return doc.querySelectorAll('[data-live-region]'); }

        function run() {
            var query = queryOf();                     // new criteria start at page 1
            if (query === lastQuery) { return; }
            lastQuery = query;

            var url = form.getAttribute('action') || window.location.pathname;
            url = url.split('?')[0] + (query ? '?' + query : '');

            if (controller) { controller.abort(); }
            controller = new AbortController();

            regions(document).forEach(function (el) { el.setAttribute('aria-busy', 'true'); el.classList.add('is-loading'); });

            fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (response) {
                    if (!response.ok || response.redirected) { throw new Error('HTTP ' + response.status); }
                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    regions(document).forEach(function (el) {
                        var fresh = doc.querySelector('[data-live-region="' + el.getAttribute('data-live-region') + '"]');
                        if (fresh) { el.innerHTML = fresh.innerHTML; }
                        el.removeAttribute('aria-busy');
                        el.classList.remove('is-loading');
                    });
                    window.history.replaceState(null, '', url);
                    if (window.CASDevU.initSortable) { window.CASDevU.initSortable(document); }
                    // Announce what changed: the empty-state heading, else the
                    // page's own count ([data-live-status]), else its summary line.
                    var empty = document.querySelector('[data-live-region="results"] .empty strong');
                    var count = document.querySelector('[data-live-region="results"] [data-live-status]');
                    var summary = document.querySelector('[data-live-region="summary"]');
                    status.textContent = (empty || count || summary)
                        ? (empty || count || summary).textContent.trim()
                        : 'Results updated';
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') { return; }
                    form.submit();                       // fall back to a normal page load
                });
        }

        form.addEventListener('input', function (event) {
            if (event.target.type !== 'search' && event.target.type !== 'text') { return; }
            window.clearTimeout(timer);
            timer = window.setTimeout(run, DELAY);
        });
        form.addEventListener('change', function (event) {
            if (event.target.tagName === 'SELECT' || event.target.type === 'checkbox') {
                window.clearTimeout(timer);
                run();
            }
        });
        // Enter / the Filter button still work, now without a reload.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(timer);
            lastQuery = null;
            run();
        });
    });
})();

/* Password visibility: <button data-password-toggle="inputId" hidden>.
   The button ships hidden and is revealed here, so without JavaScript the
   field just stays masked. Its accessible name says what it will do, and
   aria-pressed reports whether the password is showing. */
(function () {
    'use strict';
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) { return; }
        function render(showing) {
            button.textContent = showing ? 'Hide' : 'Show';
            button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', showing ? 'true' : 'false');
        }
        render(false);
        button.hidden = false;
        button.addEventListener('click', function () {
            var showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            render(showing);
        });
        // Never submit (or let the browser remember) the password as plain text.
        if (input.form) {
            input.form.addEventListener('submit', function () { input.type = 'password'; render(false); });
        }
    });
})();

/* Notifications pop-up: opening it marks everything read, as visiting the
   old notifications page did. The pop-up's own "Mark all as read" form is
   sent in the background, so it shares that form's token and endpoint.
   The unread count goes at once; the "New" tags stay until the pop-up
   closes, so people can still see what was new. Without JavaScript the
   form's button does the same job. */
(function () {
    'use strict';
    var menu = document.querySelector('details[data-notifications]');
    if (!menu || !window.fetch) { return; }
    var form = menu.querySelector('form[data-notifications-read]');
    var trigger = menu.querySelector('summary');

    function markRead() {
        if (!form) { return; }
        var sending = form;
        form = null;                      // once per page
        fetch(sending.action, {
            method: 'POST', body: new FormData(sending),
            credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            if (!response.ok) { form = sending; return; }
            var count = trigger.querySelector('.nav-count');
            if (count) { count.remove(); }
            trigger.setAttribute('aria-label', 'Notifications');
            sending.remove();
        }).catch(function () { form = sending; });
    }

    menu.addEventListener('toggle', function () {
        if (menu.open) { markRead(); return; }
        menu.querySelectorAll('.notif-item.is-new').forEach(function (item) {
            if (!form) {                  // only once they have been marked read
                item.classList.remove('is-new');
                var tag = item.querySelector('.badge');
                if (tag) { tag.remove(); }
            }
        });
    });
    if (menu.open) { markRead(); }        // opened by ?notifications=open
})();

/* Profile photo: choosing a file crops it to the centre square, resizes it
   to 512 px and re-encodes it as JPEG before upload. That keeps uploads
   small, applies the camera's rotation, and drops EXIF data such as GPS
   location. The photo is previewed and sent at once, so the Upload button
   is only needed without JavaScript. If the browser cannot decode the file,
   the original is sent and the server's checks decide. */
(function () {
    'use strict';
    var input = document.querySelector('[data-photo-input]');
    if (!input || !window.createImageBitmap || !window.DataTransfer) { return; }
    var form = input.form;
    var submit = form.querySelector('[data-photo-submit]');
    var preview = document.querySelector('[data-photo-preview]');
    var SIZE = 512;

    if (submit) { submit.hidden = true; }

    function send() {
        if (form.requestSubmit) { form.requestSubmit(submit || undefined); } else { form.submit(); }
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) { return; }

        createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
            var side = Math.min(bitmap.width, bitmap.height);
            var canvas = document.createElement('canvas');
            canvas.width = canvas.height = Math.min(SIZE, side);
            canvas.getContext('2d').drawImage(bitmap,
                (bitmap.width - side) / 2, (bitmap.height - side) / 2, side, side,
                0, 0, canvas.width, canvas.height);
            bitmap.close();

            canvas.toBlob(function (blob) {
                if (!blob) { send(); return; }
                var resized = new File([blob], 'profile-photo.jpg', { type: 'image/jpeg' });
                var transfer = new DataTransfer();
                transfer.items.add(resized);
                input.files = transfer.files;

                if (preview) {
                    preview.innerHTML = '';
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(resized);
                    img.alt = 'Your new profile photo';
                    preview.appendChild(img);
                }
                send();
            }, 'image/jpeg', 0.88);
        }).catch(send);
    });
})();
/* Stepped forms: <form data-steps> with sections [data-step]. One step
   shows at a time with Back / Continue, a "Step 2 of 4" line and a progress
   bar; the submit button appears on the last step. Continue checks the
   step's own fields first, and Enter never submits early. Without
   JavaScript every step shows and the form works as one page. After a
   failed submit it opens at the first step with an empty required field. */
(function () {
    'use strict';
    document.querySelectorAll('form[data-steps]').forEach(function (form) {
        var steps  = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
        var status = form.querySelector('[data-steps-status]');
        var nav    = form.querySelector('[data-steps-nav]');
        var back   = form.querySelector('[data-steps-back]');
        var next   = form.querySelector('[data-steps-next]');
        var submit = form.querySelector('button[type="submit"]');
        if (steps.length < 2 || !status || !nav) { return; }

        var current = 0;
        form.classList.add('is-stepped');
        status.hidden = false;
        if (submit) { nav.appendChild(submit); }   // Back and Create account share a row
        nav.hidden = false;

        function fields(step) { return step.querySelectorAll('input:not([type="hidden"]), select, textarea'); }

        function show(index, moveFocus) {
            current = index;
            steps.forEach(function (step, i) { step.hidden = i !== index; });
            var last = index === steps.length - 1;
            back.hidden = index === 0;
            next.hidden = last;
            if (submit) { submit.hidden = !last; }
            var title = steps[index].querySelector('h3');
            status.innerHTML = '';
            var text = document.createElement('span');
            text.textContent = 'Step ' + (index + 1) + ' of ' + steps.length + (title ? ': ' + title.textContent : '');
            var bar = document.createElement('span');
            bar.className = 'steps-bar';
            bar.setAttribute('aria-hidden', 'true');
            steps.forEach(function (_, i) {
                var seg = document.createElement('span');
                if (i <= index) { seg.className = 'is-done'; }
                bar.appendChild(seg);
            });
            status.appendChild(text);
            status.appendChild(bar);
            if (moveFocus && title) { title.focus(); }
        }

        // The current step's fields must be valid before moving on.
        function stepValid() {
            var list = fields(steps[current]);
            for (var i = 0; i < list.length; i++) {
                if (!list[i].checkValidity()) { list[i].reportValidity(); list[i].focus(); return false; }
            }
            return true;
        }

        next.addEventListener('click', function () {
            if (stepValid()) { show(current + 1, true); }
        });
        back.addEventListener('click', function () { show(current - 1, true); });

        // Enter in a field: on an early step it means Continue, not submit.
        form.addEventListener('submit', function (event) {
            if (current < steps.length - 1) {
                event.preventDefault();
                if (stepValid()) { show(current + 1, true); }
            } else if (!stepValid()) {
                event.preventDefault();
            }
        });

        // After a failed submit, start where something is missing.
        var start = 0;
        if (document.querySelector('.alert-error')) {
            for (var s = 0; s < steps.length; s++) {
                var empty = Array.prototype.some.call(fields(steps[s]), function (f) { return f.required && !f.value; });
                if (empty) { start = s; break; }
            }
        }
        show(start, false);
    });
})();

