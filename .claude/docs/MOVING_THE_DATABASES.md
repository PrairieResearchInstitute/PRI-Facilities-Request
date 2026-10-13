# Putting the databases on one computer

The application no longer cares where the databases are. Everything that
used to be a code change is now a form: **Studio -> Database**.

This document is the other half - what has to be true on the server, which
is the part the application cannot do for itself.

## Why the current setup cannot be shared

The server is `(localdb)\MSSQLLocalDB`. LocalDB is a user-mode instance: it
starts on demand, listens on a named pipe owned by one Windows account, and
has no network listener at all. There is no firewall rule, no port, and no
setting that makes another computer able to reach it. It has to be replaced,
not configured.

The ten registers currently hold about 670 MB.

## What to install

**SQL Server Express** is free and its 10 GB per-database ceiling is far
above what these use. During setup:

- Choose a **named instance** (`SQLEXPRESS` is the convention) or the
  default instance. Either works; the name goes in the Server box.
- Choose **Mixed Mode authentication**. This matters: the computers in the
  Institute are not on a domain, so a Windows login would have to exist,
  with the same password, on every machine that runs the application. A SQL
  login is one account, defined once, on the server.

After setup, in SQL Server Configuration Manager:

- **Enable TCP/IP** for the instance and restart the service. It is off by
  default and nothing else in this list works without it.
- If it is a named instance, either **start SQL Server Browser** (so clients
  can find it by name) or give the instance a **fixed port** and put that
  port in the form.
- Open the port in Windows Firewall - 1433 for a default instance, or the
  fixed port you chose.

## Moving the data

Ten databases, all named `PRI_Facilities*`. On the machine that has them
now, back each one up; on the new server, restore each one under the same
name. The names must not change - the application asks for them by name.

    BACKUP DATABASE PRI_FacilitiesWork TO DISK = '<backup folder>\Work.bak' WITH INIT;
    -- ... and the other nine
    RESTORE DATABASE PRI_FacilitiesWork FROM DISK = '<backup folder>\Work.bak';

## The login the application uses

One login, with rights on all ten:

    CREATE LOGIN app_priops WITH PASSWORD = '...', CHECK_POLICY = ON;

then in each of the ten databases:

    CREATE USER app_priops FOR LOGIN app_priops;
    ALTER ROLE db_datareader ADD MEMBER app_priops;
    ALTER ROLE db_datawriter ADD MEMBER app_priops;
    ALTER ROLE db_ddladmin   ADD MEMBER app_priops;   -- only if Studio will run schema changes

`db_owner` is not needed and should not be granted.

## Pointing the application at it

Studio -> Database. Fill in the server, choose **SQL login**, give the user
name and password, and press **Test**. Test connects and reports the round
trip in milliseconds without writing anything.

**Save** connects first and refuses to write settings that do not work -
saving a bad server would lock everyone out of the application, including
that page. It writes all ten entries at once, since they share a server and
differ only in database name.

Each computer running the application keeps its own `config.php`, so this is
done once per machine.

## What each computer still needs

The application is a folder and travels with one, but two things do not:

- **PHP with `pdo_sqlsrv`.** It is bundled at `tools\php`, so this is
  handled - the launcher looks there first.
- **ODBC Driver 18 for SQL Server.** This must be registered with Windows; it cannot run straight from the folder. It does **not** need the Microsoft MSI, though - that installer can demand a reboot when a previous attempt left one pending. Instead the driver DLL and the VC++ runtime it needs are bundled at `tools\odbcd`, and `tools\odbc\install-driver.cmd` registers them with one administrator prompt and **no reboot** (it copies them to `%ProgramData%\PRIOps\odbc` and points the ODBC registry there). The launcher runs this for you when the driver is missing, and the app's own error page names it. The MSI is kept as a fallback only. Once per computer.

## What the Database screen tells you

- Every register, the database it resolves to, whether it answered, and how
  long it took. The time is a real connection plus one statement - what
  every page pays.
- A register the config never names is marked as such. It is still reached,
  using the shared server settings and its standard name, exactly as `db()`
  resolves it.
- A red panel while the server is LocalDB, because that is the one setting
  that cannot serve another computer.

Errors are shown as the driver reported them, with the `SQLSTATE` and vendor
prefixes stripped and no part of the connection string included - "No such
host is known", "Login failed for user", "Login timeout expired".
