-- ============================================================
-- PRI Facilities Request System
-- 27_purchase.sql
--   The PRI Purchase Request Application, brought inside.
--
--   It is an approval process, and this system is already an approval
--   engine, so what is added is the shape of a purchase rather than new
--   machinery: the same tickets, the same rq.Approvals, the same
--   notifications, the same "waiting on you".
--
--   One thing about it is genuinely different and is modelled rather
--   than flattened: EACH FUND LINE CARRIES TWO APPROVALS, not one. The
--   principal investigator - or their named proxy, who at PRI is the
--   unit's administrative assistant - and then the fund manager. A
--   purchase may be split across several funds by dollar, by percentage
--   or by item, so approval is a grid rather than a queue, and a
--   purchase on three funds needs six agreements before it reaches
--   anybody senior.
--
--   The CFOPA is stored BOTH ways, and that is deliberate. The whole
--   code is what people type, what the account directory matches on, and
--   what every other request type in this system already holds. The
--   parts - chart, fund, organisation, program, activity - are what the
--   university system needs when a requisition is injected into it.
--   They are DERIVED from the whole on write, never typed, so the two
--   representations cannot drift apart.
-- Work database.
-- ============================================================

IF SCHEMA_ID('rq') IS NULL EXEC('CREATE SCHEMA rq');
GO

IF OBJECT_ID('rq.PurchaseRequests', 'U') IS NULL
EXEC('
CREATE TABLE rq.PurchaseRequests (
    PurchaseRequestID INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber      NVARCHAR(30)  NOT NULL UNIQUE,
    LegacyReference   NVARCHAR(40)  NULL,   -- their FY27-882 style id, when imported
    RequestedByNetID  NVARCHAR(64)  NULL,   -- who keyed it
    RequestedByUserID INT NULL,
    SurveyCode        NVARCHAR(20)  NULL,

    -- Buyer information, in the words the current application uses
    StaffRequesting   NVARCHAR(160) NULL,   -- "Deliver To" on the form
    StaffRequestingNetID NVARCHAR(64) NULL,
    PurchaseReqDate   NVARCHAR(40)  NULL,
    PurchaseType      NVARCHAR(80)  NULL,   -- PCard/TCard/Reimbursement, iBuy, ...
    Destination       NVARCHAR(200) NULL,   -- receiving location, or Non-deliverable
    DeliverBldgNumber NVARCHAR(20)  NULL,
    DeliverRoomID     NVARCHAR(40)  NULL,
    DeliverRoomCode   NVARCHAR(40)  NULL,   -- the room connector, when it resolves
    BusinessJustification NVARCHAR(MAX) NULL,
    AdditionalNotes   NVARCHAR(MAX) NULL,

    -- Suggested vendor
    VendorName        NVARCHAR(200) NULL,
    VendorWeb         NVARCHAR(300) NULL,
    VendorAddress1    NVARCHAR(200) NULL,
    VendorAddress2    NVARCHAR(200) NULL,
    VendorCity        NVARCHAR(120) NULL,
    VendorState       NVARCHAR(20)  NULL,
    VendorZip         NVARCHAR(20)  NULL,
    VendorPhone       NVARCHAR(60)  NULL,
    VendorNotes       NVARCHAR(400) NULL,

    SplitMethod       NVARCHAR(10)  NOT NULL DEFAULT ''dollar'',  -- dollar|percent|item
    TotalCost         DECIMAL(12,2) NULL,

    Status            NVARCHAR(40)  NOT NULL DEFAULT ''submitted'',
    DecisionNote      NVARCHAR(MAX) NULL,
    OrderReference    NVARCHAR(80)  NULL,   -- the requisition or PO once raised
    OrderedAt         DATETIME2 NULL,
    CreatedAt         DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2 NULL,
    ClosedAt          DATETIME2 NULL
);
CREATE INDEX IX_PurchaseRequests_Status ON rq.PurchaseRequests(Status, SurveyCode);');
GO

-- What is being bought.
IF OBJECT_ID('rq.PurchaseItems', 'U') IS NULL
EXEC('
CREATE TABLE rq.PurchaseItems (
    PurchaseItemID    INT IDENTITY(1,1) PRIMARY KEY,
    PurchaseRequestID INT NOT NULL,
    ItemNo            INT NOT NULL,
    CatalogNumber     NVARCHAR(80)  NULL,
    Description       NVARCHAR(400) NOT NULL,
    Quantity          DECIMAL(12,2) NULL,
    UnitsPerItem      NVARCHAR(40)  NULL,
    UnitCost          DECIMAL(12,2) NULL,
    Amount            DECIMAL(12,2) NULL,   -- quantity x unit cost
    AccountCode       NVARCHAR(40)  NULL,   -- the expense code, e.g. 132300
    ProductUrl        NVARCHAR(500) NULL,
    -- Filled once the thing arrives and is tagged, which is how a
    -- purchase becomes a piece of property instead of ending at the order.
    InventoryID       INT NULL,
    PropertyTag       NVARCHAR(60)  NULL,
    ReceivedAt        DATETIME2 NULL,
    CONSTRAINT FK_PurchaseItems_Request FOREIGN KEY (PurchaseRequestID)
        REFERENCES rq.PurchaseRequests(PurchaseRequestID)
);
CREATE INDEX IX_PurchaseItems_Request ON rq.PurchaseItems(PurchaseRequestID, ItemNo);');
GO

-- Who pays, and the two people who must agree for each fund.
IF OBJECT_ID('rq.PurchaseFunding', 'U') IS NULL
EXEC('
CREATE TABLE rq.PurchaseFunding (
    PurchaseFundingID INT IDENTITY(1,1) PRIMARY KEY,
    PurchaseRequestID INT NOT NULL,
    FundNo            INT NOT NULL,

    -- The whole code, as typed and as the account directory holds it.
    CfopCode          NVARCHAR(40)  NULL,
    ActivityCode      NVARCHAR(40)  NULL,
    AccountDescription NVARCHAR(300) NULL,  -- as it stood when filed

    -- The same thing in parts, for injecting a requisition into the
    -- university system. Derived from CfopCode on write - never typed -
    -- so there is one source of truth and no chance of disagreement.
    Coa               NVARCHAR(4)   NULL,
    FundCode          NVARCHAR(20)  NULL,
    OrgCode           NVARCHAR(20)  NULL,
    ProgramCode       NVARCHAR(20)  NULL,

    SurveyCode        NVARCHAR(20)  NULL,
    AmountValue       DECIMAL(12,2) NULL,   -- dollars, percent, or an item number
    AmountKind        NVARCHAR(10)  NULL,   -- dollar|percent|item

    -- Approval one: the principal investigator, or their proxy.
    PiName            NVARCHAR(160) NULL,
    PiNetID           NVARCHAR(64)  NULL,
    PiEmail           NVARCHAR(200) NULL,
    PiProxyNetID      NVARCHAR(64)  NULL,   -- may act for the PI
    PiProxyName       NVARCHAR(160) NULL,
    PiStatus          NVARCHAR(20)  NOT NULL DEFAULT ''pending'',
    PiDecidedByNetID  NVARCHAR(64)  NULL,
    PiDecidedAt       DATETIME2 NULL,
    PiComment         NVARCHAR(600) NULL,

    -- Approval two: the fund manager.
    FmName            NVARCHAR(160) NULL,
    FmNetID           NVARCHAR(64)  NULL,
    FmEmail           NVARCHAR(200) NULL,
    FmStatus          NVARCHAR(20)  NOT NULL DEFAULT ''pending'',
    FmDecidedByNetID  NVARCHAR(64)  NULL,
    FmDecidedAt       DATETIME2 NULL,
    FmComment         NVARCHAR(600) NULL,

    CONSTRAINT FK_PurchaseFunding_Request FOREIGN KEY (PurchaseRequestID)
        REFERENCES rq.PurchaseRequests(PurchaseRequestID)
);
CREATE INDEX IX_PurchaseFunding_Request ON rq.PurchaseFunding(PurchaseRequestID, FundNo);');
GO

-- The decomposed parts, for a database that already has the table.
IF OBJECT_ID('rq.PurchaseFunding', 'U') IS NOT NULL
   AND COL_LENGTH('rq.PurchaseFunding', 'Coa') IS NULL
    ALTER TABLE rq.PurchaseFunding ADD
        Coa NVARCHAR(4) NULL, FundCode NVARCHAR(20) NULL,
        OrgCode NVARCHAR(20) NULL, ProgramCode NVARCHAR(20) NULL;
GO

-- Approvals rows can name the fund they belong to, so several approvers
-- sit at one stage without being mistaken for one another.
IF COL_LENGTH('rq.Approvals', 'FundingID') IS NULL
    ALTER TABLE rq.Approvals ADD FundingID INT NULL, ApproverEmail NVARCHAR(200) NULL;
GO

-- Everything that happened, in order: the approval timeline the current
-- application shows at the bottom of a submitted request.
IF OBJECT_ID('rq.PurchaseEvents', 'U') IS NULL
EXEC('
CREATE TABLE rq.PurchaseEvents (
    PurchaseEventID   INT IDENTITY(1,1) PRIMARY KEY,
    PurchaseRequestID INT NOT NULL,
    PurchaseFundingID INT NULL,
    EventCode         NVARCHAR(40)  NOT NULL,   -- submitted, pi_approved, fm_denied, ordered
    Detail            NVARCHAR(600) NULL,
    ByNetID           NVARCHAR(64)  NULL,
    ByName            NVARCHAR(160) NULL,
    CreatedAt         DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_PurchaseEvents_Request FOREIGN KEY (PurchaseRequestID)
        REFERENCES rq.PurchaseRequests(PurchaseRequestID)
);
CREATE INDEX IX_PurchaseEvents_Request ON rq.PurchaseEvents(PurchaseRequestID, PurchaseEventID);');
GO

-- ---------- settings -------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_pur')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('ticket_prefix_pur', 'PUR', 'text', 'Purchase request prefix',
            'The letters on a purchase request ticket, before the fiscal year.', 250);
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'purchase_director_threshold')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('purchase_director_threshold', '5000', 'number', 'Purchase: director threshold',
            'Above this total the unit director approves, after every fund has agreed.', 255);
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'purchase_executive_threshold')
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
    VALUES ('purchase_executive_threshold', '25000', 'number', 'Purchase: executive threshold',
            'Above this total the executive director approves as well.', 256);
GO

-- ---------- the lists the form offers --------------------------------
MERGE ref.PickListTypes AS t
USING (VALUES
 ('purchase_type', 'Purchasing', 'Purchase methods',
  'How the purchase will be made. It is the first thing the form asks and it decides what else is needed.',
  'Purchase request', 10),
 ('purchase_destination', 'Purchasing', 'Destinations',
  'Where the goods go. Central Receiving takes most of it; some purchases never arrive anywhere.',
  'Purchase request', 20),
 ('purchase_status', 'Purchasing', 'Purchase request stages',
  'The steps a purchase request walks through, from submitted to received.',
  'Purchase request', 30),
 ('approval_status', 'Purchasing', 'Approval decisions',
  'What an approver can say about a fund line. "Changes Required" sends it back to the requester rather than ending it.',
  'Purchase request', 40),
 ('purchase_action', 'Purchasing', 'Actions on a submitted request',
  'What can be done with a purchase request once it has been filed.',
  'Purchase request', 50)
) AS s (ListKey, GroupName, Title, Description, UsedOn, SortOrder)
ON t.ListKey = s.ListKey
WHEN MATCHED THEN UPDATE SET t.GroupName = s.GroupName, t.Title = s.Title,
    t.Description = s.Description, t.UsedOn = s.UsedOn, t.SortOrder = s.SortOrder
WHEN NOT MATCHED THEN INSERT (ListKey, GroupName, Title, Description, UsedOn, SortOrder)
    VALUES (s.ListKey, s.GroupName, s.Title, s.Description, s.UsedOn, s.SortOrder);
GO

MERGE ref.PickLists AS t
USING (VALUES
 ('purchase_type', 'pcard',      'PCard/TCard/Reimbursement', 10, 0),
 ('purchase_type', 'ibuy',       'iBuy catalogue order', 20, 0),
 ('purchase_type', 'po',         'Purchase order', 30, 0),
 ('purchase_type', 'contract',   'Against an existing contract', 40, 0),
 ('purchase_type', 'solesource', 'Sole source', 50, 0),
 ('purchase_type', 'bid',        'Competitive bid required', 60, 0),
 ('purchase_type', 'transfer',   'Transfer or internal charge', 70, 0),
 ('purchase_type', 'other',      'Other', 99, 0),

 ('purchase_destination', 'central', 'PRI Central Receiving (2204 Griffith Dr, Champaign)', 10, 0),
 ('purchase_destination', 'direct',  'Direct to the building and room given above', 20, 0),
 ('purchase_destination', 'field',   'A field station - say which in the shipping instructions', 30, 0),
 ('purchase_destination', 'none',    'Non-deliverable', 40, 0),

 ('purchase_status', 'submitted',       'Submitted', 10, 1),
 ('purchase_status', 'fund_review',     'With the fund approvers', 20, 1),
 ('purchase_status', 'director_review', 'With the director', 30, 1),
 ('purchase_status', 'approved',        'Approved, with purchasing', 40, 1),
 ('purchase_status', 'ordered',         'Ordered', 50, 1),
 ('purchase_status', 'received',        'Received', 60, 1),
 ('purchase_status', 'closed',          'Closed', 70, 1),
 ('purchase_status', 'changes_required', 'Changes required', 25, 1),
 ('purchase_status', 'denied',          'Denied', 80, 1),

 ('purchase_action', 'status',    'Submit Purchase Approval Status', 10, 1),
 ('purchase_action', 'edit',      'Edit Purchase Request', 20, 1),
 ('purchase_action', 'replicate', 'Replicate As New Purchase Request', 30, 1),
 ('purchase_action', 'new',       'Create New Purchase Request', 40, 1),

 ('approval_status', 'pending',          'Pending', 10, 1),
 ('approval_status', 'approved',         'Approved', 20, 1),
 ('approval_status', 'changes_required', 'Changes Required', 30, 1)
) AS s (ListKey, Code, Label, SortOrder, IsProtected)
ON t.ListKey = s.ListKey AND t.Code = s.Code
WHEN NOT MATCHED THEN
    INSERT (ListKey, Code, Label, SortOrder, IsActive, IsProtected, UpdatedAt, UpdatedByNetID)
    VALUES (s.ListKey, s.Code, s.Label, s.SortOrder, 1, s.IsProtected, SYSDATETIME(), 'seed');
GO

-- ---------- the stages, so the public chart shows them ---------------
MERGE ref.StatusFlow AS t
USING (VALUES
 ('purchase', 'submitted',       1, 'Submitted', 'Filed and waiting for the fund approvers.', 'Requester', 0, 1),
 ('purchase', 'fund_review',     2, 'With the fund approvers', 'Each fund needs its principal investigator and its fund manager to agree.', 'PI and fund manager', 0, 1),
 ('purchase', 'director_review', 3, 'With the director', 'Above the threshold the unit director signs once every fund has agreed.', 'Director', 0, 1),
 ('purchase', 'approved',        4, 'Approved', 'Approved, and with purchasing to be raised.', 'Purchasing', 0, 1),
 ('purchase', 'ordered',         5, 'Ordered', 'The order has been placed.', 'Purchasing', 0, 1),
 ('purchase', 'received',        6, 'Received', 'Goods received. Anything tagged becomes a property record.', 'Receiving', 0, 1),
 ('purchase', 'closed',          7, 'Closed', 'Complete.', 'Purchasing', 1, 1),
 ('purchase', 'changes_required', 3, 'Changes required', 'An approver has asked for something to be changed; it is back with the requester.', 'Requester', 0, 1),
 ('purchase', 'denied',          8, 'Denied', 'Not approved.', 'Approver', 1, 1)
) AS s (RequestType, StatusCode, StageOrder, Label, Description, OwnerHint, IsTerminal, NotifySubmitter)
ON t.RequestType = s.RequestType AND t.StatusCode = s.StatusCode
WHEN NOT MATCHED THEN
    INSERT (RequestType, StatusCode, StageOrder, Label, Description, OwnerHint,
            IsTerminal, NotifySubmitter, IsActive)
    VALUES (s.RequestType, s.StatusCode, s.StageOrder, s.Label, s.Description, s.OwnerHint,
            s.IsTerminal, s.NotifySubmitter, 1);
GO
