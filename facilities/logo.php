<?php
declare(strict_types=1);

/**
 * The PRIOps lockup, large, on the banner navy - photographed for the
 * briefing deck's title slide and printable for anything else. The mark
 * is Pyrops, the lanternfly: PRIDE has its lion, PRIOps has an insect
 * genus, which for a natural history institute is exactly right.
 */

require_once __DIR__ . '/lib/db.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
// ?bare=1 drops the banner plate so the mark can be photographed on
// nothing. The title slide is already flat navy, and a lockup carrying
// its own gradient sits on that as a visible rectangle - which is what
// "the white margins around the logo" turned out to be once the stray
// pixels were trimmed off it.
$bare = isset($_GET['bare']);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>PRIOps</title>
<style>
    html, body { margin: 0; overflow: hidden;
                 background: <?= $bare ? 'transparent' : '#fff' ?>; }
    .banner { width: 1200px; height: 360px; display: flex; align-items: center;
              justify-content: center; gap: 38px;
              background: <?= $bare ? 'transparent' : '#131F4E' ?>; }
    /* Sampled from PRIDELogo.gif so the two marks are siblings rather
       than cousins: the lion is #FFFFFF, "PRI" is #0775BA and "DE" is
       #6A6E71. PRIOps takes the same three - white mark, blue PRI, grey
       Ops - and the plate PRIDE sits on is #131F4E. */
    .word { font-family: Arial, 'Segoe UI', sans-serif; font-weight: 800;
            font-size: 128px; letter-spacing: .04em; }
    .word b { color: #0775BA; }
    .word i { color: #6A6E71; font-style: normal; }
    .tag { position: absolute; left: 0; right: 0; top: 292px; text-align: center;
           color: #FFFFFF; font-family: Calibri, sans-serif; font-size: 24px;
           letter-spacing: .18em; }
</style></head><body>
<div class="banner">
    <img src="assets/priops.png" width="284" height="181" alt="">
    <div class="word"><b>PRI</b><i>Ops</i></div>
    <div class="tag">PRAIRIE&nbsp;RESEARCH&nbsp;INSTITUTE &middot; OPERATIONS</div>
</div>
</body></html>
