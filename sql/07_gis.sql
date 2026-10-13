-- ============================================================
-- PRI Facilities Request System
-- 07_gis.sql   Space data combined from the facilities geodatabase
--              and the C3PO exports. Work database only: the map is a
--              single view over space, and the move and shop databases
--              are queried through their own connections.
-- Idempotent.
-- ============================================================

IF SCHEMA_ID('gis') IS NULL EXEC('CREATE SCHEMA gis');
GO

-- One row per room: the flat, queryable table that replaces reading
-- half a dozen geodatabase layers.
IF OBJECT_ID('gis.Rooms', 'U') IS NULL
EXEC('
CREATE TABLE gis.Rooms (
    RoomCode       NVARCHAR(40)  NOT NULL PRIMARY KEY,   -- 0109_01_0189
    BldgNumber     NVARCHAR(10)  NOT NULL,               -- natural key
    FloorCode      NVARCHAR(10)  NOT NULL,
    RoomID         NVARCHAR(40)  NOT NULL,
    RoomName       NVARCHAR(200) NULL,
    CollectionType NVARCHAR(120) NULL,
    CategoryCode   NVARCHAR(20)  NULL,
    CategoryDesc   NVARCHAR(120) NULL,
    UseCode        NVARCHAR(20)  NULL,
    UseDesc        NVARCHAR(160) NULL,
    Department     NVARCHAR(120) NULL,
    Division       NVARCHAR(120) NULL,
    DivisionName   NVARCHAR(200) NULL,
    AreaSqFt       DECIMAL(12,1) NULL,
    CentroidX      DECIMAL(12,1) NULL,
    CentroidY      DECIMAL(12,1) NULL,
    PathData       NVARCHAR(MAX) NULL
);
CREATE INDEX IX_gisRooms_Bldg ON gis.Rooms(BldgNumber, FloorCode);
CREATE INDEX IX_gisRooms_Room ON gis.Rooms(BldgNumber, RoomID);');
GO

IF OBJECT_ID('gis.Floors', 'U') IS NULL
EXEC('
CREATE TABLE gis.Floors (
    BldgNumber NVARCHAR(10) NOT NULL,
    FloorCode  NVARCHAR(10) NOT NULL,
    MinX DECIMAL(14,1) NULL, MinY DECIMAL(14,1) NULL,
    MaxX DECIMAL(14,1) NULL, MaxY DECIMAL(14,1) NULL,
    WallPath NVARCHAR(MAX) NULL,
    CONSTRAINT PK_gisFloors PRIMARY KEY (BldgNumber, FloorCode)
);');
GO

IF OBJECT_ID('gis.BuildingInfo', 'U') IS NULL
EXEC('
CREATE TABLE gis.BuildingInfo (
    BldgNumber  NVARCHAR(10)  NOT NULL PRIMARY KEY,
    Name1       NVARCHAR(200) NULL,
    Name2       NVARCHAR(200) NULL,
    Location    NVARCHAR(80)  NULL,
    AddressLine NVARCHAR(200) NULL,
    City        NVARCHAR(80)  NULL,
    State       NVARCHAR(20)  NULL,
    PostalCode  NVARCHAR(20)  NULL,
    Longitude   DECIMAL(11,7) NULL,
    Latitude    DECIMAL(11,7) NULL
);');
GO

IF OBJECT_ID('gis.Occupants', 'U') IS NULL
EXEC('
CREATE TABLE gis.Occupants (
    OccupantID   INT IDENTITY(1,1) PRIMARY KEY,
    RoomCode     NVARCHAR(40)  NULL,
    BldgNumber   NVARCHAR(10)  NULL,
    FloorCode    NVARCHAR(10)  NULL,
    RoomID       NVARCHAR(40)  NULL,
    PersonName   NVARCHAR(160) NULL,
    NetID        NVARCHAR(64)  NULL,
    UIN          NVARCHAR(40)  NULL,
    Org          NVARCHAR(200) NULL,
    EmpType      NVARCHAR(60)  NULL,
    PrimaryOffice NVARCHAR(10) NULL,
    ConfirmedOn  NVARCHAR(40)  NULL,
    Notes        NVARCHAR(300) NULL
);
CREATE INDEX IX_gisOcc_Room ON gis.Occupants(RoomCode);');
GO

IF OBJECT_ID('gis.Roster', 'U') IS NULL
EXEC('
CREATE TABLE gis.Roster (
    RosterID     INT IDENTITY(1,1) PRIMARY KEY,
    NetID        NVARCHAR(64)  NULL,
    PersonName   NVARCHAR(160) NULL,
    Org          NVARCHAR(200) NULL,
    UIN          NVARCHAR(40)  NULL,
    BuildingCommonName NVARCHAR(80) NULL,
    RoomID       NVARCHAR(40)  NULL,
    Position     NVARCHAR(160) NULL,
    PositionType NVARCHAR(60)  NULL,
    FieldStation NVARCHAR(120) NULL
);
CREATE INDEX IX_gisRoster_NetID ON gis.Roster(NetID);');
GO

IF OBJECT_ID('gis.Inventory', 'U') IS NULL
EXEC('
CREATE TABLE gis.Inventory (
    InventoryID   INT IDENTITY(1,1) PRIMARY KEY,
    RoomCode      NVARCHAR(40)  NULL,
    BldgNumber    NVARCHAR(10)  NULL,
    BannerRoom    NVARCHAR(40)  NULL,
    PropertyTag   NVARCHAR(40)  NULL,
    Description   NVARCHAR(300) NULL,
    Model         NVARCHAR(120) NULL,
    SerialNumber  NVARCHAR(120) NULL,
    Person        NVARCHAR(160) NULL,
    AcquiredOn    NVARCHAR(40)  NULL,
    Cost          DECIMAL(14,2) NULL,
    DepreciatedValue DECIMAL(14,2) NULL,
    FundingSource NVARCHAR(200) NULL,
    BannerLocationCode NVARCHAR(40) NULL
);
CREATE INDEX IX_gisInv_Room ON gis.Inventory(RoomCode);
CREATE INDEX IX_gisInv_Bldg ON gis.Inventory(BldgNumber);');
GO

IF OBJECT_ID('gis.Safety', 'U') IS NULL
EXEC('
CREATE TABLE gis.Safety (
    SafetyID     INT IDENTITY(1,1) PRIMARY KEY,
    RoomCode     NVARCHAR(40)  NULL,
    BldgNumber   NVARCHAR(10)  NULL,
    RoomID       NVARCHAR(40)  NULL,
    Kind         NVARCHAR(40)  NOT NULL,   -- fume_hood, eyewash, safety_shower
    Tag          NVARCHAR(60)  NULL,
    Brand        NVARCHAR(80)  NULL,
    Model        NVARCHAR(80)  NULL,
    SerialNumber NVARCHAR(80)  NULL,
    Descriptor   NVARCHAR(200) NULL,
    Status       NVARCHAR(60)  NULL,
    LastTested   NVARCHAR(40)  NULL
);
CREATE INDEX IX_gisSafety_Room ON gis.Safety(RoomCode);');
GO

-- Key holders. The key assignment source is a PDF whose text cannot be
-- extracted, so this table loads from a CSV export when one exists.
IF OBJECT_ID('gis.RoomKeys', 'U') IS NULL
EXEC('
CREATE TABLE gis.RoomKeys (
    RoomKeyID   INT IDENTITY(1,1) PRIMARY KEY,
    RoomCode    NVARCHAR(40)  NULL,
    BldgNumber  NVARCHAR(10)  NULL,
    RoomID      NVARCHAR(40)  NULL,
    KeyCode     NVARCHAR(60)  NULL,
    HolderName  NVARCHAR(160) NULL,
    HolderNetID NVARCHAR(64)  NULL,
    IssuedOn    NVARCHAR(40)  NULL,
    Notes       NVARCHAR(300) NULL
);
CREATE INDEX IX_gisKeys_Room ON gis.RoomKeys(RoomCode);');
GO

-- Responsibility the source systems do not carry: the survey follows from
-- occupancy, but the principal investigator and the safety contact are
-- assigned here and edited on the map screen.
IF OBJECT_ID('gis.RoomResponsibility', 'U') IS NULL
EXEC('
CREATE TABLE gis.RoomResponsibility (
    RoomCode              NVARCHAR(40) NOT NULL PRIMARY KEY,
    SurveyCode            NVARCHAR(10)  NULL,
    PrincipalInvestigator NVARCHAR(160) NULL,
    SafetyContact         NVARCHAR(160) NULL,
    Notes                 NVARCHAR(400) NULL,
    UpdatedAt             DATETIME2 NULL,
    UpdatedByNetID        NVARCHAR(64) NULL
);');
GO
