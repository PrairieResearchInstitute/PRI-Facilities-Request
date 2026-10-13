-- ============================================================
-- PRI Facilities Request System
-- 14_workflow.sql
--   Back end access by NetID, the two new request types that begin on
--   the front end and are decided on the back end, and the stages every
--   request type moves through.
-- Run against all three databases; blocks guard themselves.
-- ============================================================

-- ---------- who may see the back end ----------
-- Role says what a person may do; this says whether they see the
-- administration side at all. Typically the survey's facilities and
-- safety staff, the director, and the director's proxy.
IF OBJECT_ID('app.AdminAccess', 'U') IS NULL
EXEC('
CREATE TABLE app.AdminAccess (
    AdminAccessID INT IDENTITY(1,1) PRIMARY KEY,
    NetID       NVARCHAR(64) NOT NULL,
    SurveyCode  NVARCHAR(10) NULL,          -- null means every survey
    -- Not named Function: that is a reserved word in SQL Server.
    AccessFunction NVARCHAR(30) NOT NULL DEFAULT ''facilities''
        CHECK (AccessFunction IN (''director'',''proxy'',''facilities'',''safety'',
                            ''finance'',''executive'',''deputy'',''inventory'',''it'',''studio'')),
    Note        NVARCHAR(200) NULL,
    IsActive    BIT NOT NULL DEFAULT 1,
    GrantedAt   DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    GrantedByNetID NVARCHAR(64) NULL,
    CONSTRAINT UQ_AdminAccess UNIQUE (NetID, SurveyCode, AccessFunction)
);
CREATE INDEX IX_AdminAccess_NetID ON app.AdminAccess(NetID, IsActive);');
GO

-- ---------- the stages a request moves through ----------
-- Editable, so a change of practice is a change of data. The front end
-- shows this as a chart; the back end walks a request along it.
IF OBJECT_ID('ref.StatusFlow', 'U') IS NULL
EXEC('
CREATE TABLE ref.StatusFlow (
    RequestType  NVARCHAR(20) NOT NULL,     -- work, shop, move, space, key
    StatusCode   NVARCHAR(30) NOT NULL,
    StageOrder   INT NOT NULL,
    Label        NVARCHAR(60) NOT NULL,
    Description  NVARCHAR(300) NULL,
    OwnerHint    NVARCHAR(60) NULL,         -- who usually moves it on
    IsTerminal   BIT NOT NULL DEFAULT 0,
    NotifySubmitter BIT NOT NULL DEFAULT 1,
    IsActive     BIT NOT NULL DEFAULT 1,
    CONSTRAINT PK_StatusFlow PRIMARY KEY (RequestType, StatusCode)
);');
GO

IF OBJECT_ID('ref.StatusFlow', 'U') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ref.StatusFlow)
BEGIN
    INSERT INTO ref.StatusFlow (RequestType, StatusCode, StageOrder, Label, Description, OwnerHint, IsTerminal) VALUES
    ('work','open',10,'Open','Filed and waiting to be looked at','Facilities coordinator',0),
    ('work','triaged',20,'Triaged','Severity and cost confirmed, work identified','Facilities coordinator',0),
    ('work','awaiting_approval',30,'Awaiting approval','Cost crossed a threshold and a signature is needed','Fund manager, Director',0),
    ('work','scheduled',40,'Scheduled','On the calendar with a trade','Facilities',0),
    ('work','in_progress',50,'In progress','Work has started','Facilities',0),
    ('work','on_hold',55,'On hold','Waiting on a part, access, or weather','Facilities',0),
    ('work','work_done',60,'Work done','The trade has finished; awaiting a look',' Facilities',0),
    ('work','verified',70,'Verified','Somebody confirmed it is actually fixed','Facilities coordinator',0),
    ('work','handed_off',75,'Transferred to F&S','Passed to the campus work management system','Facilities coordinator',0),
    ('work','closed',80,'Closed','Finished and closed out','Facilities coordinator',1),
    ('work','declined',90,'Declined','Not being done, with a reason','Facilities coordinator',1),
    ('work','merged',95,'Merged','Folded into another request','Facilities coordinator',1),

    ('shop','submitted',10,'Submitted','Filed and copied to the account PI','Shop',0),
    ('shop','awaiting_approval',20,'Awaiting approval','With the PI, fund manager, or shop supervisor','Approvers',0),
    ('shop','approved',30,'Approved','Cleared to be scheduled','Shop supervisor',0),
    ('shop','in_shop',40,'In the shop','Work under way','Shop',0),
    ('shop','complete',50,'Complete','Work finished, awaiting closeout','Shop supervisor',0),
    ('shop','closed',60,'Closed','Finished and closed out','Shop supervisor',1),
    ('shop','denied',70,'Denied','Refused, with a reason','Approvers',1),

    ('move','open',10,'Open','Filed and waiting for review','Inventory',0),
    ('move','it_review',20,'IT review','Technology items are on the request','IT',0),
    ('move','inventory_review',30,'Inventory review','Property records checked','Inventory',0),
    ('move','facilities_review',40,'Facilities review','Movers and access arranged','Facilities',0),
    ('move','scheduled',50,'Scheduled','On the calendar','Facilities',0),
    ('move','complete',60,'Complete','Items moved','Facilities',0),
    ('move','closed',70,'Closed','Property records updated and closed out','Inventory',1),
    ('move','declined',80,'Declined','Not being done, with a reason','Inventory',1),

    ('space','submitted',10,'Submitted','Filed and waiting for committee review','Survey committee',0),
    ('space','committee_review',20,'Committee review','With the survey space committee','Survey committee',0),
    ('space','director_review',30,'Director review','With the survey director','Director',0),
    ('space','approved',40,'Approved','Space granted, awaiting assignment','Facilities',0),
    ('space','assigned',50,'Assigned','Room assignment updated','Facilities',0),
    ('space','closed',60,'Closed','Finished and closed out','Facilities',1),
    ('space','denied',70,'Denied','Refused, with a reason','Director',1),

    ('key','submitted',10,'Submitted','Filed and waiting for approval','Director or proxy',0),
    ('key','director_review',20,'Director review','With the director or their proxy','Director or proxy',0),
    ('key','approved',30,'Approved','Cleared for the key to be cut or issued','Facilities',0),
    ('key','issued',40,'Issued','Key handed over and recorded in the register','Facilities',0),
    ('key','closed',50,'Closed','Closed out','Facilities',1),
    ('key','denied',60,'Denied','Refused, with a reason','Director or proxy',1);
END
GO

-- ---------- space and key requests ----------
-- Both begin on the front end and are decided on the back end, so they
-- live with the work database, which is where space and keys already are.
IF OBJECT_ID('wr.WorkRequests', 'U') IS NOT NULL AND SCHEMA_ID('rq') IS NULL
EXEC('CREATE SCHEMA rq');
GO

IF SCHEMA_ID('rq') IS NOT NULL AND OBJECT_ID('rq.SpaceRequests', 'U') IS NULL
EXEC('
CREATE TABLE rq.SpaceRequests (
    SpaceRequestID INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber   NVARCHAR(24) NOT NULL UNIQUE,
    RequestedByUserID INT NOT NULL REFERENCES app.Users(UserID),
    RequestedByNetID  NVARCHAR(64) NOT NULL,
    SurveyCode     NVARCHAR(10) NULL,
    RequestKind    NVARCHAR(30) NOT NULL DEFAULT ''additional''
        CHECK (RequestKind IN (''additional'',''change_use'',''relocate'',''release'',''shared'')),
    CurrentRoomCode  NVARCHAR(40) NULL,
    CurrentBldgNumber NVARCHAR(10) NULL,
    CurrentRoomID    NVARCHAR(40) NULL,
    RequestedBldgNumber NVARCHAR(10) NULL,
    RequestedRoomID  NVARCHAR(40) NULL,
    RequestedRoomCode NVARCHAR(40) NULL,
    PeopleAffected INT NULL,
    SpaceUse       NVARCHAR(120) NULL,
    NeededByDate   DATE NULL,
    Justification  NVARCHAR(MAX) NOT NULL,
    Status         NVARCHAR(30) NOT NULL DEFAULT ''submitted'',
    DecisionNote   NVARCHAR(MAX) NULL,
    AssignedRoomCode NVARCHAR(40) NULL,
    CreatedAt      DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt      DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    ClosedAt       DATETIME2 NULL
);
CREATE INDEX IX_SpaceReq_Status ON rq.SpaceRequests(Status);');
GO

IF SCHEMA_ID('rq') IS NOT NULL AND OBJECT_ID('rq.KeyRequests', 'U') IS NULL
EXEC('
CREATE TABLE rq.KeyRequests (
    KeyRequestID   INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber   NVARCHAR(24) NOT NULL UNIQUE,
    RequestedByUserID INT NOT NULL REFERENCES app.Users(UserID),
    RequestedByNetID  NVARCHAR(64) NOT NULL,
    SurveyCode     NVARCHAR(10) NULL,
    HolderName     NVARCHAR(160) NOT NULL,
    HolderNetID    NVARCHAR(64) NULL,
    EmpStatus      NVARCHAR(40) NULL,
    BldgNumber     NVARCHAR(10) NULL,
    FloorCode      NVARCHAR(20) NULL,
    RoomID         NVARCHAR(40) NULL,
    RoomCode       NVARCHAR(40) NULL,
    KeyType        NVARCHAR(60) NULL,
    Quantity       INT NOT NULL DEFAULT 1,
    RequestKind    NVARCHAR(20) NOT NULL DEFAULT ''new''
        CHECK (RequestKind IN (''new'',''replacement'',''additional'',''return'')),
    Justification  NVARCHAR(MAX) NOT NULL,
    NeededByDate   DATE NULL,
    Status         NVARCHAR(30) NOT NULL DEFAULT ''submitted'',
    DecisionNote   NVARCHAR(MAX) NULL,
    IssuedRoomKeyID INT NULL,
    CreatedAt      DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt      DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    ClosedAt       DATETIME2 NULL
);
CREATE INDEX IX_KeyReq_Status ON rq.KeyRequests(Status);');
GO

-- One approval table for both, matching the shape used elsewhere.
IF SCHEMA_ID('rq') IS NOT NULL AND OBJECT_ID('rq.Approvals', 'U') IS NULL
EXEC('
CREATE TABLE rq.Approvals (
    ApprovalID   INT IDENTITY(1,1) PRIMARY KEY,
    RequestType  NVARCHAR(10) NOT NULL,     -- space, key
    RequestID    INT NOT NULL,
    StageCode    NVARCHAR(30) NOT NULL,     -- committee, director
    StageOrder   INT NOT NULL,
    ApproverUserID INT NULL REFERENCES app.Users(UserID),
    ApproverName NVARCHAR(160) NULL,
    Decision     NVARCHAR(20) NULL
        CHECK (Decision IN (''approved'',''denied'',''returned'')),
    DecisionAt   DATETIME2 NULL,
    Comment      NVARCHAR(MAX) NULL,
    ReturnToStage NVARCHAR(30) NULL,
    IsRequired   BIT NOT NULL DEFAULT 1,
    SkipReason   NVARCHAR(120) NULL,
    CreatedAt    DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
CREATE INDEX IX_rqApprovals ON rq.Approvals(RequestType, RequestID, StageOrder);');
GO

-- Ticket prefixes for the new types.
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_sp')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('ticket_prefix_sp', 'SPR', 'text', 'Space request prefix',
            'Ticket prefix for space requests; tickets read SPR-FY27-00001', 260);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_ky')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('ticket_prefix_ky', 'KEY', 'text', 'Key request prefix',
            'Ticket prefix for key requests; tickets read KEY-FY27-00001', 270);
GO
