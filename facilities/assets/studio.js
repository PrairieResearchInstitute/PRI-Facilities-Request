/* The Studio's editor behaviours.

   No library, because there is none to have on PRI shared hosting and
   none is needed: a textarea, numbers beside it, and the three habits
   anyone editing code expects - Tab indents, Ctrl+S saves, and leaving
   with unsaved work asks first. */

(function () {
  "use strict";

  var code = document.getElementById("studiocode");
  var gutter = document.getElementById("studiogutter");
  var form = document.getElementById("studioform");
  var filter = document.getElementById("studiofilter");

  /* Line numbers, redrawn only when the count changes. */
  function drawGutter() {
    if (!code || !gutter) return;
    var n = code.value.split("\n").length;
    if (gutter.dataset.n === String(n)) return;
    gutter.dataset.n = String(n);
    var out = [];
    for (var i = 1; i <= n; i++) out.push(i);
    gutter.textContent = out.join("\n");
  }

  if (code) {
    drawGutter();
    code.addEventListener("input", function () { drawGutter(); dirty = true; });
    code.addEventListener("scroll", function () { gutter.scrollTop = code.scrollTop; });

    /* Tab indents rather than leaving the box. Shift+Tab outdents the
       line. Without this, editing PHP in a textarea is unusable. */
    code.addEventListener("keydown", function (e) {
      if (e.key !== "Tab") return;
      e.preventDefault();
      var s = code.selectionStart, t = code.selectionEnd, v = code.value;
      if (e.shiftKey) {
        var lineStart = v.lastIndexOf("\n", s - 1) + 1;
        if (v.substr(lineStart, 4) === "    ") {
          code.value = v.slice(0, lineStart) + v.slice(lineStart + 4);
          code.selectionStart = code.selectionEnd = Math.max(lineStart, s - 4);
        }
      } else {
        code.value = v.slice(0, s) + "    " + v.slice(t);
        code.selectionStart = code.selectionEnd = s + 4;
      }
      drawGutter();
      dirty = true;
    });
  }

  /* Unsaved work should not leave quietly. */
  var dirty = false;
  if (form) {
    form.addEventListener("submit", function () { dirty = false; });
  }
  window.addEventListener("beforeunload", function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = "";
  });

  /* Ctrl+S / Cmd+S saves, because every editor does. */
  document.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
      if (!form) return;
      e.preventDefault();
      form.submit();
    }
  });

  /* Filtering the rail matches the whole path, so "lib/ui" finds it and
     so does "ui.php". A folder with nothing left in it hides itself. */
  if (filter) {
    filter.addEventListener("input", function () {
      var q = filter.value.trim().toLowerCase();
      document.querySelectorAll(".studio-group").forEach(function (g) {
        var shown = 0;
        g.querySelectorAll("a.studio-file").forEach(function (a) {
          var hit = q === "" || (a.dataset.path || "").toLowerCase().indexOf(q) !== -1;
          a.parentElement.hidden = !hit;
          if (hit) shown++;
        });
        g.hidden = shown === 0;
        if (q !== "" && shown > 0) g.open = true;
      });
    });
  }
})();

/* The database form: a Windows account has no user name or password to
   give, so the two boxes go quiet rather than inviting an entry that
   would be discarded on save. */
(function () {
    var auth = document.getElementById('dbauth');
    if (!auth) { return; }
    var user = document.querySelector('.studio-dbform [name=username]');
    var pass = document.querySelector('.studio-dbform [name=password]');
    function sync() {
        var sql = auth.value === 'sql';
        [user, pass].forEach(function (box) {
            if (!box) { return; }
            box.disabled = !sql;
            box.closest('label').style.opacity = sql ? '' : '0.5';
        });
    }
    auth.addEventListener('change', sync);
    sync();
})();
