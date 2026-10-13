-- ============================================================
-- PRI Facilities Request System
-- 06_upgrade.sql   Notifications, fleet vehicles, floor plans.
-- Idempotent; safe to run against all three databases.
-- ============================================================

-- ---------- Notification outbox (all three databases) ----------
-- Every notification is recorded whether or not mail delivery is
-- configured, so the record exists on a host without a mail transport
-- and an administrator can see exactly what went where.
IF OBJECT_ID('app.Notifications', 'U') IS NULL
EXEC('
CREATE TABLE app.Notifications (
    NotificationID INT IDENTITY(1,1) PRIMARY KEY,
    RequestID      INT NULL,
    TicketNumber   NVARCHAR(24)  NULL,
    EventCode      NVARCHAR(40)  NOT NULL,
    Recipients     NVARCHAR(MAX) NOT NULL,
    Subject        NVARCHAR(300) NOT NULL,
    Body           NVARCHAR(MAX) NOT NULL,
    Status         NVARCHAR(20)  NOT NULL DEFAULT ''logged''
        CHECK (Status IN (''logged'',''sent'',''failed'')),
    ErrorText      NVARCHAR(600) NULL,
    CreatedAt      DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    SentAt         DATETIME2 NULL
);
CREATE INDEX IX_Notifications_Created ON app.Notifications(CreatedAt DESC);');
GO

-- ---------- Notification settings ----------
-- Recipient lists are configuration, edited on the admin screen, never
-- on a public form.
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'notify_mode')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder) VALUES
    ('notify_mode', 'log', 'text', 'Notification delivery',
     'How notifications leave the system: log (record only, nothing sent), mail (send with the host mail transport)', 300);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'notify_from')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder) VALUES
    ('notify_from', 'configure-me@example.edu', 'text', 'Notification from address',
     'Address notifications are sent from', 310);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'notify_shop')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder) VALUES
    ('notify_shop', '', 'text', 'Shop request recipients',
     'Comma separated addresses notified when a shop services request is filed. The account PI and fund manager are added automatically.', 320);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'notify_work')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder) VALUES
    ('notify_work', '', 'text', 'Work request recipients',
     'Comma separated addresses notified when a facilities work request is filed', 330);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'notify_move')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder) VALUES
    ('notify_move', '', 'text', 'Move request recipients',
     'Comma separated addresses notified when a move request is filed', 340);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'notify_work_urgent')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder) VALUES
    ('notify_work_urgent', '', 'text', 'Urgent work request recipients',
     'Additional addresses notified when a work request is filed at severity 3 or 4, or flags life safety', 350);
GO

-- ---------- Fleet vehicles (shop database) ----------
-- Loaded from the existing fleet listing; maintained through Admin.
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL AND OBJECT_ID('ref.FleetVehicles', 'U') IS NULL
EXEC('
CREATE TABLE ref.FleetVehicles (
    FleetVehicleID INT IDENTITY(1,1) PRIMARY KEY,
    FleetNumber    NVARCHAR(40)  NOT NULL UNIQUE,   -- natural key, e.g. 35690-18
    Description    NVARCHAR(200) NOT NULL,          -- e.g. Green Dodge Journey
    VehicleYear    INT NULL,
    Make           NVARCHAR(60)  NULL,
    Model          NVARCHAR(60)  NULL,
    Color          NVARCHAR(40)  NULL,
    PlateNumber    NVARCHAR(40)  NULL,
    VIN            NVARCHAR(40)  NULL,
    HomeBldgNumber NVARCHAR(10)  NULL,
    SurveyCode     NVARCHAR(10)  NULL,
    AssignedTo     NVARCHAR(160) NULL,
    IsActive       BIT NOT NULL DEFAULT 1
);
CREATE INDEX IX_Fleet_Active ON ref.FleetVehicles(IsActive);');
GO

-- Shop requests distinguish vehicle maintenance and name the vehicle.
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL
   AND COL_LENGTH('sh.ShopRequests', 'RequestKind') IS NULL
EXEC('
ALTER TABLE sh.ShopRequests ADD
    RequestKind    NVARCHAR(20) NOT NULL DEFAULT ''general'',
    FleetVehicleID INT NULL,
    FleetNumber    NVARCHAR(40) NULL;');
GO

-- ---------- Floor plan coverage ----------
-- Which buildings have a published floor plan service, and which floors.
-- Replicated with the rest of the reference data.
IF OBJECT_ID('ref.BuildingMaps', 'U') IS NULL
EXEC('
CREATE TABLE ref.BuildingMaps (
    BuildingMapID INT IDENTITY(1,1) PRIMARY KEY,
    BldgNumber    NVARCHAR(10)  NOT NULL,     -- natural key, matches ref.Buildings
    FloorCode     NVARCHAR(20)  NOT NULL,     -- 1, 2, B ...
    FloorLabel    NVARCHAR(60)  NOT NULL,
    ServiceUrl    NVARCHAR(400) NOT NULL,     -- ArcGIS MapServer for this floor
    RoomLayerID   INT NOT NULL DEFAULT 1,
    SortOrder     INT NOT NULL DEFAULT 0,
    IsActive      BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_BuildingMap UNIQUE (BldgNumber, FloorCode)
);');
GO

-- Seed the published Natural Resources Building floors. Room polygons
-- carry BannerBldg 0109, which is this application''s building number for
-- the NRB, so no name crosswalk is required.
IF OBJECT_ID('ref.BuildingMaps', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM ref.BuildingMaps WHERE BldgNumber = N'0109')
BEGIN
    INSERT INTO ref.BuildingMaps (BldgNumber, FloorCode, FloorLabel, ServiceUrl, RoomLayerID, SortOrder) VALUES
    (N'0109', N'B', N'Basement',
     N'https://data.isgs.illinois.edu/arcgis/rest/services/Space/NRB_Basement/MapServer', 1, 10),
    (N'0109', N'2', N'Second floor',
     N'https://data.isgs.illinois.edu/arcgis/rest/services/Space/NRB_Second_Floor/MapServer', 1, 20);
END
GO
