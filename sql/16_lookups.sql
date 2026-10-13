-- ============================================================
-- PRI Facilities Request System
-- 16_lookups.sql
--   The CFOP directory grows to match what the current form shows: an
--   account, its activity code, and the plain description people
--   actually recognise. A people directory backs the "requested by"
--   type ahead for filing on somebody's behalf.
-- Run against all three databases; blocks guard themselves.
-- ============================================================

-- ---------- CFOP accounts ----------
-- The live form shows "1-594662-375002-191100-A00 --D6262 Biological
-- monitoring associated with Illinois tollway construction activity",
-- which is account, activity code, and description together.
IF OBJECT_ID('ref.CfopAccounts', 'U') IS NOT NULL
   AND COL_LENGTH('ref.CfopAccounts', 'ActivityCode') IS NULL
EXEC('
ALTER TABLE ref.CfopAccounts ADD
    ActivityCode   NVARCHAR(20)  NULL,
    Description    NVARCHAR(300) NULL,
    FundType       NVARCHAR(20)  NULL,   -- ICR, GRF, gift, grant, state ...
    AccountPIEmail NVARCHAR(200) NULL,
    FundManagerEmail NVARCHAR(200) NULL,
    SourceLabel    NVARCHAR(60)  NULL,
    UpdatedAt      DATETIME2 NULL;');
GO

IF OBJECT_ID('ref.CfopAccounts', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Cfop_Search')
EXEC('CREATE INDEX IX_Cfop_Search ON ref.CfopAccounts(CfopCode, IsActive);');
GO

-- Who actually keyed the request, when it is filed on somebody's behalf.
-- The requester stays the person the work belongs to; this records who
-- sat at the keyboard, so accountability survives.
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL
   AND COL_LENGTH('sh.ShopRequests', 'FiledByNetID') IS NULL
EXEC('ALTER TABLE sh.ShopRequests ADD FiledByNetID NVARCHAR(64) NULL;');
GO

-- ---------- people directory ----------
-- app.Users holds whoever has signed in or been imported. The type ahead
-- also needs people who have never touched this application, so a
-- directory sits alongside it and is refreshed from whatever the campus
-- gives us.
IF OBJECT_ID('ref.People', 'U') IS NULL
EXEC('
CREATE TABLE ref.People (
    PersonID    INT IDENTITY(1,1) PRIMARY KEY,
    NetID       NVARCHAR(64)  NOT NULL,
    DisplayName NVARCHAR(160) NOT NULL,   -- "Dreslik, Michael J"
    Email       NVARCHAR(200) NULL,
    UIN         NVARCHAR(40)  NULL,
    SurveyCode  NVARCHAR(20)  NULL,
    Org         NVARCHAR(200) NULL,
    Position    NVARCHAR(160) NULL,
    IsActive    BIT NOT NULL DEFAULT 1,
    SourceLabel NVARCHAR(60)  NULL,
    UpdatedAt   DATETIME2 NULL,
    CONSTRAINT UQ_People_NetID UNIQUE (NetID)
);
CREATE INDEX IX_People_Name ON ref.People(DisplayName);');
GO

-- Seed the directory from what we already know, so the type ahead works
-- before any campus feed exists.
IF OBJECT_ID('ref.People', 'U') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ref.People)
BEGIN
    INSERT INTO ref.People (NetID, DisplayName, Email, SurveyCode, SourceLabel)
    SELECT NetID, DisplayName, Email, SurveyCode, 'app.Users'
      FROM app.Users WHERE IsActive = 1;
END
GO
