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
(function () {
    'use strict';

    function key(cell, type) {
        var raw = cell ? (cell.getAttribute('data-sort-value') || cell.textContent) : '';
        raw = raw.trim();
        if (type === 'number') { var n = parseFloat(raw.replace(/[^0-9.\-]/g, '')); return isNaN(n) ? -Infinity : n; }
        if (type === 'date') { var t = Date.parse(raw); return isNaN(t) ? -Infinity : t; }
        return raw.toLowerCase();
    }

    document.querySelectorAll('table[data-sortable]').forEach(function (table) {
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
})();
