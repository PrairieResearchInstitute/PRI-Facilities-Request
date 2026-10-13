/* Work request intake: live duplicate candidates and the forced-look gate.
   The continue control stays inactive until candidates render or the
   search returns nothing (spec Section 6.2). Vanilla JS, no dependencies. */
(function () {
    'use strict';

    var building = document.getElementById('building_id');
    var type = document.getElementById('type_id');
    var title = document.getElementById('title');
    var box = document.getElementById('candidatebox');
    var list = document.getElementById('candidates');
    var btn = document.getElementById('continuebtn');
    var full = document.getElementById('fullform');
    var shown = document.getElementById('shown_ids');
    var phoneWarn = document.getElementById('phonewarn');

    if (!building) { return; }

    var timer = null;
    var lastKey = '';

    function debounceSearch() {
        clearTimeout(timer);
        timer = setTimeout(search, 300);
    }

    function search() {
        var b = building.value;
        if (!b) {
            box.hidden = true;
            btn.disabled = true;
            return;
        }
        var key = b + '|' + type.value + '|' + title.value;
        if (key === lastKey) { return; }
        lastKey = key;
        btn.disabled = true;
        var url = 'api/search_open.php?building_id=' + encodeURIComponent(b)
                + '&type_id=' + encodeURIComponent(type.value || '0')
                + '&q=' + encodeURIComponent(title.value || '');
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                render(data.candidates || []);
            })
            .catch(function () {
                list.innerHTML = '<p class="muted">Could not load open requests; you may continue.</p>';
                box.hidden = false;
                btn.disabled = false;
            });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function render(cands) {
        box.hidden = false;
        var ids = [];
        if (!cands.length) {
            list.innerHTML = '<p class="muted">No open requests for this building. Continue below.</p>';
        } else {
            var html = '';
            cands.forEach(function (c) {
                ids.push(c.id);
                var where = c.room ? 'Room ' + esc(c.room) : esc(c.location || '');
                html += '<div class="candidate">'
                     + '<strong><a href="detail.php?type=work&id=' + c.id + '">'
                     + esc(c.ticket) + ' — ' + esc(c.title) + '</a></strong>'
                     + '<div class="meta">' + where
                     + ' · ' + esc(c.status)
                     + ' · ' + c.confirmations + ' confirmation(s)'
                     + ' · filed ' + c.age_days + ' day(s) ago'
                     + ' · <a href="detail.php?type=work&id=' + c.id + '">this is my issue — confirm it</a>'
                     + '</div></div>';
            });
            list.innerHTML = html;
        }
        shown.value = ids.join(',');
        btn.disabled = false;   // gate satisfied: the queue has rendered
    }

    function phoneCheck() {
        var opt = type.options[type.selectedIndex];
        var isPhone = opt && opt.getAttribute('data-phone') === '1';
        phoneWarn.hidden = !isPhone;
        if (isPhone) {
            phoneWarn.textContent = opt.getAttribute('data-instruction')
                || 'This issue is handled by telephone, not by ticket.';
            btn.disabled = true;
            full.hidden = true;
        }
    }

    building.addEventListener('change', function () { lastKey = ''; debounceSearch(); });
    type.addEventListener('change', function () { phoneCheck(); debounceSearch(); });
    title.addEventListener('input', debounceSearch);

    btn.addEventListener('click', function () {
        full.hidden = false;
        btn.hidden = true;
        var desc = full.querySelector('textarea[name="description"]');
        if (desc) { desc.focus(); }
    });

    if (building.value) { search(); }
    phoneCheck();
}());
