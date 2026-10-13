-- ============================================================
-- PRI Facilities Request System
-- 00_shared_objects.sql   RUN AGAINST ALL THREE DATABASES
--   PRI_FacilitiesWork, PRI_FacilitiesMove, PRI_FacilitiesShop
-- Target: SQL Server 2019 or later
-- Source: Build Specification v1.0, Appendix B
-- ============================================================

CREATE SCHEMA ref;
GO
CREATE SCHEMA app;
GO

CREATE TABLE ref.Surveys (
    SurveyID INT IDENTITY(1,1) PRIMARY KEY,
    Code     NVARCHAR(10)  NOT NULL UNIQUE,
    Name     NVARCHAR(120) NOT NULL,
    IsActive BIT NOT NULL DEFAULT 1
);
GO

CREATE TABLE ref.Buildings (
    BuildingID       INT IDENTITY(1,1) PRIMARY KEY,
    BldgNumber       NVARCHAR(10)  NOT NULL UNIQUE,   -- natural key across databases
    SortKey          NVARCHAR(20)  NOT NULL,
    ShortCode        NVARCHAR(20)  NULL,
    Name             NVARCHAR(200) NOT NULL,
    AddressLine      NVARCHAR(200) NULL,
    City             NVARCHAR(80)  NULL,
    IsOnCampus       BIT NOT NULL DEFAULT 1,
    IsRemote         BIT NOT NULL DEFAULT 0,
    Criticality      INT NOT NULL DEFAULT 0,
    Latitude         DECIMAL(9,6)  NULL,
    Longitude        DECIMAL(9,6)  NULL,
    GisFeatureID     NVARCHAR(64)  NULL,   -- reserved for the later crosswalk
    RequiresFreeText BIT NOT NULL DEFAULT 0,
    IsActive         BIT NOT NULL DEFAULT 1
);
GO
CREATE INDEX IX_Buildings_Sort ON ref.Buildings(SortKey);
GO

CREATE TABLE ref.BuildingAliases (
    AliasID    INT IDENTITY(1,1) PRIMARY KEY,
    BuildingID INT NOT NULL REFERENCES ref.Buildings(BuildingID),
    AliasText  NVARCHAR(200) NOT NULL,
    AliasNorm  NVARCHAR(200) NOT NULL,   -- lowercased, punctuation stripped
    Source     NVARCHAR(40)  NOT NULL DEFAULT 'seed'
        CHECK (Source IN ('seed','legacy','fs','gis','manual'))
);
GO
CREATE INDEX IX_Aliases_Norm ON ref.BuildingAliases(AliasNorm);
GO

CREATE TABLE ref.BuildingOccupants (
    BuildingID INT NOT NULL REFERENCES ref.Buildings(BuildingID),
    SurveyID   INT NOT NULL REFERENCES ref.Surveys(SurveyID),
    IsPrimary  BIT NOT NULL DEFAULT 0,
    CONSTRAINT PK_BuildingOccupants PRIMARY KEY (BuildingID, SurveyID)
);
GO

CREATE TABLE ref.Owners (
    OwnerID      INT IDENTITY(1,1) PRIMARY KEY,
    Code         NVARCHAR(20)  NOT NULL UNIQUE,
    Name         NVARCHAR(120) NOT NULL,
    HandoffEmail NVARCHAR(200) NULL,
    HandoffUrl   NVARCHAR(400) NULL,
    ShowsOnBoard BIT NOT NULL DEFAULT 1
);
GO

CREATE TABLE app.Users (
    UserID      INT IDENTITY(1,1) PRIMARY KEY,
    NetID       NVARCHAR(64)  NOT NULL UNIQUE,   -- natural key across databases
    DisplayName NVARCHAR(160) NOT NULL,
    Email       NVARCHAR(200) NOT NULL,
    SurveyCode  NVARCHAR(10)  NULL,
    Unit        NVARCHAR(120) NULL,
    Phone       NVARCHAR(40)  NULL,
    Role        NVARCHAR(20)  NOT NULL DEFAULT 'staff'
        CHECK (Role IN ('staff','coordinator','approver','admin')),
    IsProvisional BIT NOT NULL DEFAULT 0,   -- created by an import, not by a login
    IsActive    BIT NOT NULL DEFAULT 1,
    CreatedAt   DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    LastLoginAt DATETIME2 NULL
);
GO

CREATE TABLE app.Settings (
    SettingKey   NVARCHAR(60) PRIMARY KEY,
    SettingValue NVARCHAR(400) NOT NULL,
    ValueType    NVARCHAR(20)  NOT NULL DEFAULT 'number',
    Label        NVARCHAR(160) NOT NULL,
    HelpText     NVARCHAR(400) NULL,
    SortOrder    INT NOT NULL DEFAULT 0
);
GO

CREATE TABLE app.Counters (
    CounterName  NVARCHAR(20) PRIMARY KEY,
    CounterYear  INT NOT NULL,
    CurrentValue INT NOT NULL
);
GO

-- RequestID references the request table of THIS database.
-- The foreign key is added by script 01, 02, or 03 once that
-- database's request table exists (spec Section 5.1).
CREATE TABLE app.Notes (
    NoteID     INT IDENTITY(1,1) PRIMARY KEY,
    RequestID  INT NOT NULL,
    UserID     INT NOT NULL REFERENCES app.Users(UserID),
    Body       NVARCHAR(MAX) NOT NULL,
    IsInternal BIT NOT NULL DEFAULT 0,
    ImportBatchID INT NULL,
    CreatedAt  DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_Notes_Request ON app.Notes(RequestID);
GO

CREATE TABLE app.Attachments (
    AttachmentID  INT IDENTITY(1,1) PRIMARY KEY,
    RequestID     INT NOT NULL,
    UserID        INT NOT NULL REFERENCES app.Users(UserID),
    StoredName    NVARCHAR(120) NOT NULL,
    OriginalName  NVARCHAR(260) NOT NULL,
    MimeType      NVARCHAR(80)  NOT NULL,
    SizeBytes     BIGINT NOT NULL,
    ExifLatitude  DECIMAL(9,6) NULL,
    ExifLongitude DECIMAL(9,6) NULL,
    CapturedAt    DATETIME2 NULL,
    CreatedAt     DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_Attachments_Request ON app.Attachments(RequestID);
GO

CREATE TABLE app.StatusHistory (
    HistoryID  INT IDENTITY(1,1) PRIMARY KEY,
    RequestID  INT NOT NULL,
    FromStatus NVARCHAR(20) NULL,
    ToStatus   NVARCHAR(20) NOT NULL,
    UserID     INT NULL REFERENCES app.Users(UserID),
    Comment    NVARCHAR(600) NULL,
    CreatedAt  DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_History_Request ON app.StatusHistory(RequestID);
GO

CREATE TABLE app.ImportBatches (
    ImportBatchID INT IDENTITY(1,1) PRIMARY KEY,
    SourceSystem  NVARCHAR(60)  NOT NULL,
    FileName      NVARCHAR(260) NOT NULL,
    StoredPath    NVARCHAR(400) NOT NULL,
    MappingJson   NVARCHAR(MAX) NULL,
    RowsRead      INT NOT NULL DEFAULT 0,
    RowsAccepted  INT NOT NULL DEFAULT 0,
    RowsRejected  INT NOT NULL DEFAULT 0,
    Status        NVARCHAR(20) NOT NULL DEFAULT 'staged'
        CHECK (Status IN ('staged','validated','committed','reversed','failed')),
    LoadedByUserID INT NOT NULL REFERENCES app.Users(UserID),
    CreatedAt     DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CommittedAt   DATETIME2 NULL,
    ReversedAt    DATETIME2 NULL
);
GO

CREATE TABLE app.ImportRows (
    ImportRowID   INT IDENTITY(1,1) PRIMARY KEY,
    ImportBatchID INT NOT NULL REFERENCES app.ImportBatches(ImportBatchID),
    RowNumber     INT NOT NULL,
    RawJson       NVARCHAR(MAX) NOT NULL,
    ErrorText     NVARCHAR(600) NULL,
    ResultingID   INT NULL
);
GO
CREATE INDEX IX_ImportRows_Batch ON app.ImportRows(ImportBatchID);
GO

CREATE TABLE app.AuditLog (
    AuditID    INT IDENTITY(1,1) PRIMARY KEY,
    UserID     INT NULL REFERENCES app.Users(UserID),
    Action     NVARCHAR(60) NOT NULL,
    TargetType NVARCHAR(40) NULL,
    TargetID   NVARCHAR(40) NULL,
    Detail     NVARCHAR(MAX) NULL,
    IpAddress  NVARCHAR(45) NULL,
    CreatedAt  DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO
