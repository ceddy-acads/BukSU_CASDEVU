/* CASMS: navigation drawer for phones and tablets.
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
