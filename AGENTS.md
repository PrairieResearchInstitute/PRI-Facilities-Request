# Instructions for AI coding assistants

PRIOps: the Prairie Research Institute's request system. Ten registers —
work, move, shop, purchasing, keys, fleet, space, equipment, incidents and
safety plans — behind one front door.

Read this before the first edit. Most of these rules exist because
something took a day to work out, and several were learned twice.

## Rules

1. **The databases are hosted: Postgres on Radiant, reached with
   university sign-in.** One database holding TEN SCHEMAS, one per
   register. No foreign key crosses a schema; registers refer to each
   other by natural key only. Every query goes through
   `facilities/lib/db.php` — `db_for()` and `APP_TABLE_HOME` select a
   SCHEMA where they used to select a database.

2. **The application folder no longer carries the data.** It carries the
   code, PHP and the launcher. Copying the folder to another machine no
   longer copies the registers, and two people working at once is now
   ordinary rather than dangerous.

   *Until the port completes, rules 1 and 2 of the previous edition still
   stand: everything stays in the folder, and one machine at a time.*

3. *(In force until the port completes.)* **"Login failed" from LocalDB is almost never about credentials.** It
   means the instance is stopped, or an engine it has forgotten is holding
   the instance and serving a half-initialised `master` in which every
   database looks missing. Read
   `%LOCALAPPDATA%\Microsoft\Microsoft SQL Server Local DB\Instances\MSSQLLocalDB\error.log`
   before theorising — the real reason is in there, not on the screen.

4. *(In force until the port completes.)* **Recover LocalDB in this order:** check `sqllocaldb info`; if it says
   Stopped while a `sqlservr.exe` whose command line contains `LocalDB` is
   running, stop that one process, then `sqllocaldb start MSSQLLocalDB`.
   Do not conclude the databases were lost — on 22 Sep 2026 all ten were
   still attached the whole time.

5. **Never give a database connection a short login timeout.** (The reasoning is LocalDB-specific, but the rule outlives it.) A cold
   LocalDB start brings up ten databases before it answers. `LoginTimeout=5`
   was set in September 2026 and turned a slow start into a hard failure,
   and probably into the orphaned-engine state as well. Local instances get
   45 seconds.

5a. *(In force until the port completes.)* **A restarted LocalDB leaves the running server holding a dead pipe.**
    This is the cause of the "Login failed" that kept coming back. LocalDB
    idles out, starts again with a NEW named pipe, and the long-lived
    `php -S` process still has the old one cached in the driver - so every
    request fails while a freshly started process connects perfectly.
    Proven 28 Sep 2026: with LocalDB untouched and Running, restarting only
    the web server turned 503 into 200. `tools/keepalive.php`, started by
    the launcher, holds one connection open so the instance never idles out
    and never changes its pipe. If you ever see this again, restarting the
    application fixes it - restarting LocalDB alone does not.

5b. *(In force until the port completes.)* **Never let two processes make
    their first LocalDB connection at the same moment.** On 5 Oct 2026 the
    launcher woke the instance and then started the web server AND the
    keep-alive within three seconds. Two first connections while the
    instance is still settling spawns a second engine that `sqllocaldb`
    loses track of: it then reports Stopped while the orphan holds the
    instance, and every page says "Login failed". The keep-alive added by
    rule 5a was itself the second racer. The launcher now waits on
    `tools/dbready.php` - one process, one proven query - before starting
    anything else, and the keep-alive waits 20 seconds before its first
    connection. Verified cold: Stopped and 0 engines to Running and exactly
    1 engine, pages 200.

6. **A port being busy is not the same as our server being there, and
   silence is not the same as free.** Windows lets `php -S` bind a port
   another server already holds, then hands connections to one of them at
   random. Decide a port is free only from a TCP listener check, never from
   an HTTP probe. Bind and open `127.0.0.1`, never `localhost` — that name
   is `::1` first and `127.0.0.1` second, so one URL can reach two
   different servers.

7. **Do not kill whatever is holding 8080.** It is usually another of the
   user's applications, mid-work. Step to the next free port.

8. **The launcher is not optional.** This is PHP: it must be executed by a
   server, so it cannot be opened as a file. `PRI Facilities Request.vbs`
   starts the server and opens the browser. `start-local.bat` is the same
   thing with a console, for seeing errors.

9. **Never hand-edit `config.php`.** The application writes it. Change
   settings through Studio → Database, which proves a connection before
   saving and refuses settings that do not work.

10. **Every weight, threshold and limit is a setting**, read from
    `app.Settings` — never a number in the code.

11. **Build the decks with the bundled PHP, `tools\php\php.exe`.** It
    carries `pdo_sqlsrv`, so the deck tools reach the databases at build
    time with nothing installed outside this folder. An earlier edition of
    this rule named a PHP on one developer's C: drive, on the belief that
    the bundled copy lacked the driver. The belief was wrong, and the path
    broke the moment the app ran from anywhere else. Decks are finished
    only when `py -3 tools/deck_gate.py` passes. See
    `.claude/docs/DECK_RULES_AND_FAILURES.md`, which is binding.

12. **Screenshots must be readable.** Text on a slide renders at
    `12.5 * (placed_inches / capture_px) * 72` points and nothing may land
    under 12pt. Photographing a whole scrolling page and shrinking it to
    fit is the mistake that keeps recurring.

13. **Verify in the built artefact, not in the source.** Unzip the `.pptx`,
    re-request the page, read the file back. "I changed the generator" is
    not evidence the output changed.

14. **Report what actually happened.** If a check was skipped, say so. If a
    claim was disproven by a test, say that too, rather than quietly
    dropping it.

15. **Nothing goes on the computer** (owner, 8 Oct 2026; standard v2
    section B). No path under C:, the user profile, `%APPDATA%`,
    `%LOCALAPPDATA%`, `%ProgramData%` or `%TEMP%`. Scratch included: PHP's
    own defaults put session files and every uploaded photograph in the
    profile temp folder, so `runtime_dir()` names `<data folder>\runtime`
    and the launchers pass `sys_temp_dir` and `upload_tmp_dir` with `-d`,
    because those two are PHP_INI_SYSTEM and no page can set them. Two
    exceptions, both installed Microsoft software rather than our files:
    the ODBC driver and the LocalDB engine. The ten database files are the
    one thing left on C:, and that is owner question Q-001, not a licence.
    A `-d` value needs quoting as `-d "key=\"value\""`: PHP reads it as ini
    text, and an unquoted path truncated at a tilde once and carried on
    with the wrong folder rather than failing. See D-007.

## Stack

- PHP 8.3 with `pdo_sqlsrv`, bundled at `tools\php` — travels with the folder
- Postgres on Radiant: one database, ten schemas, university sign-in
  *(until the port completes: SQL Server LocalDB, ten databases)*
- Microsoft ODBC Driver 18 — installer and a no-reboot registration script
  in `tools\odbc`; one admin run per machine
- No framework, no package manager, no build step. Plain PHP and one CSS file.

## Commands

```bat
rem run it
"PRI Facilities Request.vbs"

rem run it with a console, to see errors
start-local.bat

rem move the databases into the folder (app closed, one machine)
"tools\php\php.exe" tools\move_data_into_folder.php --go

rem decks
"tools\php\php.exe" tools\make_presentation.php
py -3 tools\deck_gate.py

rem prove it still renders with no internet
py -3 tools\offline_check.py
```
