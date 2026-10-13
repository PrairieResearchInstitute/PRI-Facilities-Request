-- ============================================================
-- PRI Facilities Request System
-- 09_admin_forms.sql
--   Working records behind the administrative forms: key assignment,
--   equipment ingest and transfer, and room assignment. Each carries an
--   event trail, because these answer "who changed this, and when".
-- Work database. Idempotent.
-- ============================================================

-- ---------- keys ----------
-- gis.RoomKeys holds the current assignment. Editing, returning, and
-- reporting a key lost all write an event rather than overwriting
-- history.
IF OBJECT_ID('gis.RoomKeys', 'U') IS NOT NULL
   AND COL_LENGTH('gis.RoomKeys', 'ReturnedOn') IS NULL
EXEC('
ALTER TABLE gis.RoomKeys ADD
    ReturnedOn     NVARCHAR(40)  NULL,
    LostOn         NVARCHAR(40)  NULL,
    UpdatedAt      DATETIME2     NULL,
    UpdatedByNetID NVARCHAR(64)  NULL,
    IsManaged      BIT NOT NULL DEFAULT 0;');   -- issued in this application
GO

IF OBJECT_ID('gis.KeyEvents', 'U') IS NULL
EXEC('
CREATE TABLE gis.KeyEvents (
    KeyEventID   INT IDENTITY(1,1) PRIMARY KEY,
    RoomKeyID    INT NOT NULL,
    EventCode    NVARCHAR(20) NOT NULL
        CHECK (EventCode IN (''issued'',''returned'',''lost'',''edited'',''voided'')),
    KeyStateFrom NVARCHAR(40) NULL,
    KeyStateTo   NVARCHAR(40) NULL,
    Detail       NVARCHAR(600) NULL,
    ByNetID      NVARCHAR(64) NULL,
    CreatedAt    DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
CREATE INDEX IX_gisKeyEvents ON gis.KeyEvents(RoomKeyID, CreatedAt);');
GO

-- ---------- equipment ----------
-- The C3PO extract is the starting position; movements and disposals
-- recorded here keep the location current between extracts.
IF OBJECT_ID('gis.Inventory', 'U') IS NOT NULL
   AND COL_LENGTH('gis.Inventory', 'Status') IS NULL
EXEC('
ALTER TABLE gis.Inventory ADD
    Status         NVARCHAR(20) NOT NULL DEFAULT ''active''
        CHECK (Status IN (''active'',''transferred'',''surplus'',''trashed'',''lost'',''returned'')),
    RoomID         NVARCHAR(40)  NULL,
    Condition      NVARCHAR(40)  NULL,
    SurveyCode     NVARCHAR(20)  NULL,
    LastMovedAt    DATETIME2     NULL,
    RemovedOn      NVARCHAR(40)  NULL,
    RemovalReason  NVARCHAR(300) NULL,
    IsManaged      BIT NOT NULL DEFAULT 0,
    UpdatedAt      DATETIME2     NULL,
    UpdatedByNetID NVARCHAR(64)  NULL;');
GO

IF OBJECT_ID('gis.InventoryEvents', 'U') IS NULL
EXEC('
CREATE TABLE gis.InventoryEvents (
    InventoryEventID INT IDENTITY(1,1) PRIMARY KEY,
    InventoryID   INT NOT NULL,
    PropertyTag   NVARCHAR(40) NULL,
    EventCode     NVARCHAR(20) NOT NULL
        CHECK (EventCode IN (''ingested'',''transferred'',''removed'',''edited'',''restored'')),
    FromRoomCode  NVARCHAR(40) NULL,
    ToRoomCode    NVARCHAR(40) NULL,
    FromBldgNumber NVARCHAR(10) NULL,
    ToBldgNumber   NVARCHAR(10) NULL,
    Detail        NVARCHAR(600) NULL,
    MoveTicket    NVARCHAR(24) NULL,   -- when a move request drove the change
    ByNetID       NVARCHAR(64) NULL,
    CreatedAt     DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
CREATE INDEX IX_gisInvEvents ON gis.InventoryEvents(InventoryID, CreatedAt);
CREATE INDEX IX_gisInvEvents_Tag ON gis.InventoryEvents(PropertyTag);');
GO

-- ---------- room assignment ----------
IF OBJECT_ID('gis.RoomResponsibility', 'U') IS NOT NULL
   AND COL_LENGTH('gis.RoomResponsibility', 'PrimaryContactNetID') IS NULL
EXEC('
ALTER TABLE gis.RoomResponsibility ADD
    PrincipalInvestigatorNetID NVARCHAR(64) NULL,
    SafetyContactNetID NVARCHAR(64) NULL,
    PrimaryContactNetID NVARCHAR(64) NULL,
    RoomUseOverride NVARCHAR(160) NULL,
    AccessRestriction NVARCHAR(120) NULL;');
GO

IF OBJECT_ID('gis.RoomAssignmentEvents', 'U') IS NULL
EXEC('
CREATE TABLE gis.RoomAssignmentEvents (
    RoomAssignmentEventID INT IDENTITY(1,1) PRIMARY KEY,
    RoomCode  NVARCHAR(40) NOT NULL,
    EventCode NVARCHAR(30) NOT NULL,   -- responsibility, occupant_added, occupant_removed
    Detail    NVARCHAR(600) NULL,
    ByNetID   NVARCHAR(64) NULL,
    CreatedAt DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
CREATE INDEX IX_gisRoomAssign ON gis.RoomAssignmentEvents(RoomCode, CreatedAt);');
GO

-- Occupancy rows created in this application are marked so a reload of
-- the C3PO extract does not silently discard them.
IF OBJECT_ID('gis.Occupants', 'U') IS NOT NULL
   AND COL_LENGTH('gis.Occupants', 'IsManaged') IS NULL
EXEC('
ALTER TABLE gis.Occupants ADD
    IsManaged   BIT NOT NULL DEFAULT 0,
    AssignedOn  NVARCHAR(40) NULL,
    EndedOn     NVARCHAR(40) NULL,
    ByNetID     NVARCHAR(64) NULL;');
GO

-- ---------- safety equipment inspections ----------
IF OBJECT_ID('gis.Safety', 'U') IS NOT NULL
   AND COL_LENGTH('gis.Safety', 'NextDue') IS NULL
EXEC('
ALTER TABLE gis.Safety ADD
    NextDue        NVARCHAR(40) NULL,
    IsManaged      BIT NOT NULL DEFAULT 0,
    UpdatedAt      DATETIME2 NULL,
    UpdatedByNetID NVARCHAR(64) NULL;');
GO
