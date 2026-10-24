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

/* Sign-in photos: <div data-portal-slides> (includes/portal.php) fades to
   the next event photo every few seconds. The other photos load once the
   page has, so signing in is never slowed. Rotation stops while someone is
   in the form, while the tab is hidden, when the visitor pauses it, and not
   at all when the device asks for reduced motion. Without JavaScript the
   first photo stays. */
(function () {
    'use strict';
    var stack = document.querySelector('[data-portal-slides]');
    if (!stack) { return; }
    var slides = Array.prototype.slice.call(stack.querySelectorAll('.portal-slide'));
    if (slides.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    var INTERVAL_MS = 7000;
    var current = 0, timer = null, paused = false, inForm = false;
    var form = document.querySelector('.portal-main');
    var toggle = document.querySelector('[data-portal-slides-toggle]');

    window.addEventListener('load', function () {
        slides.forEach(function (slide) {
            var url = slide.getAttribute('data-photo');
            if (url) {
                slide.style.setProperty('--slide-photo', 'url("' + url + '")');
                slide.removeAttribute('data-photo');
            }
        });
    });

    function next() {
        slides[current].classList.remove('is-active');
        current = (current + 1) % slides.length;
        slides[current].classList.add('is-active');
    }
    function sync() {
        var run = !paused && !inForm && !document.hidden;
        if (run && timer === null) { timer = window.setInterval(next, INTERVAL_MS); }
        if (!run && timer !== null) { window.clearInterval(timer); timer = null; }
    }

    if (toggle) {
        toggle.hidden = false;
        toggle.addEventListener('click', function () {
            paused = !paused;
            toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
            toggle.textContent = paused ? 'Play background photos' : 'Pause background photos';
            sync();
        });
    }
    if (form) {
        form.addEventListener('focusin', function (event) {
            if (event.target !== toggle) { inForm = true; sync(); }
        });
        form.addEventListener('focusout', function () {
            window.setTimeout(function () {
                var active = document.activeElement;
                inForm = !!active && active !== toggle && form.contains(active);
                sync();
            }, 0);
        });
    }
    document.addEventListener('visibilitychange', sync);
    sync();
})();

/* Forms that start empty: <form data-start-empty> (the sign-in form on a
   fresh visit). Browsers put back what was typed on a reload, or when Back
   returns to the page after signing out; clear it so nobody finds the last
   person's email or password waiting. Saved logins a password manager fills
   on request are left alone. */
(function () {
    'use strict';
    var forms = document.querySelectorAll('form[data-start-empty]');
    if (!forms.length) { return; }
    // Some browsers restore values only once loading ends, so clear again
    // then, but never a field the person has already started typing in.
    var typed = new WeakSet();
    forms.forEach(function (form) {
        form.addEventListener('input', function (event) { typed.add(event.target); });
    });
    function clear(force) {
        forms.forEach(function (form) {
            form.querySelectorAll('input:not([type="hidden"])').forEach(function (input) {
                if (!force && typed.has(input)) { return; }
                if (input.type === 'checkbox' || input.type === 'radio') { input.checked = input.defaultChecked; }
                else { input.value = ''; }
            });
        });
    }
    clear(false);
    window.addEventListener('load', function () { clear(false); });

    // Fields login.php loaded read-only (so the browser's saved login is not
    // filled in on its own) become editable the moment someone reaches them.
    forms.forEach(function (form) {
        form.querySelectorAll('input[data-locked]').forEach(function (input) {
            function unlock() {
                if (!input.hasAttribute('data-locked')) { return; }
                input.readOnly = false;
                input.removeAttribute('data-locked');
            }
            // pointerdown comes before focus, so phones open the keyboard.
            ['pointerdown', 'touchstart', 'focus', 'keydown'].forEach(function (type) {
                input.addEventListener(type, unlock);
            });
        });
    });
    // Back after signing out shows a stored copy of the page: start over.
    window.addEventListener('pageshow', function (event) { if (event.persisted) { clear(true); } });
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

/* Password visibility: <button data-password-toggle="inputId" hidden>
   holding an eye icon and a struck-through eye; CSS shows the one that
   matches aria-pressed. The button ships hidden and is revealed here, so
   without JavaScript the field just stays masked. Its accessible name says
   what it will do, and aria-pressed reports whether the password is showing. */
(function () {
    'use strict';
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) { return; }
        function render(showing) {
            button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            button.title = showing ? 'Hide password' : 'Show password';
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

/* New passwords (includes/layout/password-rules.php):
   - <ul data-password-rules="inputId"> ticks each rule as the person types,
     and keeps the input invalid until all are met, so neither a plain form
     nor a step of the sign-up form can be sent early. The unmet rules turn
     red once a send has been tried.
   - <input data-password-match="inputId"> on a confirm field says whether
     the two match once the person has finished typing, and stays invalid
     while they differ.
   The server checks the same rules again (password_error() in PHP). */
(function () {
    'use strict';
    var TESTS = {
        length: function (value, min) { return Array.from(value).length >= min; },
        upper:  function (value) { return /\p{Lu}/u.test(value); },
        lower:  function (value) { return /\p{Ll}/u.test(value); },
        number: function (value) { return /[0-9]/.test(value); }
    };

    document.querySelectorAll('[data-password-rules]').forEach(function (list) {
        var input = document.getElementById(list.getAttribute('data-password-rules'));
        if (!input) { return; }
        var min = parseInt(list.getAttribute('data-min-length'), 10) || 8;
        var items = Array.prototype.slice.call(list.querySelectorAll('[data-rule]'));

        function check() {
            var missing = [];
            items.forEach(function (item) {
                var test = TESTS[item.getAttribute('data-rule')];
                var met = test ? test(input.value, min) : true;
                item.classList.toggle('is-met', met);
                item.querySelector('[data-rule-state]').textContent = met ? 'Done: ' : 'Still needed: ';
                // The rule as a phrase, as the server words it: "a number", not "A number (0–9)".
                if (!met) { missing.push(item.textContent.replace(/^(Done|Still needed): /, '').replace(/\s*\(.*\)$/, '').toLowerCase()); }
            });
            var last = missing.pop();
            input.setCustomValidity(last === undefined ? '' :
                'Your password needs ' + (missing.length ? missing.join(', ') + ' and ' : '') + last + '.');
        }
        input.addEventListener('input', check);
        // A failed send (or a blocked step) shows what is still missing.
        input.addEventListener('invalid', function () { list.classList.add('show-unmet'); });
        check();
    });

    document.querySelectorAll('[data-password-match]').forEach(function (confirm) {
        var original = document.getElementById(confirm.getAttribute('data-password-match'));
        if (!original) { return; }
        var note = document.createElement('p');
        note.className = 'field-note';
        note.id = confirm.id + '-match';
        note.hidden = true;
        note.setAttribute('aria-live', 'polite');
        (confirm.closest('.password-field') || confirm).insertAdjacentElement('afterend', note);
        confirm.setAttribute('aria-describedby',
            ((confirm.getAttribute('aria-describedby') || '') + ' ' + note.id).trim());

        var touched = false;
        function check() {
            var same = confirm.value === original.value;
            confirm.setCustomValidity(confirm.value !== '' && !same ? 'The two passwords do not match.' : '');
            // Speak up once they look finished, not on every keystroke.
            var finished = touched || confirm.value.length >= original.value.length;
            if (confirm.value === '' || !finished) { note.hidden = true; return; }
            note.hidden = false;
            note.classList.toggle('is-ok', same);
            note.classList.toggle('is-error', !same);
            note.textContent = same ? 'Passwords match.' : 'Passwords do not match.';
        }
        confirm.addEventListener('input', check);
        confirm.addEventListener('blur', function () { if (confirm.value !== '') { touched = true; } check(); });
        confirm.addEventListener('invalid', function () { touched = true; check(); });
        original.addEventListener('input', function () { if (confirm.value !== '') { check(); } });
    });

    // Forms marked novalidate skip the browser's own check, so do it here
    // for the password fields. The stepped sign-up form checks its steps
    // itself and fires the same 'invalid' events.
    document.querySelectorAll('form').forEach(function (form) {
        if (form.hasAttribute('data-steps')) { return; }
        var fields = form.querySelectorAll('[data-password-match]');
        var ruled = Array.prototype.map.call(form.querySelectorAll('[data-password-rules]'), function (list) {
            return document.getElementById(list.getAttribute('data-password-rules'));
        });
        if (!fields.length && !ruled.length) { return; }
        form.addEventListener('submit', function (event) {
            var bad = ruled.concat(Array.prototype.slice.call(fields)).filter(function (field) {
                return field && !field.checkValidity();
            });
            if (bad.length) {
                event.preventDefault();
                bad[0].focus();
            }
        });
    });
})();

/* Role changes (admin/users.php): <form data-role-change data-person
   data-current-role>. Nothing is sent when the role did not change, and
   otherwise the admin confirms with both roles named. */
(function () {
    'use strict';
    document.querySelectorAll('form[data-role-change]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var select = form.querySelector('select[name="role_id"]');
            var chosen = select.options[select.selectedIndex].text.trim();
            var current = form.getAttribute('data-current-role');
            if (chosen === current ||
                !window.confirm('Change ' + form.getAttribute('data-person') + ' from ' + current + ' to ' + chosen + '?')) {
                event.preventDefault();
            }
        });
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

    // A clicked item leaves the bell. A beacon survives the page unloading
    // as the browser follows the link; the server answers 204.
    menu.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-dismiss]');
        if (!link || !navigator.sendBeacon) { return; }
        var data = new FormData();
        data.append('csrf_token', menu.getAttribute('data-dismiss-token'));
        data.append('dismiss', link.getAttribute('data-dismiss'));
        navigator.sendBeacon(menu.getAttribute('data-dismiss-url'), data);
    });
})();

/* Settings switches: flipping one saves it in the background and says so in
   a toast; the page does not reload. A failed save flips the switch back.
   Saves from one form run in order, so quick double flips end where the
   switch ends. Without JavaScript the Save button posts as before. */
(function () {
    'use strict';
    if (!window.fetch || !window.FormData) { return; }
    document.querySelectorAll('form[data-autosave]').forEach(function (form) {
        var submit = form.querySelector('[data-autosave-submit]');
        var state = form.querySelector('.set-switch-state');
        var queue = Promise.resolve();
        if (submit) { submit.hidden = true; }

        function show(input) { if (state) { state.textContent = input.checked ? 'On' : 'Off'; } }

        form.addEventListener('change', function (event) {
            var input = event.target;
            show(input);
            queue = queue.then(function () {
                // getAttribute: the form's own <input name="action"> shadows form.action.
                return fetch(form.getAttribute('action'), {
                    method: 'POST', body: new FormData(form),
                    credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
                }).then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.json();
                }).then(function (result) {
                    window.CASDevU.toast('success', result.message);
                }).catch(function () {
                    input.checked = !input.checked;
                    show(input);
                    window.CASDevU.toast('error', 'That setting was not saved. Check your connection and try again.');
                });
            });
        });
    });
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

/* Toasts: floating flash messages dismiss themselves after a few seconds;
   the close button dismisses at once. Inline ones (the sign-in card) only
   close on request, so the form below never shifts on its own, and so do
   ones with something to copy (a temporary password is shown only once,
   so it must not vanish before it is copied). The wrap
   removes itself with the last toast, so nothing invisible stays in the
   tab order. */
(function () {
    'use strict';
    var TOAST_MS = 6000;
    function wire(toast) {
        var done = false;
        function dismiss() {
            if (done) { return; }
            done = true;
            toast.classList.add('is-leaving');
            window.setTimeout(function () {
                var wrap = toast.closest('[data-toasts]');
                toast.remove();
                if (wrap && wrap.children.length === 0) { wrap.remove(); }
            }, 220);
        }
        var sticky = toast.closest('[data-toasts-inline]') || toast.querySelector('[data-toast-copy]');
        var timer = sticky ? null : window.setTimeout(dismiss, TOAST_MS);
        var closer = toast.querySelector('[data-toast-close]');
        if (closer) {
            closer.addEventListener('click', function () {
                window.clearTimeout(timer);
                dismiss();
            });
        }
        // Copy button: copies the toast's dedicated copy text (e.g. a
        // temporary password) — never the whole message — with a fallback
        // for browsers without the async API.
        var copier = toast.querySelector('[data-toast-copy]');
        if (copier) {
            var original = copier.innerHTML;
            copier.addEventListener('click', function () {
                var text = copier.getAttribute('data-toast-copy-text') || '';
                if (text === '') { return; }
                function confirm(ok) {
                    copier.classList.add('is-copied');
                    copier.textContent = ok ? 'Copied' : 'Copy failed';
                    copier.setAttribute('aria-label', ok ? 'Copied to clipboard' : 'Copy failed');
                    window.setTimeout(function () {
                        copier.classList.remove('is-copied');
                        copier.innerHTML = original;
                        copier.setAttribute('aria-label', 'Copy temporary password');
                    }, 1500);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () { confirm(true); }, function () { confirm(false); });
                } else {
                    try {
                        var area = document.createElement('textarea');
                        area.value = text;
                        document.body.appendChild(area);
                        area.select();
                        document.execCommand('copy');
                        document.body.removeChild(area);
                        confirm(true);
                    } catch (e) { confirm(false); }
                }
            });
        }
    }
    document.querySelectorAll('[data-toast]').forEach(wire);

    // A toast raised from script, for a save that does not reload the page.
    // The text goes in a moment after the element, so screen readers hear it.
    window.CASDevU.toast = function (type, message) {
        var template = document.getElementById('toast-template');
        if (!template) { return; }
        var wrap = document.querySelector('[data-toasts]');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'toasts';
            wrap.setAttribute('data-toasts', '');
            document.body.appendChild(wrap);
        }
        var toast = template.content.firstElementChild.cloneNode(true);
        toast.classList.add('toast-' + type);
        if (type === 'error') { toast.setAttribute('role', 'alert'); }
        wrap.appendChild(toast);
        window.setTimeout(function () { toast.querySelector('p').textContent = message; }, 50);
        wire(toast);
    };
})();

/* Print buttons: [data-print] prints the page, replacing inline onclick
   handlers so markup stays consistent. Works without any other setup. */
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-print]');
        if (button) { window.print(); }
    });
})();

/* Shared popup: links with [data-modal] open the shared <dialog> and load
   their form page into it, instead of navigating. The link carries
   data-modal-title for the heading and data-modal-wrap for the fragment id
   to pull out of the fetched page; data-modal-src, when present, is fetched
   instead of href (a short version, while href stays the full page).
   Without JavaScript (or without <dialog> support) the link opens the page
   as before. A successful save follows the
   page's own redirect; validation errors re-render inside the popup, using
   the page's own old-input. Photo uploads work: the submit travels as
   FormData, exactly like a normal post. A form without its own action posts
   back to the page it was loaded from, not to the page under the popup. */
(function () {
    'use strict';
    var dialog = document.getElementById('modal-dialog');
    if (!dialog || !window.fetch || !window.DOMParser || !dialog.showModal) { return; }
    var heading = dialog.querySelector('[data-modal-heading]');
    var body = dialog.querySelector('[data-modal-body]');
    var lastTrigger = null;
    var wrapSelector = '';

    function close() { if (dialog.open) { dialog.close(); } }

    dialog.querySelector('[data-modal-close]').addEventListener('click', close);
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) { close(); }
    });
    dialog.addEventListener('close', function () {
        if (lastTrigger) { lastTrigger.focus(); }
    });

    function swap(html, sourceUrl) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var wrap = wrapSelector === '' ? null : doc.querySelector(wrapSelector);
        if (!wrap) { return false; }
        body.innerHTML = '';
        body.appendChild(document.importNode(wrap, true));
        // An action-less form means "post to my own page". Moved into the
        // popup it would post to the page underneath, which ignores it.
        body.querySelectorAll('form:not([action])').forEach(function (form) {
            form.setAttribute('action', sourceUrl);
        });
        body.scrollTop = 0;
        return true;
    }

    function load(url) {
        body.innerHTML = '<p class="muted">Loading…</p>';
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                if (!swap(html, url)) { window.location.href = url; return; }
                var field = body.querySelector('input, select, textarea');
                if (field) { field.focus({ preventScroll: true }); }
            })
            .catch(function () { window.location.href = url; });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-modal]');
        if (!link) { return; }
        event.preventDefault();
        lastTrigger = link;
        wrapSelector = link.getAttribute('data-modal-wrap') || '';
        heading.textContent = link.getAttribute('data-modal-title') || 'Details';
        if (!dialog.open) { dialog.showModal(); }
        load(link.getAttribute('data-modal-src') || link.href);
    });

    body.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') { return; }
        event.preventDefault();
        // getAttribute: a form with <input name="action"> (the item form) shadows form.action.
        var target = form.getAttribute('action');
        fetch(target, {
            method: 'POST', body: new FormData(form),
            credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            if (response.redirected) { window.location.href = response.url; return null; }
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.text();
        }).then(function (html) {
            if (html === null) { return; }
            if (!swap(html, target)) { form.submit(); return; }
            var errors = body.querySelector('.alert-error');
            if (errors) { errors.setAttribute('tabindex', '-1'); errors.focus({ preventScroll: true }); }
        }).catch(function () { form.submit(); });
    });
})();

/* Section entrances: blocks that start below the first screen rise in as
   they reach the viewport, staggered up to 210ms apart. The content is
   visible without this script; it is armed only when an observer exists and
   the device has not asked for reduced motion. */
(function () {
    'use strict';
    if (!('IntersectionObserver' in window)) { return; }
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    var blocks = document.querySelectorAll('.page > .card, .page > .grid > *, .page > .stats > *, .page > .fact-strip');
    if (blocks.length === 0) { return; }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) { return; }
            entry.target.classList.add('reveal-in');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

    Array.prototype.forEach.call(blocks, function (block, index) {
        /* Only blocks that start below the first screen are armed. Anything
           already in view is simply there: no flash of missing content, and
           the page reads complete on arrival. */
        if (block.getBoundingClientRect().top < window.innerHeight - 40) { return; }
        block.classList.add('reveal-armed');
        block.style.transitionDelay = (index % 4) * 70 + 'ms';
        observer.observe(block);
    });
})();
