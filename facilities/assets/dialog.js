/*
 * PriDialog: the application's own ask, confirm and notify, and the
 * data-confirm interception that replaces onsubmit="return confirm(...)".
 * Plain JavaScript, no library, loaded once by ui_bottom().
 *
 * Why it exists (Q-27, PFR-39): a native confirm() cannot be styled, cannot
 * be read by the page's own wording rules, and on a phone it arrives as a
 * browser chrome box that does not look like the application at all.
 *
 * What it must not break: the two forms it replaces both carry their
 * instruction in the SUBMIT BUTTON's name and value (action=ds_token_clear,
 * action=list_remove). A form submitted from script drops that pair, so the
 * server would receive the form with no action and do nothing. Every path
 * here re-submits through requestSubmit(button) when the browser has it, and
 * otherwise writes the button's own name and value into a hidden field.
 */
(function () {
    'use strict';

    var OPEN = null;      // the dialog on screen
    var RETURN_TO = null; // what had focus before it opened

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function close(result, resolve) {
        if (!OPEN) { return; }
        var back = OPEN;
        OPEN = null;
        document.removeEventListener('keydown', onKey, true);
        back.remove();
        if (RETURN_TO && document.contains(RETURN_TO)) { RETURN_TO.focus(); }
        RETURN_TO = null;
        resolve(result);
    }

    function onKey(e) {
        if (!OPEN) { return; }
        if (e.key === 'Escape') { e.preventDefault(); OPEN.cancel(); return; }
        if (e.key !== 'Tab') { return; }
        // A modal keeps the keyboard inside itself.
        var f = OPEN.querySelectorAll('button, input, [href], select, textarea');
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    /**
     * The shell every dialog shares. $kind is 'ask', 'confirm' or 'notify';
     * onOk turns the dialog into the value the promise settles with.
     */
    function open(opts, kind) {
        return new Promise(function (resolve) {
            if (OPEN) { OPEN.cancel(); }
            RETURN_TO = document.activeElement;

            var back = el('div', 'dialog-backdrop');
            var box = el('div', 'dialog');
            var id = 'dlg' + Date.now();
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-labelledby', id);

            var head = el('div', 'dialog-head');
            var h = el('h2', null, opts.title || '');
            h.id = id;
            var x = el('button', 'dialog-x');
            x.type = 'button';
            x.setAttribute('aria-label', 'Close');
            x.innerHTML = '&times;';
            head.appendChild(h);
            head.appendChild(x);

            var body = el('div', 'dialog-body');
            if (opts.message) { body.appendChild(el('p', null, opts.message)); }

            var field = null;
            if (kind === 'ask') {
                var lab = el('label', null, opts.label || '');
                field = el('input');
                field.type = 'text';
                field.value = opts.value || '';
                if (opts.placeholder) { field.placeholder = opts.placeholder; }
                if (opts.required) { field.required = true; }
                lab.appendChild(field);
                body.appendChild(lab);
            }

            var actions = el('div', 'actions');
            var cancel = null;
            if (kind !== 'notify') {
                cancel = el('button', null, opts.cancelLabel || 'Cancel');
                cancel.type = 'button';
                actions.appendChild(cancel);
            }
            var ok = el('button', opts.danger ? 'danger' : 'primary', opts.okLabel || 'OK');
            ok.type = 'button';
            actions.appendChild(ok);

            box.appendChild(head);
            box.appendChild(body);
            box.appendChild(actions);
            back.appendChild(box);
            document.body.appendChild(back);

            var no = kind === 'ask' ? null : (kind === 'confirm' ? false : undefined);
            back.cancel = function () { close(no, resolve); };
            x.addEventListener('click', back.cancel);
            if (cancel) { cancel.addEventListener('click', back.cancel); }
            back.addEventListener('mousedown', function (e) { if (e.target === back) { back.cancel(); } });
            ok.addEventListener('click', function () {
                if (kind === 'ask') {
                    if (opts.required && field.value.trim() === '') { field.focus(); return; }
                    close(field.value, resolve);
                } else {
                    close(kind === 'confirm' ? true : undefined, resolve);
                }
            });

            OPEN = back;
            document.addEventListener('keydown', onKey, true);
            (field || ok).focus();
        });
    }

    window.PriDialog = {
        ask: function (o) { return open(o || {}, 'ask'); },
        confirm: function (o) { return open(o || {}, 'confirm'); },
        notify: function (o) { return open(o || {}, 'notify'); }
    };

    /* ---------------- data-confirm ---------------- */

    var GOING = null;   // the form already answered for, so the ask runs once

    function asks(form, button) {
        var src = (button && button.hasAttribute('data-confirm')) ? button : form;
        if (!src || !src.hasAttribute('data-confirm')) { return null; }
        return {
            node: src,
            message: src.getAttribute('data-confirm') || '',
            title: src.getAttribute('data-confirm-title') || 'Please confirm',
            okLabel: src.getAttribute('data-confirm-ok') || 'OK',
            cancelLabel: src.getAttribute('data-confirm-cancel') || 'Cancel',
            danger: src.hasAttribute('data-confirm-danger')
        };
    }

    // event.submitter is not in every browser this may meet, so the last
    // submit control clicked is remembered as the fallback.
    var LAST = null;
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button, input[type=submit], input[type=image]') : null;
        if (b && (!b.type || b.type === 'submit' || b.type === 'image')) { LAST = b; }
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form === GOING) { GOING = null; return; }
        var button = e.submitter || (LAST && LAST.form === form ? LAST : null);
        var q = asks(form, button);
        if (!q) { return; }
        e.preventDefault();

        PriDialog.confirm({
            title: q.title, message: q.message,
            okLabel: q.okLabel, cancelLabel: q.cancelLabel, danger: q.danger
        }).then(function (yes) {
            if (!yes) { return; }
            if (!form.querySelector('input[name=confirmed]')) {
                var c = document.createElement('input');
                c.type = 'hidden';
                c.name = 'confirmed';
                c.value = '1';
                form.appendChild(c);
            }
            GOING = form;
            if (form.requestSubmit && button) {
                // Keeps the button's own name and value, and its formnovalidate.
                form.requestSubmit(button);
                return;
            }
            if (button && button.name) {
                var k = document.createElement('input');
                k.type = 'hidden';
                k.name = button.name;
                k.value = button.value;
                form.appendChild(k);
            }
            form.submit();
        });
    }, true);
}());
