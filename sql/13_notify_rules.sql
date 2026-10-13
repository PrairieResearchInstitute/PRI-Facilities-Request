-- ============================================================
-- PRI Facilities Request System
-- 13_notify_rules.sql
--   Notification recipients as rules rather than one list per event, so
--   a request reaches everyone it should: the standing recipients, the
--   people who care about that particular kind of work, and the survey
--   director and proxy for the unit it belongs to.
-- Run against all three databases, then publish reference data.
-- ============================================================

-- ---------- survey leadership ----------
-- Every request touches a survey, and the director and their proxy are
-- told about it. They live on the survey rather than in a rule, because
-- they are a property of the unit.
IF COL_LENGTH('ref.Surveys', 'DirectorEmail') IS NULL
EXEC('
ALTER TABLE ref.Surveys ADD
    DirectorName  NVARCHAR(160) NULL,
    DirectorEmail NVARCHAR(200) NULL,
    ProxyName     NVARCHAR(160) NULL,
    ProxyEmail    NVARCHAR(200) NULL,
    NotifyDirector BIT NOT NULL DEFAULT 1;');
GO

-- ---------- recipient rules ----------
-- A rule is: for this event, when this condition holds, tell this person.
-- Several rules may fire for one request; the addresses are merged and
-- deduplicated when the notice is sent.
IF OBJECT_ID('app.NotifyRules', 'U') IS NULL
EXEC('
CREATE TABLE app.NotifyRules (
    RuleID      INT IDENTITY(1,1) PRIMARY KEY,
    EventCode   NVARCHAR(40) NOT NULL,     -- work_request_created, move_request_created,
                                           -- shop_request_created, approval_decision, any
    MatchType   NVARCHAR(20) NOT NULL DEFAULT ''always''
        CHECK (MatchType IN (''always'',''survey'',''work_type'',''owner'',
                             ''request_kind'',''severity_min'',''building'',''life_safety'',
                             ''specimen_risk'')),
    MatchValue  NVARCHAR(120) NULL,
    Email       NVARCHAR(200) NOT NULL,
    DisplayName NVARCHAR(160) NULL,
    Note        NVARCHAR(300) NULL,
    IsActive    BIT NOT NULL DEFAULT 1,
    CreatedAt   DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CreatedByNetID NVARCHAR(64) NULL
);
CREATE INDEX IX_NotifyRules_Event ON app.NotifyRules(EventCode, IsActive);');
GO

-- Carry the existing comma separated lists across, so nothing that was
-- being told about a request stops being told.
IF OBJECT_ID('app.NotifyRules', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM app.NotifyRules)
BEGIN
    INSERT INTO app.NotifyRules (EventCode, MatchType, MatchValue, Email, Note)
    SELECT 'shop_request_created', 'always', NULL, LTRIM(RTRIM(value)), 'migrated from notify_shop'
      FROM app.Settings CROSS APPLY STRING_SPLIT(SettingValue, ',')
     WHERE SettingKey = 'notify_shop' AND LTRIM(RTRIM(value)) <> '';

    INSERT INTO app.NotifyRules (EventCode, MatchType, MatchValue, Email, Note)
    SELECT 'work_request_created', 'always', NULL, LTRIM(RTRIM(value)), 'migrated from notify_work'
      FROM app.Settings CROSS APPLY STRING_SPLIT(SettingValue, ',')
     WHERE SettingKey = 'notify_work' AND LTRIM(RTRIM(value)) <> '';

    INSERT INTO app.NotifyRules (EventCode, MatchType, MatchValue, Email, Note)
    SELECT 'work_request_created', 'severity_min', '3', LTRIM(RTRIM(value)),
           'migrated from notify_work_urgent'
      FROM app.Settings CROSS APPLY STRING_SPLIT(SettingValue, ',')
     WHERE SettingKey = 'notify_work_urgent' AND LTRIM(RTRIM(value)) <> '';

    INSERT INTO app.NotifyRules (EventCode, MatchType, MatchValue, Email, Note)
    SELECT 'move_request_created', 'always', NULL, LTRIM(RTRIM(value)), 'migrated from notify_move'
      FROM app.Settings CROSS APPLY STRING_SPLIT(SettingValue, ',')
     WHERE SettingKey = 'notify_move' AND LTRIM(RTRIM(value)) <> '';
END
GO

-- Fleet work goes to whoever runs the fleet, on top of the standing list.
IF OBJECT_ID('app.NotifyRules', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM app.NotifyRules
                   WHERE EventCode = 'shop_request_created' AND MatchType = 'request_kind')
    INSERT INTO app.NotifyRules (EventCode, MatchType, MatchValue, Email, DisplayName, Note)
    VALUES ('shop_request_created', 'request_kind', 'vehicle', 'configure-me@example.edu',
            'Deany, Adam F', 'Fleet vehicle maintenance');
GO
