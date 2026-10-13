-- ============================================================
-- PRI Facilities Request System
-- 19_orgchart.sql
--   The org chart export carries what no other report does: who manages
--   each unit, and which of those managers hold approval authority. That
--   is the raw material for survey leadership and for the back end
--   access list, so it is loaded and then PROPOSED rather than applied:
--   authority is granted by a person, not by an import.
-- Work database.
-- ============================================================

IF OBJECT_ID('ref.People', 'U') IS NOT NULL
   AND COL_LENGTH('ref.People', 'OrgPath') IS NULL
EXEC('
ALTER TABLE ref.People ADD
    FirstName  NVARCHAR(80)  NULL,
    LastName   NVARCHAR(80)  NULL,
    OrgPath    NVARCHAR(300) NULL,   -- ISWS.ISWS_WISE.ISWS_WPH.WOO
    OrgUnit    NVARCHAR(120) NULL,   -- WOO
    OrgRole    NVARCHAR(40)  NULL,   -- Member, Manager, Manager approve, Manager view
    OrgDepth   INT NULL,             -- 0 for a survey, 1 for a section, and so on
    OrgSince   NVARCHAR(40)  NULL;');
GO

-- One row per unit, with whoever manages it. A unit may have more than
-- one manager, so the primary key includes the person.
IF OBJECT_ID('ref.OrgUnits', 'U') IS NULL
EXEC('
CREATE TABLE ref.OrgUnits (
    OrgUnitID   INT IDENTITY(1,1) PRIMARY KEY,
    OrgPath     NVARCHAR(300) NOT NULL,
    UnitName    NVARCHAR(120) NULL,
    SurveyCode  NVARCHAR(20)  NULL,
    Depth       INT NOT NULL DEFAULT 0,
    ManagerNetID NVARCHAR(64) NULL,
    ManagerName  NVARCHAR(160) NULL,
    ManagerRole  NVARCHAR(40)  NULL,   -- Manager, Manager approve, Manager view
    MemberCount  INT NOT NULL DEFAULT 0,
    UpdatedAt    DATETIME2 NULL,
    CONSTRAINT UQ_OrgUnit UNIQUE (OrgPath, ManagerNetID, ManagerRole)
);
CREATE INDEX IX_OrgUnits_Survey ON ref.OrgUnits(SurveyCode, Depth);');
GO

-- A person often holds two roles on one unit: plain Manager, which marks
-- the head of the unit, and "Manager approve", which marks fiscal
-- authority. Those mean different things, so both rows are kept.
IF OBJECT_ID('ref.OrgUnits', 'U') IS NOT NULL
   AND EXISTS (SELECT 1 FROM sys.indexes i
               WHERE i.name = 'UQ_OrgUnit' AND i.object_id = OBJECT_ID('ref.OrgUnits')
                 AND (SELECT COUNT(*) FROM sys.index_columns c
                      WHERE c.object_id = i.object_id AND c.index_id = i.index_id) < 3)
EXEC('
ALTER TABLE ref.OrgUnits DROP CONSTRAINT UQ_OrgUnit;
ALTER TABLE ref.OrgUnits ADD CONSTRAINT UQ_OrgUnit
    UNIQUE (OrgPath, ManagerNetID, ManagerRole);');
GO
