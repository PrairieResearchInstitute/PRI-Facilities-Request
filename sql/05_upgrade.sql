-- ============================================================
-- PRI Facilities Request System
-- 05_upgrade.sql   Idempotent upgrades applied to existing databases.
-- Safe to run against all three; each block guards itself.
-- ============================================================

-- CFOP directory (shop database only).
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL AND OBJECT_ID('ref.CfopAccounts', 'U') IS NULL
EXEC('
CREATE TABLE ref.CfopAccounts (
    CfopID      INT IDENTITY(1,1) PRIMARY KEY,
    CfopCode    NVARCHAR(40)  NOT NULL UNIQUE,
    SurveyCode  NVARCHAR(10)  NOT NULL,
    AccountPI   NVARCHAR(160) NOT NULL,
    FundManager NVARCHAR(160) NOT NULL,
    IsActive    BIT NOT NULL DEFAULT 1
);');
GO

-- Handoff tracking: a request transferred to the campus F&S work
-- management system records the reference it was given there.
IF OBJECT_ID('wr.WorkRequests', 'U') IS NOT NULL
   AND COL_LENGTH('wr.WorkRequests', 'HandoffReference') IS NULL
EXEC('
ALTER TABLE wr.WorkRequests ADD
    HandoffReference NVARCHAR(60) NULL,
    HandoffSystem    NVARCHAR(60) NULL,
    HandoffAt        DATETIME2 NULL;');
GO
