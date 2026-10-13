-- ============================================================
-- PRI Facilities Request System
-- 02_move.sql   DATABASE: PRI_FacilitiesMove ONLY
-- Run after 00_shared_objects.sql.
-- Source: Build Specification v1.0, Appendix B
-- ============================================================

CREATE SCHEMA mv;
GO

CREATE TABLE mv.MoveRequests (
    MoveRequestID     INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber      NVARCHAR(24) NOT NULL UNIQUE,
    RequestedByUserID INT NOT NULL REFERENCES app.Users(UserID),
    RequestedByNetID  NVARCHAR(64) NOT NULL,
    SurveyCode        NVARCHAR(10) NULL,
    NeededByDate      DATE NOT NULL,
    IsPersonMoving    BIT NOT NULL DEFAULT 0,
    HasTechItems      BIT NOT NULL DEFAULT 0,
    AdditionalNotes   NVARCHAR(MAX) NULL,
    Status            NVARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (Status IN ('open','it_review','inventory_review',
                          'facilities_review','scheduled','complete',
                          'closed','declined')),
    ImportBatchID     INT NULL REFERENCES app.ImportBatches(ImportBatchID),
    LegacyReference   NVARCHAR(60) NULL,
    CreatedAt         DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    ClosedAt          DATETIME2 NULL
);
GO

CREATE TABLE mv.MoveItems (
    MoveItemID       INT IDENTITY(1,1) PRIMARY KEY,
    MoveRequestID    INT NOT NULL REFERENCES mv.MoveRequests(MoveRequestID),
    MoveType         NVARCHAR(20) NOT NULL
        CHECK (MoveType IN ('move','transfer','trash','surplus')),
    ItemType         NVARCHAR(60) NOT NULL,
    PTag             NVARCHAR(40) NULL,
    PRITag           NVARCHAR(40) NULL,
    SerialNumber     NVARCHAR(60) NULL,
    Description      NVARCHAR(300) NOT NULL,
    FromBuildingID   INT NULL REFERENCES ref.Buildings(BuildingID),
    FromBldgNumber   NVARCHAR(10) NULL,
    FromRoom         NVARCHAR(40) NULL,
    ToBuildingID     INT NULL REFERENCES ref.Buildings(BuildingID),
    ToBldgNumber     NVARCHAR(10) NULL,
    ToRoom           NVARCHAR(40) NULL,
    CurrentCustodian NVARCHAR(64) NULL,
    NewCustodian     NVARCHAR(64) NULL,
    IsTechItem       BIT NOT NULL DEFAULT 0,
    Notes            NVARCHAR(MAX) NULL,
    CONSTRAINT CK_MoveItem_Destination CHECK (
        MoveType IN ('trash','surplus') OR (ToBuildingID IS NOT NULL AND ToRoom IS NOT NULL)
    )
);
GO
CREATE INDEX IX_MoveItems_Parent ON mv.MoveItems(MoveRequestID);
CREATE INDEX IX_MoveItems_PTag   ON mv.MoveItems(PTag);
GO

CREATE TABLE mv.Reviews (
    ReviewID      INT IDENTITY(1,1) PRIMARY KEY,
    MoveRequestID INT NOT NULL REFERENCES mv.MoveRequests(MoveRequestID),
    StageCode     NVARCHAR(30) NOT NULL,   -- it, inventory, facilities
    StageOrder    INT NOT NULL,
    ReviewerUserID INT NULL REFERENCES app.Users(UserID),
    Decision      NVARCHAR(20) NULL
        CHECK (Decision IN ('cleared','returned','declined')),
    DecisionAt    DATETIME2 NULL,
    Comment       NVARCHAR(MAX) NULL,
    IsRequired    BIT NOT NULL DEFAULT 1,
    SkipReason    NVARCHAR(120) NULL,
    CreatedAt     DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_MVReviews ON mv.Reviews(MoveRequestID, StageOrder);
GO

-- Section 5.1: wire the shared child tables to this database's
-- request table now that it exists.
ALTER TABLE app.Notes
    ADD CONSTRAINT FK_Notes_Request
    FOREIGN KEY (RequestID) REFERENCES mv.MoveRequests(MoveRequestID);
GO
ALTER TABLE app.Attachments
    ADD CONSTRAINT FK_Attachments_Request
    FOREIGN KEY (RequestID) REFERENCES mv.MoveRequests(MoveRequestID);
GO
ALTER TABLE app.StatusHistory
    ADD CONSTRAINT FK_StatusHistory_Request
    FOREIGN KEY (RequestID) REFERENCES mv.MoveRequests(MoveRequestID);
GO
