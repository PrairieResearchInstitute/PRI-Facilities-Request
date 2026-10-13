/* Move request items.

   Choosing the origin room loads that room's tagged property and lists it
   as rows to tick. Everything the property record already knows is filled
   in and read only in practice, because retyping a serial number is how
   inventories drift. Anything not on the record is added as one extra
   row. */
(function () {
    'use strict';

    var rows = document.getElementById('itemrows');
    var tmpl = document.getElementById('rowtemplate');
    var addBtn = document.getElementById('addmanual');
    var hint = document.getElementById('itemshint');
    var fromGroup = document.getElementById('fromgroup');
    if (!rows || !tmpl) { return; }

    function clearEmpty() {
        var e = rows.querySelector('.emptyrow');
        if (e) { e.remove(); }
    }

    function wire(tr) {
        var inc = tr.querySelector('.inc');
        var incHidden = tr.querySelector('.inchidden');
        var desc = tr.querySelector('.desc');
        inc.addEventListener('change', function () {
            incHidden.value = inc.checked ? '1' : '0';
            tr.classList.toggle('rowoff', !inc.checked);
            // A row that is not moving must not block submission.
            desc.required = inc.checked;
        });
        var techBox = tr.querySelector('.techbox');
        var techHidden = tr.querySelector('.techhidden');
        techBox.addEventListener('change', function () {
            techHidden.value = techBox.checked ? '1' : '0';
        });
        return tr;
    }

    function addRow(item) {
        clearEmpty();
        var frag = tmpl.content.cloneNode(true);
        var tr = frag.querySelector('tr');
        if (item) {
            tr.querySelector('.desc').value = item.description || '';
            tr.querySelector('.ptag').value = item.tag || '';
            tr.querySelector('.serial').value = item.serial || '';
            tr.querySelector('.src').value = item.id || '';
            // Who holds it now comes from the property record. It stays
            // editable, because records go stale, but it is never
            // assumed to be the person filling in the form.
            tr.querySelector('.custodian').value = item.person || '';
            // Nothing is moving until somebody says so.
            var inc = tr.querySelector('.inc');
            inc.checked = false;
            tr.querySelector('.inchidden').value = '0';
            tr.classList.add('rowoff');
            tr.querySelector('.desc').required = false;
            if (/computer|laptop|printer|monitor|server|network|scanner/i.test(item.description || '')) {
                tr.querySelector('.techbox').checked = true;
                tr.querySelector('.techhidden').value = '1';
                tr.querySelector('select[name="item_itemtype[]"]').value = 'Computer / IT equipment';
            }
        }
        rows.appendChild(frag);
        wire(rows.lastElementChild);
        return rows.lastElementChild;
    }

    function loadRoom(code) {
        rows.innerHTML = '';
        if (!code) {
            rows.innerHTML = '<tr class="emptyrow"><td colspan="8" class="muted">'
                + 'No mapped room chosen. Use "Add something not listed" to describe items by hand.'
                + '</td></tr>';
            return;
        }
        rows.innerHTML = '<tr class="emptyrow"><td colspan="8" class="muted">Loading…</td></tr>';
        fetch('api/rooms.php?room_code=' + encodeURIComponent(code))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                rows.innerHTML = '';
                var items = d.items || [];
                if (!items.length) {
                    rows.innerHTML = '<tr class="emptyrow"><td colspan="8" class="muted">'
                        + 'No tagged property is recorded in that room. Add items by hand below.'
                        + '</td></tr>';
                    hint.textContent = 'Nothing is on the property record for that room, so add '
                        + 'what is moving by hand.';
                    return;
                }
                items.forEach(addRow);
                hint.textContent = items.length + ' tagged item(s) are recorded in that room. '
                    + 'Tick what is moving; add anything not listed below.';
            })
            .catch(function () {
                rows.innerHTML = '<tr class="emptyrow"><td colspan="8" class="muted">'
                    + 'Could not load that room\'s property. Add items by hand.</td></tr>';
            });
    }

    if (fromGroup) {
        fromGroup.addEventListener('roomchange', function (e) {
            loadRoom(e.detail.code);
        });
    }
    addBtn.addEventListener('click', function () {
        var tr = addRow(null);
        tr.querySelector('.desc').focus();
    });
}());
