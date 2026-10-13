-- ============================================================
-- PRI Facilities Request System
-- 03_shop.sql   DATABASE: PRI_FacilitiesShop ONLY
-- Run after 00_shared_objects.sql.
-- Source: Build Specification v1.0, Appendix B
-- ============================================================

CREATE SCHEMA sh;
GO

CREATE TABLE sh.ShopRequests (
    ShopRequestID         INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber          NVARCHAR(24) NOT NULL UNIQUE,
    RequestedByUserID     INT NOT NULL REFERENCES app.Users(UserID),
    RequestedByNetID      NVARCHAR(64) NOT NULL,
    NeededByDate          DATE NOT NULL,
    CfopCode              NVARCHAR(40) NOT NULL,
    SurveyCode            NVARCHAR(10) NOT NULL,
    AccountPI             NVARCHAR(160) NOT NULL,
    FundManager           NVARCHAR(160) NOT NULL,
    WorkDescription       NVARCHAR(MAX) NOT NULL,
    BusinessJustification NVARCHAR(MAX) NOT NULL,
    BuildingID            INT NULL REFERENCES ref.Buildings(BuildingID),
    BldgNumber            NVARCHAR(10) NULL,
    RoomNumber            NVARCHAR(40) NULL,
    WrittenLocation       NVARCHAR(300) NULL,
    EstimateAmount        DECIMAL(12,2) NULL,
    Status                NVARCHAR(20) NOT NULL DEFAULT 'submitted'
        CHECK (Status IN ('submitted','awaiting_approval','approved',
                          'in_shop','complete','closed','denied')),
    ImportBatchID         INT NULL REFERENCES app.ImportBatches(ImportBatchID),
    LegacyReference       NVARCHAR(60) NULL,
    CreatedAt             DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt             DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    ClosedAt              DATETIME2 NULL
);
GO

-- Locally maintained CFOP directory. The university financial system
-- remains the source of CFOP validity (spec Section 1); this table is the
-- application's stand-in on the closed network, maintained through Admin.
CREATE TABLE ref.CfopAccounts (
    CfopID      INT IDENTITY(1,1) PRIMARY KEY,
    CfopCode    NVARCHAR(40)  NOT NULL UNIQUE,
    SurveyCode  NVARCHAR(10)  NOT NULL,
    AccountPI   NVARCHAR(160) NOT NULL,
    FundManager NVARCHAR(160) NOT NULL,
    IsActive    BIT NOT NULL DEFAULT 1
);
GO

CREATE TABLE sh.Approvals (
    ApprovalID     INT IDENTITY(1,1) PRIMARY KEY,
    ShopRequestID  INT NOT NULL REFERENCES sh.ShopRequests(ShopRequestID),
    StageCode      NVARCHAR(30) NOT NULL,   -- account_pi, fund_manager, shop_supervisor
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
CREATE INDEX IX_SHApprovals ON sh.Approvals(ShopRequestID, StageOrder);
GO

-- Section 5.1: wire the shared child tables to this database's
-- request table now that it exists.
ALTER TABLE app.Notes
    ADD CONSTRAINT FK_Notes_Request
    FOREIGN KEY (RequestID) REFERENCES sh.ShopRequests(ShopRequestID);
GO
ALTER TABLE app.Attachments
    ADD CONSTRAINT FK_Attachments_Request
    FOREIGN KEY (RequestID) REFERENCES sh.ShopRequests(ShopRequestID);
GO
ALTER TABLE app.StatusHistory
    ADD CONSTRAINT FK_StatusHistory_Request
    FOREIGN KEY (RequestID) REFERENCES sh.ShopRequests(ShopRequestID);
GO
