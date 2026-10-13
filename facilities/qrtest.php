<?php
declare(strict_types=1);

/**
 * A bench for the QR writer: render a set of codes and let the browser's
 * own barcode reader decode them back. Writing an encoder without a
 * decoder to check it against is writing something that looks right.
 *
 * This page is a test fixture and is deleted once the mileage labels are
 * in use.
 */
require_once __DIR__ . '/lib/qr.php';

$cases = [
    'https://facilities.prairie.illinois.edu/m.php?v=1073',
    'https://facilities.prairie.illinois.edu/m.php?v=PRI-4417',
    'm.php?v=12',
    'HELLO WORLD',
    'https://facilities.prairie.illinois.edu/m.php?v=ISWS-TRAILER-04&t=1',
    str_repeat('A', 100),
];
?>
<!doctype html>
<meta charset="utf-8">
<title>QR bench</title>
<style>
 body { font: 14px system-ui, sans-serif; margin: 1rem; }
 .case { display: flex; gap: 1rem; align-items: center; margin-bottom: 1rem; }
 .case svg { border: 1px solid #ccc; }
 code { background: #f2f2f2; padding: 0 .3rem; }
 .ok { color: #1b6b2f; font-weight: bold; }
 .bad { color: #a4210c; font-weight: bold; }
</style>
<h1>QR bench</h1>
<p id="support"></p>
<?php foreach ($cases as $i => $text): ?>
<div class="case" data-i="<?= $i ?>" data-text="<?= htmlspecialchars($text, ENT_QUOTES) ?>">
    <?= qr_svg($text, 4, 'M') ?>
    <div>
        <div><code><?= htmlspecialchars(mb_substr($text, 0, 70)) ?></code></div>
        <div class="result">not decoded yet</div>
    </div>
</div>
<?php endforeach; ?>

<script>
/* Decode each rendered code with the browser's own reader, so the check
   is independent of the code that drew it. */
(async () => {
    const note = document.getElementById('support');
    if (!('BarcodeDetector' in window)) {
        note.textContent = 'This browser has no BarcodeDetector, so nothing can be checked here.';
        return;
    }
    const formats = await BarcodeDetector.getSupportedFormats();
    if (!formats.includes('qr_code')) {
        note.textContent = 'BarcodeDetector is present but does not do QR.';
        return;
    }
    note.textContent = 'Decoding with the browser barcode reader...';
    const det = new BarcodeDetector({formats: ['qr_code']});

    let pass = 0, fail = 0;
    for (const el of document.querySelectorAll('.case')) {
        const want = el.dataset.text;
        const svg = el.querySelector('svg');
        const out = el.querySelector('.result');

        // Rasterise the SVG at 4x so the reader has pixels to work with.
        const blob = new Blob([new XMLSerializer().serializeToString(svg)],
                              {type: 'image/svg+xml'});
        const url = URL.createObjectURL(blob);
        const img = new Image();
        await new Promise(r => { img.onload = r; img.onerror = r; img.src = url; });

        const scale = 4;
        const cv = document.createElement('canvas');
        cv.width = img.width * scale;
        cv.height = img.height * scale;
        const ctx = cv.getContext('2d');
        ctx.imageSmoothingEnabled = false;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.drawImage(img, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(url);

        try {
            const found = await det.detect(cv);
            if (found.length && found[0].rawValue === want) {
                out.innerHTML = '<span class="ok">decoded, matches</span>';
                pass++;
            } else if (found.length) {
                out.innerHTML = '<span class="bad">decoded as</span> <code>'
                    + found[0].rawValue.slice(0, 70) + '</code>';
                fail++;
            } else {
                out.innerHTML = '<span class="bad">nothing found</span>';
                fail++;
            }
        } catch (e) {
            out.innerHTML = '<span class="bad">reader threw: ' + e.message + '</span>';
            fail++;
        }
    }
    note.textContent = pass + ' of ' + (pass + fail) + ' decoded correctly.';
    window.__qrResult = {pass, fail};
})();
</script>
