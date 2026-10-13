-- ============================================================
-- PRI Facilities Request System
-- 08_roomcode.sql
--   1. Room attributes folded in from the remaining geodatabase
--      tables, so one row answers a question about a room.
--   2. RoomCode, the connector, added to the three request tables so a
--      request points at the canonical room rather than being matched by
--      free text every time it is read.
-- Run against all three databases; each block guards itself.
-- ============================================================

-- ---------- extra room attributes (work database) ----------
IF OBJECT_ID('gis.Rooms', 'U') IS NOT NULL
   AND COL_LENGTH('gis.Rooms', 'SurveyCode') IS NULL
EXEC('
ALTER TABLE gis.Rooms ADD
    OtherDescription NVARCHAR(300) NULL,
    DrsInspect       NVARCHAR(40)  NULL,
    CollectionReport NVARCHAR(40)  NULL,
    Category02       NVARCHAR(20)  NULL,
    Desc02           NVARCHAR(160) NULL,
    SpacePercent     DECIMAL(6,2)  NULL,
    SplitSpace       NVARCHAR(40)  NULL,
    SurveyCode       NVARCHAR(20)  NULL,
    Org1             NVARCHAR(200) NULL,
    Org1A            NVARCHAR(200) NULL,
    Org3A            NVARCHAR(200) NULL,
    Org4A            NVARCHAR(200) NULL,
    KeyCodeRoom      NVARCHAR(40)  NULL,
    KeyCodeBuilding  NVARCHAR(40)  NULL;');
GO

-- Official space category colours, so the plan is drawn in the same
-- scheme the facilities group already publishes.
IF SCHEMA_ID('gis') IS NOT NULL AND OBJECT_ID('gis.CategoryColors', 'U') IS NULL
EXEC('
CREATE TABLE gis.CategoryColors (
    Scope        NVARCHAR(20) NOT NULL,   -- detail or general
    CategoryCode NVARCHAR(20) NOT NULL,
    CategoryName NVARCHAR(120) NULL,
    Red INT NULL, Green INT NULL, Blue INT NULL,
    CONSTRAINT PK_gisCategoryColors PRIMARY KEY (Scope, CategoryCode)
);');
GO

-- Building outlines, for a campus level view.
IF SCHEMA_ID('gis') IS NOT NULL AND OBJECT_ID('gis.BuildingFootprints', 'U') IS NULL
EXEC('
CREATE TABLE gis.BuildingFootprints (
    BldgNumber NVARCHAR(10) NOT NULL PRIMARY KEY,
    MinX DECIMAL(14,1) NULL, MinY DECIMAL(14,1) NULL,
    MaxX DECIMAL(14,1) NULL, MaxY DECIMAL(14,1) NULL,
    PathData NVARCHAR(MAX) NULL
);');
GO

-- Doors, drawn on the floor plan.
IF OBJECT_ID('gis.Floors', 'U') IS NOT NULL
   AND COL_LENGTH('gis.Floors', 'DoorPath') IS NULL
EXEC('ALTER TABLE gis.Floors ADD DoorPath NVARCHAR(MAX) NULL;');
GO

-- Key holders. The geodatabase carries the key code that opens a room;
-- it carries no holder, no state and no date, so the current assignment
-- register loads separately.
IF OBJECT_ID('gis.RoomKeys', 'U') IS NOT NULL
   AND COL_LENGTH('gis.RoomKeys', 'KeyState') IS NULL
EXEC('
ALTER TABLE gis.RoomKeys ADD
    KeyState     NVARCHAR(40)  NULL,
    KeyType      NVARCHAR(60)  NULL,
    Quantity     INT NULL,
    SurveyCode   NVARCHAR(20)  NULL,
    EmpStatus    NVARCHAR(40)  NULL,
    AssignedBy   NVARCHAR(160) NULL,
    BuildingText NVARCHAR(160) NULL,
    FloorText    NVARCHAR(40)  NULL,
    SourceLabel  NVARCHAR(60)  NULL;');
GO

-- ---------- the connector ----------
-- A request records the room as free text, because a reporter types what
-- is on the door. RoomCode is resolved on write and backfilled here, so
-- the map and every room report join on one column.
IF OBJECT_ID('wr.WorkRequests', 'U') IS NOT NULL
   AND COL_LENGTH('wr.WorkRequests', 'RoomCode') IS NULL
EXEC('
ALTER TABLE wr.WorkRequests ADD RoomCode NVARCHAR(40) NULL;
');
GO
IF OBJECT_ID('wr.WorkRequests', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WR_RoomCode')
EXEC('CREATE INDEX IX_WR_RoomCode ON wr.WorkRequests(RoomCode);');
GO

IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL
   AND COL_LENGTH('sh.ShopRequests', 'RoomCode') IS NULL
EXEC('ALTER TABLE sh.ShopRequests ADD RoomCode NVARCHAR(40) NULL;');
GO
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SH_RoomCode')
EXEC('CREATE INDEX IX_SH_RoomCode ON sh.ShopRequests(RoomCode);');
GO

IF OBJECT_ID('mv.MoveItems', 'U') IS NOT NULL
   AND COL_LENGTH('mv.MoveItems', 'FromRoomCode') IS NULL
EXEC('
ALTER TABLE mv.MoveItems ADD
    FromRoomCode NVARCHAR(40) NULL,
    ToRoomCode   NVARCHAR(40) NULL;');
GO
IF OBJECT_ID('mv.MoveItems', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_MVItems_RoomCode')
EXEC('CREATE INDEX IX_MVItems_RoomCode ON mv.MoveItems(FromRoomCode, ToRoomCode);');
GO

-- Each database keeps a copy of the room list so the connector can be
-- resolved and reported on without reaching across a database boundary.
IF OBJECT_ID('ref.Rooms', 'U') IS NULL
EXEC('
CREATE TABLE ref.Rooms (
    RoomCode   NVARCHAR(40) NOT NULL PRIMARY KEY,
    BldgNumber NVARCHAR(10) NOT NULL,
    FloorCode  NVARCHAR(10) NOT NULL,
    RoomID     NVARCHAR(40) NOT NULL,
    RoomKey    NVARCHAR(40) NOT NULL,   -- normalised: upper case, no zeros
    RoomName   NVARCHAR(200) NULL,
    SurveyCode NVARCHAR(20)  NULL
);
CREATE INDEX IX_refRooms_Lookup ON ref.Rooms(BldgNumber, RoomKey);');
GO
