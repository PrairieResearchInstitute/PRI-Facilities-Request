<?php
/**
 * A bench for the odometer reader.
 *
 * Real dashboards are the only real test, and there are none yet. What
 * this does instead is draw seven segment displays on a canvas and see
 * whether the reader gets them back - clean first, then progressively
 * less clean: dimmer, noisier, rotated, blurred, and with the clutter a
 * real dash has around the numbers.
 *
 * It cannot prove the reader works on a photograph. It can prove the
 * reader is not fooling itself, and it says which conditions break it,
 * which is worth knowing before anybody relies on it.
 *
 * Test fixture. Delete once there are real photographs to check against.
 */
require_once __DIR__ . '/lib/ui.php';
handle_user_switch();
current_user();
?>
<!doctype html>
<meta charset="utf-8">
<title>Odometer reader bench</title>
<style>
 body { font: 14px system-ui, sans-serif; margin: 1rem; background: #f5f4f1; }
 table { border-collapse: collapse; background: #fff; }
 th, td { border: 1px solid #ccc; padding: .3rem .5rem; text-align: left; }
 canvas { border: 1px solid #999; vertical-align: middle; }
 .ok { color: #1b6b2f; font-weight: bold; }
 .bad { color: #a4210c; font-weight: bold; }
 .meh { color: #8a6d00; }
 #summary { font-size: 1.1rem; margin: 1rem 0; }
</style>
<h1>Odometer reader bench</h1>
<p>Synthetic seven segment displays, drawn and then read back. Real dashboards will behave worse
   than the clean cases and better than the cruel ones.</p>
<div id="summary">running...</div>
<table id="out">
  <tr><th>Condition</th><th>Display</th><th>Wanted</th><th>Read as</th><th></th></tr>
</table>

<script src="assets/odometer.js"></script>
<script>
(async () => {
  if (!window.PriOdometer) {
    document.getElementById('summary').textContent = 'assets/odometer.js did not load.';
    return;
  }
  const read = window.PriOdometer.read;

  const SEG = {
    '0':'1110111','1':'0010010','2':'1011101','3':'1011011','4':'0111010',
    '5':'1101011','6':'1101111','7':'1010010','8':'1111111','9':'1111011'
  };

  /* Draw a digit the way a dashboard does: thick bars with gaps. */
  function drawDigit(ctx, d, x, y, w, h, colour) {
    const bits = SEG[d], t = Math.max(2, Math.round(w * 0.22)), g = t * 0.35;
    ctx.fillStyle = colour;
    const bars = [
      [x + g, y, w - 2*g, t],                       // a
      [x, y + g, t, h/2 - g*1.5],                   // b
      [x + w - t, y + g, t, h/2 - g*1.5],           // c
      [x + g, y + h/2 - t/2, w - 2*g, t],           // d
      [x, y + h/2 + g/2, t, h/2 - g*1.5],           // e
      [x + w - t, y + h/2 + g/2, t, h/2 - g*1.5],   // f
      [x + g, y + h - t, w - 2*g, t]                // g
    ];
    bars.forEach((b, i) => { if (bits[i] === '1') ctx.fillRect(...b); });
  }

  function makeDash(value, opts) {
    const o = Object.assign({w: 640, h: 360, digitH: 90, fg: '#dfe8ff', bg: '#0b0d12',
                             noise: 0, rotate: 0, blur: 0, clutter: false, dim: 1}, opts);
    const cv = document.createElement('canvas');
    cv.width = o.w; cv.height = o.h;
    const ctx = cv.getContext('2d');
    ctx.fillStyle = o.bg; ctx.fillRect(0, 0, o.w, o.h);

    if (o.clutter) {
      ctx.strokeStyle = '#3a4152'; ctx.lineWidth = 6;
      ctx.beginPath(); ctx.arc(120, 180, 95, 0, Math.PI*2); ctx.stroke();
      ctx.beginPath(); ctx.arc(520, 180, 95, 0, Math.PI*2); ctx.stroke();
      ctx.fillStyle = '#7a3b3b'; ctx.fillRect(300, 60, 26, 26);
      ctx.fillStyle = '#3b6a3b'; ctx.fillRect(340, 60, 26, 26);
      ctx.fillStyle = '#555b6a'; ctx.font = '20px sans-serif';
      ctx.fillText('MPH', 96, 300); ctx.fillText('RPM', 496, 300);
    }

    ctx.save();
    ctx.translate(o.w/2, o.h/2);
    ctx.rotate(o.rotate * Math.PI/180);
    const dw = o.digitH * 0.58, gap = dw * 0.28;
    const total = value.length * dw + (value.length - 1) * gap;
    let x = -total/2;
    const colour = o.fg;
    ctx.globalAlpha = o.dim;
    for (const ch of value) {
      drawDigit(ctx, ch, x, -o.digitH/2, dw, o.digitH, colour);
      x += dw + gap;
    }
    ctx.globalAlpha = 1;
    ctx.restore();

    if (o.blur > 0) {
      const tmp = document.createElement('canvas');
      tmp.width = o.w; tmp.height = o.h;
      const tc = tmp.getContext('2d');
      tc.filter = 'blur(' + o.blur + 'px)';
      tc.drawImage(cv, 0, 0);
      ctx.clearRect(0, 0, o.w, o.h);
      ctx.drawImage(tmp, 0, 0);
    }
    if (o.noise > 0) {
      const d = ctx.getImageData(0, 0, o.w, o.h);
      for (let i = 0; i < d.data.length; i += 4) {
        const n = (Math.random() - 0.5) * o.noise * 255;
        d.data[i] += n; d.data[i+1] += n; d.data[i+2] += n;
      }
      ctx.putImageData(d, 0, 0);
    }
    return cv;
  }

  const cases = [
    ['clean, six digits',        '128476', {}],
    ['clean, five digits',       '41208',  {}],
    ['clean, with a 1 in it',    '110234', {}],
    ['clean, lots of eights',    '888888', {}],
    ['dim display',              '128476', {dim: 0.45}],
    ['light noise',              '128476', {noise: 0.10}],
    ['heavy noise',              '128476', {noise: 0.30}],
    ['slightly blurred',         '128476', {blur: 1.5}],
    ['badly blurred',            '128476', {blur: 5}],
    ['tilted 3 degrees',         '128476', {rotate: 3}],
    ['tilted 8 degrees',         '128476', {rotate: 8}],
    ['dash clutter around it',   '128476', {clutter: true}],
    ['clutter, dim and noisy',   '128476', {clutter: true, dim: 0.5, noise: 0.12}],
    ['dark digits on pale',      '128476', {fg: '#101014', bg: '#c9cdd6'}],
    ['small in the frame',       '128476', {digitH: 40}],
  ];

  let pass = 0, wrong = 0, declined = 0;
  const out = document.getElementById('out');
  for (const [what, want, opts] of cases) {
    const cv = makeDash(want, opts);
    let got = null;
    try { got = read(cv); } catch (e) { got = 'threw: ' + e.message; }

    const row = out.insertRow();
    row.insertCell().textContent = what;
    const shown = document.createElement('canvas');
    shown.width = 200; shown.height = 200 * cv.height / cv.width;
    shown.getContext('2d').drawImage(cv, 0, 0, shown.width, shown.height);
    row.insertCell().appendChild(shown);
    row.insertCell().textContent = want;
    row.insertCell().textContent = got === null ? '(declined)' : got;
    const verdict = row.insertCell();
    if (got === want) { verdict.innerHTML = '<span class="ok">right</span>'; pass++; }
    else if (got === null) { verdict.innerHTML = '<span class="meh">declined</span>'; declined++; }
    else { verdict.innerHTML = '<span class="bad">WRONG</span>'; wrong++; }
  }

  document.getElementById('summary').innerHTML =
    '<strong>' + pass + '</strong> read correctly, <strong>' + declined
    + '</strong> declined to guess, <strong class="' + (wrong ? 'bad' : '') + '">' + wrong
    + '</strong> read wrongly, out of ' + cases.length + '.'
    + ' <br>Declining is the safe failure. Reading wrongly is the one that matters.';
  window.__odoResult = {pass, declined, wrong, total: cases.length};
})();
</script>
