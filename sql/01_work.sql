-- ============================================================
-- PRI Facilities Request System
-- 01_work.sql   DATABASE: PRI_FacilitiesWork ONLY
-- Run after 00_shared_objects.sql.
-- Source: Build Specification v1.0, Appendix B
-- ============================================================

CREATE SCHEMA wr;
GO

CREATE TABLE ref.WorkTypes (
    WorkTypeID         INT IDENTITY(1,1) PRIMARY KEY,
    OwnerID            INT NOT NULL REFERENCES ref.Owners(OwnerID),
    Name               NVARCHAR(120) NOT NULL UNIQUE,
    CategoryWeight     INT NOT NULL DEFAULT 0,
    SeverityFloor      INT NOT NULL DEFAULT 1,
    TargetResponseDays INT NOT NULL DEFAULT 14,
    RequiresEstimate   BIT NOT NULL DEFAULT 0,
    SkipsCorroboration BIT NOT NULL DEFAULT 0,
    IsPhoneOnly        BIT NOT NULL DEFAULT 0,
    PhoneInstruction   NVARCHAR(300) NULL,
    IsActive           BIT NOT NULL DEFAULT 1
);
GO

CREATE TABLE ref.Natures (
    NatureID INT IDENTITY(1,1) PRIMARY KEY,
    Name     NVARCHAR(80) NOT NULL UNIQUE,
    IsActive BIT NOT NULL DEFAULT 1
);
GO

CREATE TABLE ref.TermSynonyms (
    SynonymID INT IDENTITY(1,1) PRIMARY KEY,
    GroupKey  NVARCHAR(40) NOT NULL,
    Term      NVARCHAR(60) NOT NULL
);
GO
CREATE INDEX IX_Synonyms_Term ON ref.TermSynonyms(Term);
GO

CREATE TABLE wr.WorkRequests (
    WorkRequestID    INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber     NVARCHAR(24) NOT NULL UNIQUE,
    Title            NVARCHAR(200) NOT NULL,
    Description      NVARCHAR(MAX) NOT NULL,
    BuildingID       INT NOT NULL REFERENCES ref.Buildings(BuildingID),
    BldgNumber       NVARCHAR(10) NOT NULL,    -- denormalized natural key
    WrittenLocation  NVARCHAR(300) NULL,
    RoomNumber       NVARCHAR(40) NULL,
    FloorCode        NVARCHAR(20) NULL,
    Latitude         DECIMAL(9,6) NULL,
    Longitude        DECIMAL(9,6) NULL,
    CoordSource      NVARCHAR(20) NULL
        CHECK (CoordSource IN ('user','exif','geocode')),
    WorkTypeID       INT NOT NULL REFERENCES ref.WorkTypes(WorkTypeID),
    NatureID         INT NULL REFERENCES ref.Natures(NatureID),
    OwnerID          INT NOT NULL REFERENCES ref.Owners(OwnerID),
    SurveyCode       NVARCHAR(10) NULL,        -- denormalized natural key
    ReportedSeverity INT NOT NULL DEFAULT 2 CHECK (ReportedSeverity BETWEEN 1 AND 4),
    TriagedSeverity  INT NULL CHECK (TriagedSeverity BETWEEN 1 AND 4),
    LifeSafety       BIT NOT NULL DEFAULT 0,
    SpecimenRisk     BIT NOT NULL DEFAULT 0,
    NeededByDate     DATE NULL,
    EstimateAmount   DECIMAL(12,2) NULL,
    CfopCode         NVARCHAR(40) NULL,
    Status           NVARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (Status IN ('open','triaged','awaiting_approval','scheduled',
                          'in_progress','on_hold','work_done','verified',
                          'closed','merged','handed_off','declined')),
    PriorityBase     DECIMAL(8,2) NOT NULL DEFAULT 0,
    UrgencyBand      NVARCHAR(12) NOT NULL DEFAULT 'routine',
    CreatedByUserID  INT NOT NULL REFERENCES app.Users(UserID),
    CreatedByNetID   NVARCHAR(64) NOT NULL,    -- denormalized natural key
    OnBehalfOfName   NVARCHAR(160) NULL,
    AssignedToUserID INT NULL REFERENCES app.Users(UserID),
    MergedIntoID     INT NULL REFERENCES wr.WorkRequests(WorkRequestID),
    SearchBlob       NVARCHAR(MAX) NOT NULL DEFAULT '',
    ImportBatchID    INT NULL REFERENCES app.ImportBatches(ImportBatchID),
    LegacyReference  NVARCHAR(60) NULL,
    -- Transfer to the campus Facilities and Services work management
    -- system: the ticket stays here so duplicate suppression keeps
    -- working, and carries the reference the receiving system gave it.
    HandoffReference NVARCHAR(60) NULL,
    HandoffSystem    NVARCHAR(60) NULL,
    HandoffAt        DATETIME2 NULL,
    CreatedAt        DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt        DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    TriagedAt        DATETIME2 NULL,
    WorkDoneAt       DATETIME2 NULL,
    VerifiedAt       DATETIME2 NULL,
    ClosedAt         DATETIME2 NULL
);
GO
CREATE INDEX IX_WR_Status   ON wr.WorkRequests(Status);
CREATE INDEX IX_WR_Building ON wr.WorkRequests(BuildingID);
CREATE INDEX IX_WR_Type     ON wr.WorkRequests(WorkTypeID);
CREATE INDEX IX_WR_Priority ON wr.WorkRequests(PriorityBase DESC);
CREATE INDEX IX_WR_Created  ON wr.WorkRequests(CreatedAt DESC);
GO

CREATE TABLE wr.Corroborations (
    CorroborationID INT IDENTITY(1,1) PRIMARY KEY,
    WorkRequestID   INT NOT NULL REFERENCES wr.WorkRequests(WorkRequestID),
    UserID          INT NOT NULL REFERENCES app.Users(UserID),
    SurveyCode      NVARCHAR(10) NULL,
    CreatedAt       DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_Corroboration UNIQUE (WorkRequestID, UserID)
);
GO

CREATE TABLE wr.DedupDecisions (
    DecisionID      INT IDENTITY(1,1) PRIMARY KEY,
    UserID          INT NOT NULL REFERENCES app.Users(UserID),
    CandidateID     INT NOT NULL REFERENCES wr.WorkRequests(WorkRequestID),
    ResultingID     INT NULL REFERENCES wr.WorkRequests(WorkRequestID),
    Decision        NVARCHAR(20) NOT NULL CHECK (Decision IN ('confirmed','rejected')),
    SimilarityScore DECIMAL(5,3) NOT NULL,
    DistanceMeters  DECIMAL(9,2) NULL,
    CreatedAt       DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO

CREATE TABLE wr.Approvals (
    ApprovalID     INT IDENTITY(1,1) PRIMARY KEY,
    WorkRequestID  INT NOT NULL REFERENCES wr.WorkRequests(WorkRequestID),
    StageCode      NVARCHAR(30) NOT NULL,
    StageOrder     INT NOT NULL,
    ApproverUserID INT NULL REFERENCES app.Users(UserID),
    ApproverName   NVARCHAR(160) NULL,
    Decision       NVARCHAR(20) NULL
        CHECK (Decision IN ('approved','denied','returned')),
    DecisionAt     DATETIME2 NULL,
    Comment        NVARCHAR(MAX) NULL,
    ReturnToStage  NVARCHAR(30) NULL,
    IsRequired     BIT NOT NULL DEFAULT 1,
    SkipReason     NVARCHAR(120) NULL,
    CreatedAt      DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_WRApprovals ON wr.Approvals(WorkRequestID, StageOrder);
GO

-- Section 5.1: notes, attachments, and status history carry a real
-- foreign key to the request table of this database. Script 00 could
-- not declare it because the request table did not exist yet.
ALTER TABLE app.Notes
    ADD CONSTRAINT FK_Notes_Request
    FOREIGN KEY (RequestID) REFERENCES wr.WorkRequests(WorkRequestID);
GO
ALTER TABLE app.Attachments
    ADD CONSTRAINT FK_Attachments_Request
    FOREIGN KEY (RequestID) REFERENCES wr.WorkRequests(WorkRequestID);
GO
ALTER TABLE app.StatusHistory
    ADD CONSTRAINT FK_StatusHistory_Request
    FOREIGN KEY (RequestID) REFERENCES wr.WorkRequests(WorkRequestID);
GO
