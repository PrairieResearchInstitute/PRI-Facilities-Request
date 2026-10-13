/* The property update form shows only the boxes the chosen change uses.

   Every block is in the page from the start and is hidden rather than
   built, so switching between "it moved" and "the record is wrong" costs
   nothing and loses nothing that has been typed. */
(function () {
    'use strict';

    var form = document.getElementById('propform');
    if (!form) { return; }

    function chosen() {
        var r = form.querySelector('input[name="kind"]:checked');
        return r ? r.value : '';
    }

    function apply() {
        var kind = chosen();
        Array.prototype.forEach.call(form.querySelectorAll('[data-block]'), function (block) {
            var kinds = (block.getAttribute('data-kinds') || '').split(/\s+/);
            var show = kind !== '' && kinds.indexOf(kind) !== -1;
            block.hidden = !show;
            /* A hidden box is not part of the question being asked, so it
               does not travel with the answer. Disabling rather than
               clearing means the typing is still there if the person
               changes their mind back. */
            Array.prototype.forEach.call(
                block.querySelectorAll('input, select, textarea'),
                function (el) { el.disabled = !show; });
        });
        Array.prototype.forEach.call(form.querySelectorAll('[data-only]'), function (el) {
            el.hidden = el.getAttribute('data-only') !== kind;
        });

        /* The same two blocks mean different things depending on why they
           are open, and a heading that says so is worth more than a
           tooltip. */
        var loc = form.querySelector('[data-block="location"] h3');
        if (loc) {
            loc.textContent = kind === 'move' ? 'Where is it now?'
                            : kind === 'register' ? 'Where did it go?'
                            : 'Where is it?';
        }
        var who = form.querySelector('[data-block="person"] h3');
        if (who) {
            who.textContent = kind === 'reassign' ? 'Who is responsible for it now?'
                            : 'Who is responsible for it?';
        }
        var submit = form.querySelector('button.primary');
        if (submit) { submit.disabled = kind === ''; }
    }

    form.addEventListener('change', function (e) {
        if (e.target.name === 'kind') { apply(); }
    });
    apply();
}());
