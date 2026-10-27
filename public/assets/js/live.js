/* Five-second, read-only snapshots and progressively enhanced decision forms. */
(function () {
    'use strict';
    if (!window.fetch || !window.DOMParser || !document.body.hasAttribute('data-live-role')) { return; }
    var role = document.body.getAttribute('data-live-role');
    var revisions = new Map(), submitting = new WeakSet();
    var timer = null, controller = null, stopped = false, deferred = false, acting = false;
    var status = document.createElement('p');
    status.className = 'live-status'; status.id = 'live-update-status';
    status.setAttribute('role', 'status'); status.setAttribute('tabindex', '-1'); status.hidden = true;
    document.getElementById('main').prepend(status);

    function notice(message) {
        status.textContent = message || ''; status.hidden = !message;
    }
    function formDirty(form) {
        if (submitting.has(form)) { return true; }
        return Array.from(form.elements).some(function (field) {
            if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button') { return false; }
            if (field.type === 'file') { return field.files.length > 0; }
            if (field.type === 'checkbox' || field.type === 'radio') { return field.checked !== field.defaultChecked; }
            if (field.tagName === 'SELECT') {
                var options = Array.from(field.options);
                if (!field.multiple && !options.some(function (o) { return o.defaultSelected; })) { return field.selectedIndex !== 0; }
                return options.some(function (o) { return o.selected !== o.defaultSelected; });
            }
            return field.value !== field.defaultValue;
        });
    }
    function edited(node) {
        return node.nodeType === 1 && ((node.tagName === 'FORM' && formDirty(node)) ||
            Array.from(node.querySelectorAll('form')).some(formDirty));
    }
    function key(node) {
        if (node.nodeType !== 1) { return ''; }
        if (node.tagName === 'FORM' && (node.getAttribute('method') || 'get').toLowerCase() === 'get') {
            return 'filter:' + (node.id || Array.from(node.querySelectorAll('[name]')).map(function (field) { return field.name; }).join(':'));
        }
        return node.getAttribute('data-refresh-key') || node.id || '';
    }
    function protect(node, next) {
        if (node.nodeType !== 1) { return false; }
        if (node.matches('[data-toasts], [data-live-feedback], #toast-template, #live-update-status, script, form[method="get"], form[method="GET"]')) { return true; }
        if (node.tagName === 'THEAD' && node.closest('table[data-sort-ready]')) { return true; }
        if (node.tagName === 'DIALOG' && (node.open || edited(node))) { return true; }
        if ((node.tagName === 'FORM' || (key(node) && node.id !== 'main')) && edited(node)) {
            var current = node.querySelector('[name="_live_revision"], [name="version"]');
            var fresh = next && next.querySelector('[name="_live_revision"], [name="version"]');
            if (!next || (current && fresh && current.value !== fresh.value) || node.textContent.trim() !== next.textContent.trim()) {
                deferred = true; node.setAttribute('data-live-stale', '');
            }
            return true;
        }
        return false;
    }
    function attributes(old, fresh) {
        Array.from(old.attributes).forEach(function (a) {
            if (a.name === 'open' || a.name === 'data-live-stale' || /data-.*(?:ready|busy)/.test(a.name)) { return; }
            if (!fresh.hasAttribute(a.name)) { old.removeAttribute(a.name); }
        });
        Array.from(fresh.attributes).forEach(function (a) {
            if (a.name === 'open') { return; }
            if (a.name === 'class') {
                var runtime = Array.from(old.classList).filter(function (c) { return c === 'reveal-in' || c === 'reveal-armed'; });
                old.setAttribute('class', a.value);
                runtime.forEach(function (c) { old.classList.add(c); });
            } else if (old.getAttribute(a.name) !== a.value) { old.setAttribute(a.name, a.value); }
        });
    }
    function morph(old, fresh) {
        if (protect(old, fresh)) { return; }
        if (old.nodeType === 1) { old.removeAttribute('data-live-stale'); }
        if (old.nodeType !== fresh.nodeType || old.nodeName !== fresh.nodeName) {
            old.replaceWith(fresh.cloneNode(true)); return;
        }
        if (old.nodeType !== 1) {
            if (old.nodeValue !== fresh.nodeValue) { old.nodeValue = fresh.nodeValue; }
            return;
        }
        attributes(old, fresh);
        var keyed = new Map();
        Array.from(old.childNodes).forEach(function (n) { if (key(n)) { keyed.set(key(n), n); } });
        var cursor = old.firstChild;
        Array.from(fresh.childNodes).forEach(function (wanted) {
            // Client-only messages/toasts stay in place and do not shift the server's children.
            while (cursor && cursor.nodeType === 1 && cursor.matches('#live-update-status, [data-toasts], [data-live-feedback]')) { cursor = cursor.nextSibling; }
            var found = key(wanted) ? keyed.get(key(wanted)) : (cursor && !key(cursor) ? cursor : null);
            if (found) {
                if (found !== cursor) { old.insertBefore(found, cursor); }
                var after = found.nextSibling;
                morph(found, wanted);
                cursor = found.parentNode === old ? found.nextSibling : after;
            } else {
                var clone = wanted.cloneNode(true); old.insertBefore(clone, cursor);
            }
        });
        while (cursor) {
            var next = cursor.nextSibling;
            if (!protect(cursor, null)) { cursor.remove(); }
            cursor = next;
        }
        if (old.tagName === 'INPUT' && document.activeElement !== old) {
            if (old.type === 'checkbox' || old.type === 'radio') { old.checked = fresh.checked; }
            else if (old.type !== 'file') { old.value = fresh.value; }
        }
        if (old.tagName === 'TEXTAREA' && document.activeElement !== old) { old.value = fresh.value; }
    }
    function sortTables() {
        document.querySelectorAll('table[data-sortable]').forEach(function (table) {
            var th = table.querySelector('th[aria-sort="ascending"], th[aria-sort="descending"]');
            if (!th || !table.tBodies[0]) { return; }
            var index = th.cellIndex, ascending = th.getAttribute('aria-sort') === 'ascending', type = th.getAttribute('data-sort');
            function value(row) {
                var cell = row.cells[index], v = (cell.getAttribute('data-sort-value') || cell.textContent).trim();
                if (type === 'number') { var number = parseFloat(v.replace(/[^0-9.\-]/g, '')); return isNaN(number) ? -Infinity : number; }
                if (type === 'date') { return Date.parse(v) || -Infinity; }
                return v.toLowerCase();
            }
            Array.from(table.tBodies[0].rows).sort(function (a, b) {
                var x = value(a), y = value(b); return (x < y ? -1 : x > y ? 1 : 0) * (ascending ? 1 : -1);
            }).forEach(function (row) { table.tBodies[0].appendChild(row); });
        });
    }
    function apply(payload) {
        if (payload.role !== role) { stopped = true; notice('Your account permissions changed. Reload this page to continue.'); return; }
        deferred = false;
        var active = document.activeElement, scrollX = window.scrollX, scrollY = window.scrollY;
        Object.keys(payload.regions || {}).forEach(function (name) {
            var region = payload.regions[name];
            if (revisions.get(name) === region.revision) { return; }
            var current = document.querySelector('[data-refresh-region="' + name + '"]');
            if (!current) { return; }
            var parsed = new DOMParser().parseFromString(region.html, 'text/html');
            var fresh = parsed.querySelector('[data-refresh-region="' + name + '"]');
            if (fresh) { morph(current, fresh); }
            if (!deferred) { revisions.set(name, region.revision); }
        });
        if (window.CASDevU.initSortable) { window.CASDevU.initSortable(document); }
        if (window.CASDevU.initJoin) { window.CASDevU.initJoin(); }
        sortTables();
        if (active && !active.isConnected && active.tagName !== 'BODY') {
            document.getElementById('main').focus({preventScroll:true});
        }
        window.scrollTo(scrollX, scrollY);
        notice(deferred ? 'Updates are available for a request you are editing. Finish or discard your edits to see its latest details.' : '');
        if (deferred) {
            var discard = document.createElement('button');
            discard.type = 'button'; discard.className = 'btn btn-outline btn-sm';
            discard.textContent = 'Discard edits and update';
            discard.addEventListener('click', function () {
                if (!window.confirm('Discard unsaved changes in the updated requests, including any selected files?')) { return; }
                document.querySelectorAll('[data-live-stale]').forEach(function (record) {
                    if (record.tagName === 'FORM') { record.reset(); }
                    record.querySelectorAll('form').forEach(function (form) { form.reset(); });
                });
                revisions.clear(); poll();
            });
            status.append(' ', discard);
        }
        document.dispatchEvent(new CustomEvent('casdevu:updated'));
    }
    function schedule() {
        clearTimeout(timer);
        if (!stopped && !document.hidden) { timer = setTimeout(poll, 5000); }
    }
    async function poll() {
        if (controller || stopped || document.hidden || acting) { schedule(); return; }
        var url = window.location.href;
        controller = new AbortController();
        var timeout = setTimeout(function () { if (controller) { controller.abort(); } }, 12000);
        try {
            var response = await fetch(url, {credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-CASDevU-Live':'poll'}});
            var data = await response.json();
            if (response.status === 401 || response.status === 403 || response.status === 404) {
                stopped = true; notice(data.message || 'Your session or access changed. Sign in again to continue.'); return;
            }
            if (!response.ok || !data.ok || !data.regions) { throw new Error('Live response unavailable'); }
            if (url === window.location.href && !acting && !document.querySelector('[data-live-region][aria-busy="true"]')) { apply(data); }
        } catch (error) {
            if (!document.hidden) { notice('Live updates paused. Reconnecting… Your current information and edits are still here.'); }
        } finally {
            clearTimeout(timeout); controller = null; schedule();
        }
    }
    document.addEventListener('submit', async function (event) {
        var form = event.target;
        if (!event.defaultPrevented && form.matches('form[data-notifications-read]')) {
            event.preventDefault();
            try {
                var readResponse = await fetch(form.getAttribute('action'), {method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});
                if (!readResponse.ok) { throw new Error('Could not mark notifications read'); }
                revisions.clear(); poll();
            } catch (error) { notice('Notifications could not be marked as read. Please try again.'); }
            return;
        }
        if (event.defaultPrevented || !form.matches('form[data-live-action]')) { return; }
        event.preventDefault();
        if (acting || submitting.has(form)) { return; }
        var button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
        var values = new FormData(form);
        if (button && button.name) { values.set(button.name, button.value); }
        var original = button && button.textContent;
        submitting.add(form); acting = true;
        if (controller) { controller.abort(); }
        if (button) { button.disabled = true; button.textContent = button.getAttribute('data-loading') || 'Saving…'; }
        form.setAttribute('aria-busy', 'true');
        try {
            var response = await fetch(form.getAttribute('action') || window.location.href, {method:'POST',body:values,credentials:'same-origin',headers:{'X-CASDevU-Live':'action'}});
            var data = await response.json();
            var messages = data.messages || [{type: response.ok ? 'info' : 'error', message:data.message || 'The action could not be confirmed.'}];
            var feedback = form.querySelector('[data-live-feedback]');
            if (!feedback) { feedback = document.createElement('p'); feedback.setAttribute('data-live-feedback', ''); feedback.setAttribute('role', 'status'); form.prepend(feedback); }
            feedback.textContent = messages.map(function (m) { return m.message; }).join(' ');
            messages.forEach(function (m) { if (window.CASDevU.toast) { window.CASDevU.toast(m.type, m.message); } });
            if (data.ok) { form.reset(); }
            if (data.conflict) { form.setAttribute('data-live-conflict', ''); }
            if (response.status === 401 || response.status === 403) { stopped = true; notice(data.message || 'Your session or access changed. Sign in again to continue.'); }
        } catch (error) {
            var message = 'We could not confirm the action. Check its current status before trying again. Your notes are still here.';
            notice(message);
            if (window.CASDevU.toast) { window.CASDevU.toast('error', message); }
        } finally {
            submitting.delete(form); acting = false; form.removeAttribute('aria-busy');
            if (button) { button.disabled = false; button.textContent = original; }
            revisions.clear();
            // Finish the aborted read before requesting the committed state.
            setTimeout(poll, 30);
        }
    });
    document.addEventListener('visibilitychange', function () {
        clearTimeout(timer);
        if (document.hidden) { if (controller) { controller.abort(); } }
        else { poll(); }
    });
    window.addEventListener('online', poll);
    window.addEventListener('focus', function () { if (!stopped) { poll(); } });
    document.addEventListener('input', function () { if (deferred) { clearTimeout(timer); timer = setTimeout(poll, 500); } });
    document.addEventListener('reset', function () { if (deferred) { clearTimeout(timer); timer = setTimeout(poll, 50); } });
    window.CASDevU.refresh = function () { revisions.clear(); poll(); };
    schedule();
})();
