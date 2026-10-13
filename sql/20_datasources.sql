-- ============================================================
-- PRI Facilities Request System
-- 20_datasources.sql
--   The three campus exports the app depends on: PRIDE active grants,
--   the employee file, and the org chart. All three sit behind Shibboleth
--   and none of them can be fetched by a server, so what is recorded here
--   is not a feed but a memory: when each was last loaded, from which
--   file, how many rows it carried, and how soon it goes stale. That is
--   what turns "somebody should update this" into a visible, nagging fact.
-- Work database.
-- ============================================================

IF OBJECT_ID('ref.DataSources', 'U') IS NULL
EXEC('
CREATE TABLE ref.DataSources (
    SourceKey     NVARCHAR(40)  NOT NULL PRIMARY KEY,
    Label         NVARCHAR(120) NOT NULL,
    Description   NVARCHAR(400) NULL,
    SourceUrl     NVARCHAR(400) NULL,   -- where a human goes to pull it
    FilePattern   NVARCHAR(120) NULL,   -- how the download is usually named
    RefreshDays   INT NOT NULL DEFAULT 30,
    LastLoadedAt  DATETIME2 NULL,
    LastLoadedBy  NVARCHAR(64)  NULL,
    LastFileName  NVARCHAR(260) NULL,
    LastFileHash  NVARCHAR(64)  NULL,   -- so the same file is not loaded twice
    LastRowCount  INT NULL,
    LastNote      NVARCHAR(400) NULL,
    IsActive      BIT NOT NULL DEFAULT 1,
    SortOrder     INT NOT NULL DEFAULT 0
);');
GO

-- Every load, kept so a bad refresh can be explained afterwards.
IF OBJECT_ID('app.DataLoads', 'U') IS NULL
EXEC('
CREATE TABLE app.DataLoads (
    DataLoadID  INT IDENTITY(1,1) PRIMARY KEY,
    SourceKey   NVARCHAR(40)  NOT NULL,
    LoadedAt    DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    LoadedBy    NVARCHAR(64)  NULL,
    Channel     NVARCHAR(20)  NOT NULL DEFAULT ''upload'',  -- upload, ingest, cli
    FileName    NVARCHAR(260) NULL,
    FileHash    NVARCHAR(64)  NULL,
    FileBytes   INT NULL,
    RowsSeen    INT NULL,
    RowsAdded   INT NULL,
    RowsUpdated INT NULL,
    Outcome     NVARCHAR(20)  NOT NULL DEFAULT ''ok'',       -- ok, skipped, error
    Note        NVARCHAR(600) NULL
);
CREATE INDEX IX_DataLoads_Source ON app.DataLoads(SourceKey, LoadedAt DESC);');
GO

-- The three sources. Refresh intervals reflect how fast each one moves:
-- accounts turn over with the fiscal year and are regenerated nightly at
-- the source, people change weekly, the org chart rarely.
MERGE ref.DataSources AS t
USING (VALUES
    ('accounts', 'CFOP accounts',
     'The active grants report: every account the forms can be charged to, with its fund manager and principal investigator.',
     'https://pride.prairie.illinois.edu/pride/accounts/activeGrantsReport.php?excel=1',
     'activeGrants*.csv', 7, 10),
    -- The employee file and the org chart are pulled from PRIDE by hand
    -- and their addresses are not known here, so they are left blank
    -- rather than guessed at: an address on this screen is followed by
    -- the desktop task, and a wrong one would send it somewhere real.
    ('people', 'People directory',
     'The employee file: name, email, primary org and room, which is what the requested-by lookup searches.',
     NULL, 'export-employee-file-*.csv', 30, 20),
    ('orgchart', 'Org chart',
     'Who manages each unit and which managers may approve. Behind the survey leadership and back end access suggestions.',
     NULL, 'orgChart-*.csv', 90, 30)
) AS s (SourceKey, Label, Description, SourceUrl, FilePattern, RefreshDays, SortOrder)
ON t.SourceKey = s.SourceKey
WHEN MATCHED THEN UPDATE SET
    t.Label = s.Label, t.Description = s.Description, t.FilePattern = s.FilePattern,
    t.SortOrder = s.SortOrder
WHEN NOT MATCHED THEN
    INSERT (SourceKey, Label, Description, SourceUrl, FilePattern, RefreshDays, SortOrder)
    VALUES (s.SourceKey, s.Label, s.Description, s.SourceUrl, s.FilePattern,
            s.RefreshDays, s.SortOrder);
GO

-- Settings behind the refresh: the shared secret a desktop scheduled task
-- posts with, and whether stale sources are allowed to nag.
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ingest_token')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('ingest_token', '', 'text', 'Unattended loading key',
            'Shared secret a scheduled task on a desktop posts an export with. Blank means only a signed in administrator may load a file.', 410);
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ingest_nag')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('ingest_nag', '1', 'number', 'Warn about stale data sources',
            'When 1, the administration side says so when a campus export is past its refresh interval.', 420);
GO
