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
