/* Shop intake: CFOP lookup fills survey, PI, and fund manager read-only. */
(function () {
    'use strict';

    var cfop = document.getElementById('cfop');
    var state = document.getElementById('cfopstate');
    var survey = document.getElementById('cfop_survey');
    var pi = document.getElementById('cfop_pi');
    var fm = document.getElementById('cfop_fm');
    if (!cfop) { return; }

    var timer = null;

    function clearFields() {
        survey.value = '';
        pi.value = '';
        fm.value = '';
    }

    // The type ahead fills the read-only fields when a match is picked.
    // This still runs so a pasted or restored account resolves without
    // the person having to open the list.
    function lookup() {
        var v = cfop.value.trim();
        if (!v) { clearFields(); return; }
        fetch('api/cfop.php?cfop=' + encodeURIComponent(v))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.found) {
                    survey.value = d.survey;
                    pi.value = d.account_pi;
                    fm.value = d.fund_manager;
                    state.textContent = 'Account matched: ' + d.cfop;
                    state.className = 'muted good';
                } else if (!document.querySelector('.typeahead:not([hidden])')) {
                    clearFields();
                    state.textContent = 'That account is not in the list, so it is not valid for '
                        + 'this form. Send a support request to configure-me@example.edu '
                        + 'if you believe that is an error.';
                    state.className = 'muted bad';
                }
            })
            .catch(function () { /* leave state as-is */ });
    }

    cfop.addEventListener('typeahead:choose', function (e) {
        state.textContent = 'Account matched: ' + e.detail.value
            + (e.detail.fund_type ? ' (' + e.detail.fund_type + ')' : '');
        state.className = 'muted good';
    });
    cfop.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(lookup, 500);
    });
    if (cfop.value) { lookup(); }

    // The two kinds of shop request are different jobs, so the form
    // swaps rather than reveals: everything belonging to the other kind
    // is hidden AND disabled, so a half-filled section cannot be
    // submitted or block validation from the section actually in use.
    var kinds = document.querySelectorAll('input[name="request_kind"]');
    var panes = document.querySelectorAll('[data-kind]');

    function chosen() {
        for (var i = 0; i < kinds.length; i++) {
            if (kinds[i].checked) { return kinds[i].value; }
        }
        return 'general';
    }

    function sync() {
        var want = chosen();
        panes.forEach(function (pane) {
            var on = pane.getAttribute('data-kind') === want;
            pane.hidden = !on;
            // Disabled fields are not posted, which is what keeps the
            // unused half of the form out of the way of the used half.
            pane.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !on;
            });
        });
    }

    kinds.forEach(function (k) { k.addEventListener('change', sync); });
    sync();

    // A vehicle request arriving from the front page card starts on the
    // vehicle side without anybody having to click twice.
    if (/[?&]kind=vehicle/.test(window.location.search)) {
        var v = document.getElementById('kind_vehicle');
        if (v) { v.checked = true; sync(); }
    }
}());
