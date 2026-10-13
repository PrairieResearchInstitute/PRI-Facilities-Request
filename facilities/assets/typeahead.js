/* Type ahead, the way the current PRI forms behave: type part of an
   account or a surname, wait for the list, pick the match.

   Mark an input with data-typeahead="cfop" or "person". Sibling fields
   named in data-fill are filled from the chosen match, so the survey,
   account PI and fund manager arrive with the account rather than being
   typed. Keyboard works: arrows move, Enter picks, Escape closes. */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function TypeAhead(input) {
        this.input = input;
        this.what = input.getAttribute('data-typeahead');
        this.fill = {};
        var spec = input.getAttribute('data-fill') || '';
        spec.split(',').forEach(function (pair) {
            var bits = pair.split(':');
            if (bits.length === 2) { this.fill[bits[0].trim()] = bits[1].trim(); }
        }, this);

        this.list = document.createElement('ul');
        this.list.className = 'typeahead';
        this.list.setAttribute('role', 'listbox');
        this.list.hidden = true;
        input.insertAdjacentElement('afterend', this.list);

        this.matches = [];
        this.active = -1;
        this.timer = null;

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        var self = this;
        input.addEventListener('input', function () { self.schedule(); });
        input.addEventListener('keydown', function (e) { self.key(e); });
        input.addEventListener('blur', function () {
            // Let a click on an option land before the list disappears.
            setTimeout(function () { self.close(); }, 180);
        });
    }

    TypeAhead.prototype.schedule = function () {
        clearTimeout(this.timer);
        var self = this;
        this.timer = setTimeout(function () { self.search(); }, 250);
    };

    TypeAhead.prototype.search = function () {
        var q = this.input.value.trim();
        if (q.length < 2) { this.close(); return; }
        var self = this;
        fetch('api/lookup.php?what=' + encodeURIComponent(this.what)
              + '&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (d) { self.render(d.matches || []); })
            .catch(function () { self.close(); });
    };

    TypeAhead.prototype.render = function (matches) {
        this.matches = matches;
        this.active = -1;
        if (!matches.length) {
            this.list.innerHTML = '<li class="none">No match. Check the value, or ask support '
                + 'if you believe it should be there.</li>';
            this.open();
            return;
        }
        var self = this;
        this.list.innerHTML = matches.map(function (m, i) {
            return '<li role="option" data-i="' + i + '">'
                 + '<span class="ta-label">' + esc(m.label) + '</span>'
                 + (m.hint ? '<span class="ta-hint">' + esc(m.hint) + '</span>' : '')
                 + '</li>';
        }).join('');
        Array.prototype.forEach.call(this.list.querySelectorAll('li[data-i]'), function (li) {
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                self.choose(parseInt(li.getAttribute('data-i'), 10));
            });
        });
        this.open();
    };

    TypeAhead.prototype.open = function () {
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
    };

    TypeAhead.prototype.close = function () {
        this.list.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
    };

    TypeAhead.prototype.key = function (e) {
        if (this.list.hidden) { return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var n = this.matches.length;
            if (!n) { return; }
            this.active = (this.active + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
            Array.prototype.forEach.call(this.list.querySelectorAll('li[data-i]'), function (li, i) {
                li.classList.toggle('active', i === this.active);
            }, this);
        } else if (e.key === 'Enter' && this.active >= 0) {
            e.preventDefault();
            this.choose(this.active);
        } else if (e.key === 'Escape') {
            this.close();
        }
    };

    TypeAhead.prototype.choose = function (i) {
        var m = this.matches[i];
        if (!m) { return; }
        this.input.value = m.value;
        Object.keys(this.fill).forEach(function (key) {
            var el = document.getElementById(this.fill[key]);
            if (el) { el.value = m[key] == null ? '' : m[key]; }
        }, this);
        this.close();
        this.input.dispatchEvent(new CustomEvent('typeahead:choose', {
            bubbles: true, detail: m
        }));
    };

    function bind(el) {
        if (el && !el.__typeahead) { el.__typeahead = new TypeAhead(el); }
    }

    function wire() {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-typeahead]'), bind);
    }
    document.addEventListener('DOMContentLoaded', wire);
    if (document.readyState !== 'loading') { wire(); }

    // Forms that add rows after the page has loaded - a purchase request
    // can name any number of accounts - need to bind the new boxes.
    window.PriTypeAhead = { bind: bind, wire: wire };
}());
