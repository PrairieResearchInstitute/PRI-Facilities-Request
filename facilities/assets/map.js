/* Building map: click a room on the floor plan, then read everything known
   about it across the space data and the three request databases.
   Vanilla JS, no dependencies. */
(function () {
    'use strict';

    var svg = document.getElementById('floorplan');
    var panel = document.getElementById('roompanel');
    if (!svg || !panel) { return; }

    var selected = null;
    var activeTab = 'responsibility';
    var current = null;

    function esc(v) {
        var d = document.createElement('div');
        d.textContent = (v === null || v === undefined || v === '') ? '' : String(v);
        return d.innerHTML;
    }

    function money(n) {
        if (n === null || n === undefined || n === '') { return '—'; }
        return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function pick(el) {
        if (selected) { selected.classList.remove('selected'); }
        selected = el;
        el.classList.add('selected');
        panel.innerHTML = '<p class="muted">Loading…</p>';
        fetch('api/room.php?code=' + encodeURIComponent(el.getAttribute('data-code')))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { panel.innerHTML = '<p class="bad">' + esc(d.error) + '</p>'; return; }
                current = d;
                render();
            })
            .catch(function () { panel.innerHTML = '<p class="bad">Could not load that room.</p>'; });
    }

    var TABS = [
        ['responsibility', 'Responsible'],
        ['work', 'Work orders'],
        ['inventory', 'Inventory'],
        ['keys', 'Keys'],
        ['moves', 'Moves'],
        ['safety', 'Safety']
    ];

    function render() {
        var d = current, r = d.room;
        var html = '<h3>Room ' + esc(r.room) + (r.name ? ' — ' + esc(r.name) : '') + '</h3>'
            + '<p class="muted">' + esc(r.code) + ' · floor ' + esc(r.floor)
            + (r.area ? ' · ' + Number(r.area).toLocaleString() + ' sq ft' : '') + '</p>';

        html += '<div class="tabs subtabs">';
        TABS.forEach(function (t) {
            var count = '';
            if (t[0] === 'work') { count = ' (' + (d.work_orders.work.length + d.work_orders.shop.length) + ')'; }
            if (t[0] === 'inventory') { count = ' (' + d.inventory.items.length + ')'; }
            if (t[0] === 'keys') { count = ' (' + d.keys.length + ')'; }
            if (t[0] === 'moves') { count = ' (' + d.moves.length + ')'; }
            if (t[0] === 'safety') { count = ' (' + d.safety.length + ')'; }
            html += '<a href="#" data-tab="' + t[0] + '" class="' + (activeTab === t[0] ? 'active' : '') + '">'
                 + t[1] + count + '</a>';
        });
        html += '</div><div class="tabbody">' + body() + '</div>';
        panel.innerHTML = html;

        panel.querySelectorAll('[data-tab]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                activeTab = a.getAttribute('data-tab');
                render();
            });
        });
    }

    function rows(list, cols, empty) {
        if (!list.length) { return '<p class="muted">' + empty + '</p>'; }
        var h = '<div class="scroll-x"><table><tr>';
        cols.forEach(function (c) { h += '<th>' + esc(c[0]) + '</th>'; });
        h += '</tr>';
        list.forEach(function (item) {
            h += '<tr>';
            cols.forEach(function (c) { h += '<td>' + c[1](item) + '</td>'; });
            h += '</tr>';
        });
        return h + '</table></div>';
    }

    function body() {
        var d = current;
        if (activeTab === 'responsibility') {
            var resp = d.responsibility;
            var h = '<table>'
                + '<tr><th>Survey</th><td>' + esc(resp.survey || 'unknown')
                + ' <span class="muted">' + esc(resp.survey_source) + '</span></td></tr>'
                + '<tr><th>Principal investigator</th><td>' + (resp.pi ? esc(resp.pi)
                    : '<span class="muted">not assigned</span>') + '</td></tr>'
                + '<tr><th>Safety contact</th><td>' + (resp.safety_contact ? esc(resp.safety_contact)
                    : '<span class="muted">not assigned</span>') + '</td></tr>'
                + '<tr><th>Department</th><td>' + esc(d.room.department || '—') + '</td></tr>'
                + '<tr><th>Division</th><td>' + esc(d.room.division || '—') + '</td></tr>'
                + '<tr><th>Category</th><td>' + esc(d.room.category || '—') + '</td></tr>'
                + '<tr><th>Use</th><td>' + esc(d.room.use || '—') + '</td></tr>'
                + (d.room.collection ? '<tr><th>Collection</th><td>' + esc(d.room.collection) + '</td></tr>' : '')
                + '</table>';
            h += '<h4>Assigned occupants (' + d.responsibility.occupants.length + ')</h4>';
            h += rows(d.responsibility.occupants, [
                ['Name', function (o) { return esc(o.PersonName); }],
                ['NetID', function (o) { return esc(o.NetID); }],
                ['Org', function (o) { return esc(o.Org); }],
                ['Type', function (o) { return esc(o.EmpType); }],
                ['Primary', function (o) { return o.PrimaryOffice === 'Y' ? 'yes' : ''; }]
            ], 'Nobody is assigned to this room in C3PO.');
            return h;
        }

        if (activeTab === 'work') {
            var w = d.work_orders;
            var h = '<p><strong>' + money(w.total_spent) + '</strong> estimated across '
                + (w.work.length + w.shop.length) + ' order(s), ' + w.open_count + ' still open.</p>';
            h += '<h4>Facilities work orders</h4>';
            h += rows(w.work, [
                ['Ticket', function (o) { return '<a href="detail.php?type=work&id=' + o.id + '">' + esc(o.ticket) + '</a>'; }],
                ['Title', function (o) { return esc(o.title); }],
                ['Type', function (o) { return esc(o.type); }],
                ['Status', function (o) { return esc(String(o.status).replace(/_/g, ' ')); }],
                ['Amount', function (o) { return money(o.amount); }],
                ['Filed', function (o) { return esc(o.created); }],
                ['Closed', function (o) { return esc(o.closed || ''); }]
            ], 'No facilities work orders recorded for this room.');
            h += '<h4>Shop work orders</h4>';
            h += rows(w.shop, [
                ['Ticket', function (o) { return '<a href="detail.php?type=shop&id=' + o.id + '">' + esc(o.ticket) + '</a>'; }],
                ['Work', function (o) { return esc(o.title); }],
                ['Status', function (o) { return esc(String(o.status).replace(/_/g, ' ')); }],
                ['Amount', function (o) { return money(o.amount); }],
                ['Filed', function (o) { return esc(o.created); }]
            ], 'No shop work orders recorded for this room.');
            return h;
        }

        if (activeTab === 'inventory') {
            var i = d.inventory;
            var h = '<p><strong>' + i.items.length + '</strong> tagged item(s), acquisition '
                + money(i.total_cost) + ', current value ' + money(i.total_value) + '.</p>';
            return h + rows(i.items, [
                ['PTag', function (o) { return esc(o.PropertyTag); }],
                ['Description', function (o) { return esc(o.Description); }],
                ['Model', function (o) { return esc(o.Model); }],
                ['Serial', function (o) { return esc(o.SerialNumber); }],
                ['Holder', function (o) { return esc(o.Person); }],
                ['Acquired', function (o) { return esc(o.AcquiredOn); }],
                ['Cost', function (o) { return money(o.Cost); }],
                ['Value', function (o) { return money(o.DepreciatedValue); }]
            ], 'No tagged property recorded in this room.');
        }

        if (activeTab === 'keys') {
            return rows(d.keys, [
                ['Key', function (o) { return esc(o.KeyCode); }],
                ['Holder', function (o) { return esc(o.HolderName); }],
                ['NetID', function (o) { return esc(o.HolderNetID); }],
                ['Issued', function (o) { return esc(o.IssuedOn); }],
                ['Notes', function (o) { return esc(o.Notes); }]
            ], 'No key holders loaded. Key assignments arrive as a CSV export; the source PDF '
             + 'cannot be read reliably.');
        }

        if (activeTab === 'moves') {
            return rows(d.moves, [
                ['Ticket', function (o) { return '<a href="detail.php?type=move&id=' + o.id + '">' + esc(o.ticket) + '</a>'; }],
                ['Direction', function (o) { return esc(o.direction); }],
                ['Item', function (o) { return esc(o.item); }],
                ['Type', function (o) { return esc(o.move_type); }],
                ['PTag', function (o) { return esc(o.ptag); }],
                ['Needed', function (o) { return esc(o.needed); }],
                ['Status', function (o) { return esc(String(o.status).replace(/_/g, ' ')); }]
            ], 'No move orders touch this room.');
        }

        return rows(d.safety, [
            ['Kind', function (o) { return esc(String(o.Kind).replace(/_/g, ' ')); }],
            ['Tag', function (o) { return esc(o.Tag); }],
            ['Brand', function (o) { return esc(o.Brand); }],
            ['Model', function (o) { return esc(o.Model); }],
            ['Serial', function (o) { return esc(o.SerialNumber); }],
            ['Status', function (o) { return esc(o.Status); }],
            ['Last tested', function (o) { return esc(o.LastTested); }]
        ], 'No safety equipment recorded in this room.');
    }

    /* Deep links: ?sel=1073_01_1027&tab=inventory opens the map with
       that room already picked and that tab up - so a room's manifest
       can be sent as a URL, and the briefing deck can photograph it. */
    var q = new URLSearchParams(window.location.search);
    var deepSel = q.get('sel');
    if (deepSel) {
        var deepEl = svg.querySelector('[data-code="' + deepSel + '"]');
        if (deepEl) {
            activeTab = q.get('tab') || 'responsibility';
            pick(deepEl);
        }
    }

    svg.addEventListener('click', function (e) {
        var t = e.target.closest('path.room');
        if (t) { pick(t); }
    });
    svg.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('room')) {
            e.preventDefault();
            pick(e.target);
        }
    });
}());
