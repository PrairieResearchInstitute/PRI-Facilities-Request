#!/usr/bin/env node
// Self-check gate for PRI Facilities Request (drez-app-self-check-gate, item P19).
//   node tools/check.js      healthy output ends with: CHECK OK
// Read-only: opens no database (the SQL Server LocalDB files stay untouched),
// starts no server, writes nothing outside the system temporary folder, where
// tests/drive-invariance.php builds and removes its own scratch tree. Exits 1
// with the reason on any failure.
'use strict';
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const APP = path.resolve(__dirname, '..');
const ID = 'pri-facilities-request';
const DRIVE_DATA = path.resolve(APP, '..', '..', 'data', ID);
const failures = [];
const say = (s) => console.log(s);
const fail = (s) => { failures.push(s); say('FAIL  ' + s); };
const pass = (s) => say('ok    ' + s);

// 1. Portable settings: on the drive the app must read data\<id>, never its own folder.
const localCfgFile = path.join(APP, 'config.local.json');
const driveStorage = path.join(DRIVE_DATA, 'storage');
const driveHasData = fs.existsSync(driveStorage);
let localCfg = null;
if (fs.existsSync(localCfgFile)) {
  try { localCfg = JSON.parse(fs.readFileSync(localCfgFile, 'utf8').replace(/^﻿/, '')); }
  catch (e) { fail('config.local.json is not valid JSON: ' + e.message); }
}
if (driveHasData && !fs.existsSync(localCfgFile)) {
  fail('config.local.json is missing while ' + driveStorage + ' exists: the app would write into its own storage folder. Run SETUP.bat.');
} else if (localCfg) {
  const df = String(localCfg.dataFolder || '');
  if (/^([A-Za-z]:|[\\/])/.test(df)) fail('dataFolder carries a drive letter or root: ' + df);
  else if (driveHasData && !df) fail('config.local.json names no dataFolder while ' + driveStorage + ' exists. Run SETUP.bat.');
  else pass('config.local.json dataFolder: ' + (df || '(none: storage stays in the app folder)'));
} else {
  pass('no config.local.json and no drive data folder: storage stays in the app folder');
}

// 2. PHP: the bundled copy first, as the launchers do.
const phpCandidates = [process.env.DREZ_PHP, path.join(APP, 'tools', 'php', 'php.exe')].filter(Boolean);
let php = phpCandidates.find((p) => fs.existsSync(p));
if (!php) {
  const w = spawnSync(process.platform === 'win32' ? 'where' : 'which', ['php'], { encoding: 'utf8' });
  php = w.status === 0 ? w.stdout.split(/\r?\n/)[0].trim() : null;
}
if (!php) fail('PHP not found (tools\\php\\php.exe, DREZ_PHP or PATH); the path and lint checks did not run');

// 3. Resolve the storage folders with the app's own code, read-only.
if (php) {
  const r = spawnSync(php, [path.join(APP, 'tools', 'check_paths.php')], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  let probe = null;
  try { probe = JSON.parse(r.stdout); } catch (e) { fail('check_paths.php printed no JSON: ' + (r.stderr || r.stdout || '').trim().slice(0, 300)); }
  if (probe && !probe.ok) fail('the app could not resolve its storage: ' + probe.error);
  else if (probe) {
    say('read  data root ' + probe.dataRoot);
    say('read  uploads   ' + probe.uploads + ' (' + (probe.uploadCount || 0) + ' file(s))');
    say('read  imports   ' + probe.imports);
    say('read  gis       ' + probe.gis + ' (' + probe.gisFiles + ' csv)');
    if (driveHasData) {
      const want = fs.realpathSync(DRIVE_DATA).toLowerCase();
      if (String(probe.dataRoot).toLowerCase() !== want) fail('the app resolves storage to ' + probe.dataRoot + ', not ' + want);
      else pass('the app resolves storage to the drive data folder');
      if (fs.existsSync(path.join(driveStorage, 'uploads'))) {
        if (!probe.sample || !probe.sample.isFile) fail('file.php resolution of a stored upload found no file');
        else pass('file.php resolves ' + probe.sample.storedName + ' to ' + probe.sample.resolved + ' (' + probe.sample.bytes + ' bytes, sha256 ' + probe.sample.sha256 + ')');
      }
    }
  }
}

// 4. Installer output and local settings stay out of git.
const g = spawnSync('git', ['-c', 'safe.directory=*', 'ls-files', '--', 'config.php', 'config.local.json', '*.mdf', '*.ldf', '*.har'], { cwd: APP, encoding: 'utf8' });
if (g.status !== 0) fail('git ls-files failed: ' + (g.stderr || '').trim());
else if (g.stdout.trim()) fail('tracked but must not be: ' + g.stdout.trim().split(/\r?\n/).join(', '));
else pass('config.php, config.local.json, database files and captures are untracked');

// 5. Every tracked PHP file parses.
if (php) {
  const ls = spawnSync('git', ['-c', 'safe.directory=*', 'ls-files', '--', '*.php'], { cwd: APP, encoding: 'utf8' });
  const files = ls.stdout.split(/\r?\n/).filter(Boolean);
  const bad = files.filter((f) => spawnSync(php, ['-l', f], { cwd: APP, encoding: 'utf8', timeout: 30000 }).status !== 0);
  if (bad.length) fail('PHP lint failed: ' + bad.join(', '));
  else pass('PHP lint: ' + files.length + ' tracked file(s) parse');
}

// 6. Drive invariance: stored paths follow the drive under any letter (temporary folders only).
if (php) {
  const t = spawnSync(php, [path.join(APP, 'tests', 'drive-invariance.php')], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  const out = (t.stdout || '') + (t.stderr || '');
  const m = /All (\d+) drive-invariance checks passed\./.exec(out);
  if (t.status === 0 && m) pass(m[0]);
  else fail('tests/drive-invariance.php exited ' + t.status + ': ' + out.trim().split(/\r?\n/).slice(-3).join(' | '));
}

// 6b. NetID sign-in, without a server: the trusted header, the local login's refusals, roles.
if (php) {
  const t = spawnSync(php, [path.join(APP, 'tests', 'identity.php')], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  const out = (t.stdout || '') + (t.stderr || '');
  const m = /All (\d+) identity checks passed\./.exec(out);
  if (t.status === 0 && m) pass(m[0]);
  else fail('tests/identity.php exited ' + t.status + ': ' + out.trim().split(/\r?\n/).filter((l) => !l.startsWith('ok')).slice(-4).join(' | '));
}

// 6c. The server tier (Radiant): every setting from the environment, none from config.php or pg.env.
if (php) {
  const t = spawnSync(php, [path.join(APP, 'tests', 'server-env.php')], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  const out = (t.stdout || '') + (t.stderr || '');
  const m = /All (\d+) server-tier checks passed\./.exec(out);
  if (t.status === 0 && m) pass(m[0]);
  else fail('tests/server-env.php exited ' + t.status + ': ' + out.trim().split(/\r?\n/).filter((l) => !l.startsWith('ok')).slice(-4).join(' | '));
}

// 7. PostgreSQL port, without a server: the translation layer and the generated baseline.
if (php) {
  const t = spawnSync(php, [path.join(APP, 'tests', 'pg-translate.php')], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  const out = (t.stdout || '') + (t.stderr || '');
  const m = /All (\d+) translation checks passed\./.exec(out);
  if (t.status === 0 && m) pass(m[0]);
  else fail('tests/pg-translate.php exited ' + t.status + ': ' + out.trim().split(/\r?\n/).filter((l) => !l.startsWith('ok')).slice(-4).join(' | '));
  const l = spawnSync(php, ['-d', 'extension=pdo_sqlite', path.join(APP, 'tests', 'localdb-unchanged.php')], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  const lout = (l.stdout || '') + (l.stderr || '');
  const lm = /All (\d+) LocalDB checks passed\./.exec(lout);
  if (l.status === 0 && lm && !/^skip/m.test(lout)) pass(lm[0] + ' (the default engine sends SQL Server what it always did)');
  else fail('tests/localdb-unchanged.php: ' + lout.trim().split(/\r?\n/).filter((x) => !x.startsWith('ok')).slice(-4).join(' | '));
  const b = spawnSync(php, [path.join(APP, 'tools', 'pg', 'make_baseline.php'), '--check'], { cwd: APP, encoding: 'utf8', timeout: 60000 });
  const bout = ((b.stdout || '') + (b.stderr || '')).trim();
  if (b.status === 0) pass(bout.split(/\r?\n/).pop());
  else fail('the PostgreSQL baseline or catalogue no longer matches sql/: ' + bout.split(/\r?\n/).join(' | '));
}

// 8. PostgreSQL lives on the drive (platform D-028, app D-011), never in a service on C:.
//    Read-only: compares tools/pg/estate.php with the shared estate-pg.js and scans tracked
//    text; opens no database and starts nothing.
{
  let estate = APP;
  while (!fs.existsSync(path.join(estate, '.estate-live')) && path.dirname(estate) !== estate) estate = path.dirname(estate);
  const epgFile = path.join(estate, 'runtime', 'postgresql', 'estate-pg.js');
  if (!fs.existsSync(path.join(estate, '.estate-live'))) {
    pass('no .estate-live marker above the app (a copy outside the estate): drive PostgreSQL checks skipped');
  } else if (!fs.existsSync(epgFile)) {
    fail('the shared runtime script is missing: ' + epgFile);
  } else {
    const epg = require(epgFile);
    const src = fs.readFileSync(epgFile, 'utf8');
    if (epg.PORT !== 5433 || epg.HOST !== '127.0.0.1') fail('estate-pg.js listens on ' + epg.HOST + ':' + epg.PORT + ', not 127.0.0.1:5433');
    // tools/pg/cluster.php starts and stops the cluster itself when no Node exists; what it
    // passes must stay what estate-pg.js passes. On Windows that is the cmd.exe start line
    // from launch(), which replaced pg_ctl start on 16 Oct 2026 because pg_ctl opened a
    // window; pg_ctl stop and pg_isready are unchanged.
    const mirrored = ["'start \"\" /b ' + q(exePath) + ' -D ' + q(dataPath) + ' -p ' + portOf(p)",
      "['/d', '/s', '/c', ",
      "['stop', '-D', p.data, '-m', 'fast', '-w', '-t', '60']",
      "['-h', HOST, '-p', portOf(p), '-t', '3']"];
    const drift = mirrored.filter((m) => !src.includes(m));
    if (drift.length) fail('estate-pg.js changed how it starts or checks the cluster; update tools/pg/estate.php to match: ' + drift.join(' | '));
    if (php) {
      const r = spawnSync(php, ['-r', "require 'tools/pg/estate.php'; echo json_encode(['p' => estate_pg_paths(), 'port' => ESTATE_PG_PORT, 'host' => ESTATE_PG_HOST]);"], { cwd: APP, encoding: 'utf8', timeout: 30000 });
      let mine = null;
      try { mine = JSON.parse(r.stdout); } catch (e) { fail('tools/pg/estate.php printed no JSON: ' + (r.stderr || r.stdout || '').trim().slice(0, 300)); }
      if (mine) {
        const theirs = epg.paths();
        const diff = ['estate', 'bin', 'home', 'data', 'log'].filter((k) => String(mine.p[k]).toLowerCase() !== String(theirs[k]).toLowerCase());
        if (diff.length || mine.port !== epg.PORT || mine.host !== epg.HOST) fail('tools/pg/estate.php and estate-pg.js disagree on ' + (diff.join(', ') || 'the address'));
        else if (!drift.length && epg.PORT === 5433) pass('tools/pg/estate.php matches estate-pg.js: ' + mine.host + ':' + mine.port + ', runtime ' + mine.p.bin);
      }
    }
  }
  const pat = '5432|postgresql-x64-17|Program Files.PostgreSQL|ProgramFiles.*PostgreSQL';
  const gr = spawnSync('git', ['-c', 'safe.directory=*', 'grep', '-n', '-I', '-E', pat, '--', '.', ':!docs/decisions.md', ':!docs/handoffs', ':!tools/check.js'], { cwd: APP, encoding: 'utf8' });
  if (gr.status === 0 && gr.stdout.trim()) fail('tracked files still name the PostgreSQL service on C:: ' + gr.stdout.trim().split(/\r?\n/).slice(0, 5).join(' | '));
  else if (gr.status > 1) fail('git grep failed: ' + (gr.stderr || '').trim());
  else pass('no tracked file names the PostgreSQL service on C: (port 5432, postgresql-x64-17, Program Files)');
}

// 9. Launcher (D-018): tools/ensure-running.ps1 replaces the retired .vbs and parses; no .vbs is tracked.
{
  const launcher = path.join(APP, 'tools', 'ensure-running.ps1');
  const vbs = spawnSync('git', ['-c', 'safe.directory=*', 'ls-files', '--', '*.vbs'], { cwd: APP, encoding: 'utf8' });
  const vbsTracked = vbs.status === 0 && vbs.stdout.trim();
  if (vbsTracked) fail('a .vbs launcher is tracked again (platform D-037, app D-018): ' + vbs.stdout.trim().split(/\r?\n/).join(', '));
  if (!fs.existsSync(launcher)) fail('tools/ensure-running.ps1 is missing: the desktop shortcut has nothing to run');
  else if (process.platform === 'win32') {
    const ps = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
      '$e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($env:LAUNCHER, [ref]$null, [ref]$e); if ($e) { $e | ForEach-Object { $_.Message }; exit 1 }'],
      { env: Object.assign({}, process.env, { LAUNCHER: launcher }), encoding: 'utf8', timeout: 60000 });
    if (ps.status !== 0) fail('tools/ensure-running.ps1 does not parse: ' + ((ps.stdout || '') + (ps.stderr || '')).trim().slice(0, 300));
    else if (!vbsTracked) pass('launcher: tools/ensure-running.ps1 parses; no .vbs tracked');
  }
}

// The authorship credit (drez-ui-elements rule 27): ui_bottom() in facilities/lib/ui.php
// prints it in every page's footer, and the NOTICE at the repository root carries it.
{
  const CREDIT = 'Developed by M. J. Dreslik, with Claude Opus 5';
  const read = (f) => { try { return fs.readFileSync(path.join(APP, f), 'utf8'); } catch (_) { return null; } };
  const ui = read('facilities/lib/ui.php') || '';
  const bottom = ui.slice(ui.indexOf('function ui_bottom'));
  if (bottom.includes('<span class="credit">' + CREDIT + '</span></footer>')) pass('credit: the footer carries the credit line');
  else fail('credit: ui_bottom() in facilities/lib/ui.php lacks the footer line "' + CREDIT + '"');
  const notice = read('NOTICE');
  if (notice && notice.includes(CREDIT)) pass('credit: NOTICE carries the credit');
  else fail('credit: NOTICE is missing or lacks "' + CREDIT + '"');
}

if (failures.length) { say('CHECK FAILED: ' + failures.length + ' problem(s)'); process.exit(1); }
say('CHECK OK');
