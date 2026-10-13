@AGENTS.md

# PRI Facilities Request (PRIOps): drive copy

PHP web app (platform PHP track, D-003). AGENTS.md above holds the domain rules and is binding; this page adds the drive layout.

## Commands

| Command | Healthy output |
|---|---|
| `node tools/check.js` (the gate, read-only) | `CHECK OK` |
| `SETUP.bat` | writes config.local.json; `dataFolder saved: ..\..\data\pri-facilities-request` |
| `tools\php\php.exe tools\local_config.php show` | prints the data root, the storage folder and the engine |
| `tools\php\php.exe tools\pg\cluster.php status` (read-only) | the drive cluster's data folder, runtime bin, log, `address: 127.0.0.1:5433`, whether it runs, and which driver (node estate-pg.js or pg_ctl) |
| `tools\php\php.exe tools\pg\cluster.php start` | `start: already running ...` or `start: started ...`; every PostgreSQL command below needs the drive cluster running |
| `tools\php\php.exe -d extension=pdo_pgsql tests\run-pg.php` (writes facilities_test only) | `PG SUITE PASSED: ...` after two rounds |
| `tools\php\php.exe -d extension=pdo_pgsql tools\pg\migrate.php --database test --status` | lists the applied migrations |
| `tools\php\php.exe tests\identity.php` (no server; the gate runs it) | `All 39 identity checks passed.` |
| `tools\php\php.exe -d extension=pdo_pgsql tools\identity_seed.php --database test audit` (read-only) | `AUDIT OK: ...` or the count of NetIDs that need a look; `list` shows every grant |
| `py -3 tools\offline_check.py` | `ok nothing is loaded from anywhere but this folder` |
| `PRI Facilities Request.vbs` | the owner launches it; never launch it from a session (it starts tools/keepalive.php, which holds a LocalDB connection, or on PostgreSQL starts the drive cluster and its watcher) |
| `STOP SERVER.bat` | the owner runs it; ends this folder's PHP server and, on PostgreSQL, stops the drive cluster unless another app is connected |

## Layout

- `facilities/` document root; `facilities/lib/db.php` the connections; `facilities/lib/paths.php` resolves storage (`storage_dir()`).
- `sql/` schema scripts the installer runs; `tools/` maintenance, deck and capture scripts; `tools/php`, `tools/odbc` bundled runtime (git-ignored, protected).
- Storage (uploads, imports, GIS extracts, Studio backups) lives in `..\..\data\pri-facilities-request\storage`, named by `dataFolder` in the git-ignored `config.local.json`. Without it, storage falls back to `storage\` in the app folder.
- PHP's scratch (sessions, upload temporaries) lives in `..\..\data\pri-facilities-request\runtime` through `runtime_dir()`; the launchers pass `sys_temp_dir` and `upload_tmp_dir` with `-d` because no page can set them (AGENTS.md rule 15, D-007). Backups go to `..\..\data\pri-facilities-request\backups`.
- The ten SQL Server LocalDB databases are still on drive C:, the one thing left on the computer; moving them is owner question Q-001 in `reference\open-questions.md`.
- `reference\` (git-ignored): specs, C3PO and GIS source data, Visual Pack, the HAR capture.
- `intent.md`, `plan.md`, `handoff.md`, `docs/decisions.md`; agent guidance in `.claude/docs/`.

## Hard rules

- Never attach, detach, copy, start, stop or query the LocalDB databases from a prep or agent session. One exception (owner, 2026-10-11, D-012): during the PostgreSQL switch-over an agent session may read the ten registers with read-only SELECT queries through the port's loader (tools\pg\load.php), and nothing else; never write, attach, detach, back up or restart LocalDB.
- Never write in `..\..\data\pri-facilities-request` except through the running app.
- Never print or commit `config.php` (installer output) or `config.local.json`.
- A new storage reader or writer calls `storage_dir()`, never `app_root() . '\storage'`.

## Things Claude gets wrong here

- Building a path to `storage\` from `app_root()`: the drive copy then writes an empty folder inside the repository.
- Calling `runtime_dir()` from a session: it creates folders in the live data folder. Test against a scratch copy with its own `config.local.json`.
- Passing a `-d` ini value to PHP unquoted: it is read as ini text, so a path truncates at a tilde and PHP carries on with the wrong folder. Use `-d "key=\"value\""`.
- Taking AGENTS.md rules 1 and 2 (hosted Postgres on Radiant) as current: the owner retired them for the port (2026-10-04). The port runs on branch `port/postgres` against the estate's PostgreSQL 17 on the drive, 127.0.0.1 port 5433, never a PostgreSQL installed on the computer (platform D-028, D-011); no API server sits between them; live data stays on LocalDB until the owner sets `dataMode` to `postgres` (plan.md PG10).
- Running a PostgreSQL tool without `-d extension=pdo_pgsql`: the bundled php.ini loads only pdo_sqlsrv, and tools/php is protected, so the launchers and every PostgreSQL command pass it.
- Naming `--database live` from a session: only the owner switch-over writes the `facilities` database; sessions use `--database test`.
- Opening, printing or copying `pg.env`: the app reads it through `facilities/lib/pgenv.php` and nothing else does.
- Calling `PRI Facilities Request.vbs` or `start-local.bat` to test a change.
- Reading `current_user()['Role']` as the whole story: a campus group can lend a role for the request (D-014). Ask `role_at_least()` or `user_roles()`; write `StoredRole`, never `Role`, into a user row.
- Selecting from `work.app_groupaccess` with lower-case result names: the translator re-keys names it knows (`role` becomes `Role`). Alias every column in the spelling the code reads, as `auth_group_grants()` does.
- Unlocking the acting-user switcher for the server: the local login refuses any proxied or remote request by design; the server sets `AUTH_MODE=proxy`.
