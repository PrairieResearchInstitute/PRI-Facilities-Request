/* Purchase intake: repeating product and funding lines, running totals,
   and the account lookup filling in the two people who must approve.

   Rows are cloned from the first one rather than built in JavaScript, so
   the markup lives in one place - the PHP - and this file only has to
   renumber and rewire what it copies. */
(function () {
    'use strict';

    var form = document.getElementById('purchaseform');
    if (!form) { return; }

    function money(n) {
        return (Math.round(n * 100) / 100).toFixed(2);
    }

    /* A number shown in two places - beside its table, and on the sticky
       line at the foot of the sheet - is still one number. */
    function setAll(id, text) {
        [document.getElementById(id), document.getElementById(id + '2')]
            .forEach(function (el) { if (el) { el.textContent = text; } });
    }

    function setAllClass(id, cls, text) {
        [document.getElementById(id), document.getElementById(id + '2')]
            .forEach(function (el) {
                if (el) { el.className = cls; el.textContent = text; }
            });
    }

    function num(el) {
        if (!el) { return 0; }
        var v = parseFloat(String(el.value).replace(/[$,%\s]/g, ''));
        return isNaN(v) ? 0 : v;
    }

    /* ---- product lines ---------------------------------------------- */
    var itemTable = document.getElementById('itemtable');

    function recalcItems() {
        var total = 0;
        itemTable.querySelectorAll('tr.itemrow').forEach(function (row, i) {
            row.querySelector('.rowno').textContent = String(i + 1);
            var amount = num(row.querySelector('.qty')) * num(row.querySelector('.cost'));
            row.querySelector('.amount').textContent = money(amount);
            total += amount;
        });
        setAll('grandtotal', money(total));
        return total;
    }

    /* ---- funding lines ---------------------------------------------- */
    var fundTable = document.getElementById('fundtable');

    function recalcFunds() {
        var total = 0;
        fundTable.querySelectorAll('tr.fundrow').forEach(function (row, i) {
            row.querySelector('.rowno').textContent = String(i + 1);
            total += num(row.querySelector('.fundamt'));
        });
        var split = (form.querySelector('input[name="split"]:checked') || {}).value || 'dollar';
        setAll('fundtotal', split === 'percent' ? money(total) + '%' : '$' + money(total));
        checkSplit(total, split);
    }

    /* The two numbers that have to agree, saying so while there is still
       something to be done about it. The server checks this too - this is
       a courtesy, not the rule. */
    function checkSplit(fundTotal, split) {
        var itemTotal = 0;
        itemTable.querySelectorAll('tr.itemrow').forEach(function (row) {
            itemTotal += num(row.querySelector('.qty')) * num(row.querySelector('.cost'));
        });

        if (split === 'item') {
            setAllClass('fundcheck', 'muted', 'divided by item number');
            return;
        }
        if (fundTotal === 0) {
            setAllClass('fundcheck', 'muted', '');
            return;
        }
        var want = split === 'percent' ? 100 : itemTotal;
        var off = Math.round((fundTotal - want) * 100) / 100;
        if (Math.abs(off) < 0.01) {
            setAllClass('fundcheck', 'on',
                split === 'percent' ? 'adds up to 100%' : 'matches the items');
        } else {
            setAllClass('fundcheck', 'off', (off > 0 ? 'over by ' : 'short by ')
                + (split === 'percent' ? money(Math.abs(off)) + '%'
                                       : '$' + money(Math.abs(off))));
        }
    }

    /* A cloned row carries the original's ids, which would make the type
       ahead fill the wrong boxes. Every id and every data-fill target is
       renumbered so each line stands on its own. */
    function renumberFundRow(row, n) {
        row.querySelectorAll('[id]').forEach(function (el) {
            el.id = el.id.replace(/_\d+$/, '_' + n);
        });
        var look = row.querySelector('[data-typeahead]');
        if (look) {
            look.setAttribute('data-fill',
                'account_pi:fund_pi_' + n + ',fund_manager:fund_fm_' + n
                + ',survey:fund_sv_' + n);
        }
    }

    function addRow(table, rowClass, after) {
        var rows = table.querySelectorAll('tr.' + rowClass);
        var last = rows[rows.length - 1];
        var copy = last.cloneNode(true);
        copy.querySelectorAll('input').forEach(function (el) {
            if (el.type !== 'hidden' || true) { el.value = ''; }
        });
        // Any list the type ahead left behind belongs to the row it came from.
        copy.querySelectorAll('ul.typeahead').forEach(function (el) { el.remove(); });
        last.parentNode.insertBefore(copy, last.nextSibling);
        if (after) { after(copy, rows.length); }
        /* A clone carries the markup but none of the listeners, so every
           lookup box in the new row has to be bound again - product lines
           as much as accounts. This is done after `after` has renumbered
           the fill targets, so the box binds knowing where it writes. */
        if (window.PriTypeAhead) {
            copy.querySelectorAll('[data-typeahead]').forEach(function (el) {
                delete el.__typeahead;
                window.PriTypeAhead.bind(el);
            });
        }
        return copy;
    }

    document.getElementById('additem').addEventListener('click', function () {
        var row = addRow(itemTable, 'itemrow');
        row.querySelector('.qty').value = '1';
        row.querySelector('.amount').textContent = '0.00';
        recalcItems();
        row.querySelector('input').focus();
    });

    document.getElementById('addfund').addEventListener('click', function () {
        var row = addRow(fundTable, 'fundrow', renumberFundRow);
        recalcFunds();
        row.querySelector('input').focus();
    });

    /* One handler on each table rather than one per row, so lines added
       later behave exactly like the first. */
    form.addEventListener('input', function (e) {
        if (e.target.closest('#itemtable')) { recalcItems(); recalcFunds(); }
        if (e.target.closest('#fundtable')) { recalcFunds(); }
    });

    /* Switching between dollars and percent changes what "adds up" means. */
    form.addEventListener('change', function (e) {
        if (e.target.name === 'split') { recalcFunds(); }
    });

    form.addEventListener('click', function (e) {
        var del = e.target.closest('.rowdel');
        if (!del) { return; }
        var row = del.closest('tr');
        var table = row.closest('table');
        var cls = row.classList.contains('itemrow') ? 'itemrow' : 'fundrow';
        // Never remove the last line; a form with no rows cannot be filled in.
        if (table.querySelectorAll('tr.' + cls).length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('input').forEach(function (el) { el.value = ''; });
        }
        recalcItems();
        recalcFunds();
    });

    /* The account lookup fills the two approvers. Their NetIDs travel in
       hidden fields so the server can reach them without guessing. */
    form.addEventListener('typeahead:choose', function (e) {
        var box = e.target;
        if (box.getAttribute('data-typeahead') !== 'cfop') { return; }
        var row = box.closest('tr');
        var d = e.detail || {};
        var pi = row.querySelector('input[name="fund_pi_netid[]"]');
        var fm = row.querySelector('input[name="fund_fm_netid[]"]');
        if (pi) { pi.value = d.account_pi_netid || ''; }
        if (fm) { fm.value = d.fund_manager_netid || ''; }
    });

    recalcItems();
    recalcFunds();
}());
