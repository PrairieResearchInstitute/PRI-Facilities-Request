-- ============================================================
-- PRI Facilities Request System
-- 15_digests.sql
--   A standing summary for each person of what is waiting on them,
--   sent as often as they choose, pointing at a page of their own that
--   reflects the current state rather than the state when it was sent.
-- Work database holds the preferences; run against all three so the
-- table exists wherever it is read.
-- ============================================================

IF OBJECT_ID('app.DigestPreferences', 'U') IS NULL
EXEC('
CREATE TABLE app.DigestPreferences (
    NetID       NVARCHAR(64) NOT NULL PRIMARY KEY,
    Frequency   NVARCHAR(10) NOT NULL DEFAULT ''weekly''
        CHECK (Frequency IN (''off'',''daily'',''weekly'')),
    SendDay     INT NOT NULL DEFAULT 1,      -- 0 Sunday .. 6 Saturday, weekly only
    SendHour    INT NOT NULL DEFAULT 7,      -- local hour of day
    IncludeMine BIT NOT NULL DEFAULT 1,      -- their own open requests
    Token       NVARCHAR(64) NOT NULL,       -- their personal digest page
    LastSentAt  DATETIME2 NULL,
    LastItemCount INT NULL,
    CreatedAt   DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_DigestToken UNIQUE (Token)
);');
GO

-- When a digest last ran at all, so a host without a scheduler can catch
-- up on the next page view without checking on every request.
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'digest_last_run')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('digest_last_run', '', 'text', 'Digest last run',
            'When the digest run last happened. Set by the application; do not edit by hand.', 360);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'digest_default_frequency')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('digest_default_frequency', 'weekly', 'text', 'Digest default frequency',
            'What a person gets before they choose for themselves: off, daily, or weekly', 370);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'digest_auto_run')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('digest_auto_run', '1', 'number', 'Send digests without a scheduler',
            'When 1, a page view checks at most once an hour whether digests are due and sends a few. Turn off if a scheduled task runs tools\send_digests.php instead.', 380);
GO
