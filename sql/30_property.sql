/* ---------------------------------------------------------------------
   Property updates: one request for every change to a property record.

   Registering an item, moving it, handing it to somebody else and taking
   it off the books were four separate screens. They are not four things.
   They are one thing - the record is wrong, or out of date, and here is
   what it should say - and the only real difference between them is which
   columns change. So there is one form, one request, one place it is
   applied, and one history.

   The request exists rather than the form writing straight to the
   inventory because most people who know a piece of equipment has moved
   are not the people trusted to edit the inventory. Somebody who does
   hold that trust files and applies in the same motion; everybody else
   files, and the property office applies.
   --------------------------------------------------------------------- */

USE PRI_FacilitiesWork;
GO

IF OBJECT_ID('rq.PropertyUpdates') IS NULL
CREATE TABLE rq.PropertyUpdates (
    PropertyUpdateID    INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber        NVARCHAR(30)  NOT NULL UNIQUE,
    RequestedByNetID    NVARCHAR(50)  NOT NULL,
    RequestedByUserID   INT NULL,
    SurveyCode          NVARCHAR(10)  NULL,

    /* What is being changed. InventoryID is null for a registration,
       because the record does not exist yet. */
    ChangeKind          NVARCHAR(20)  NOT NULL,
    InventoryID         INT NULL,
    PropertyTag         NVARCHAR(50)  NULL,
    PurchaseItemID      INT NULL,      -- when it came from a purchase line

    /* Registration and correction: what the record should say. */
    Description         NVARCHAR(300) NULL,
    Model               NVARCHAR(100) NULL,
    SerialNumber        NVARCHAR(100) NULL,
    EquipClass          NVARCHAR(30)  NULL,
    Cost                DECIMAL(12,2) NULL,
    AcquiredOn          NVARCHAR(40)  NULL,
    FundingSource       NVARCHAR(200) NULL,
    ConditionCode       NVARCHAR(30)  NULL,

    /* Where it is now, and who answers for it. */
    ToBldgNumber        NVARCHAR(10)  NULL,
    ToRoomID            NVARCHAR(30)  NULL,
    ToRoomCode          NVARCHAR(40)  NULL,
    ToPerson            NVARCHAR(150) NULL,
    ToPersonNetID       NVARCHAR(50)  NULL,

    /* Leaving the books. */
    RemovalReason       NVARCHAR(30)  NULL,
    RemovedOn           NVARCHAR(40)  NULL,
    SalvageValue        DECIMAL(12,2) NULL,

    Reason              NVARCHAR(1000) NULL,
    Status              NVARCHAR(20)  NOT NULL DEFAULT 'submitted',
    DecisionNote        NVARCHAR(1000) NULL,
    AppliedByNetID      NVARCHAR(50)  NULL,
    AppliedAt           DATETIME2 NULL,
    CreatedAt           DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2 NOT NULL DEFAULT SYSDATETIME(),

    CONSTRAINT CK_PropertyUpdates_Kind CHECK (ChangeKind IN
        ('register', 'move', 'reassign', 'correct', 'remove')),
    CONSTRAINT CK_PropertyUpdates_Status CHECK (Status IN
        ('submitted', 'applied', 'declined'))
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_PropertyUpdates_Status')
CREATE INDEX IX_PropertyUpdates_Status ON rq.PropertyUpdates (Status, CreatedAt DESC);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_PropertyUpdates_Inventory')
CREATE INDEX IX_PropertyUpdates_Inventory ON rq.PropertyUpdates (InventoryID);
GO

/* Its own ticket series, so a property change is citable in an email the
   way a work order is. */
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_prp')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('ticket_prefix_prp', 'PRP', 'text', 'Property update ticket prefix',
        'The letters in front of a property update ticket number, as PRP-FY27-00001.', 190);
GO

/* The kinds of change, editable like every other list the forms offer. */
IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'property_change')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('property_change', 'Kinds of property change', 'Equipment',
        'What a property update request can say happened to an item.',
        'Property update form', 60);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'property_change')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('property_change', 'register', 'It arrived and needs an inventory number',   1, 1, 1),
 ('property_change', 'move',     'It is in a different room now',              2, 1, 1),
 ('property_change', 'reassign', 'Somebody else is responsible for it',        3, 1, 1),
 ('property_change', 'correct',  'The record is wrong and needs correcting',   4, 1, 1),
 ('property_change', 'remove',   'It has left: surplus, disposal, loss, transfer', 5, 1, 1);
GO

/* Salvage value was never recorded, and "what did we get for it" is the
   first question asked about a surplussed item. */
IF COL_LENGTH('gis.Inventory', 'SalvageValue') IS NULL
ALTER TABLE gis.Inventory ADD SalvageValue DECIMAL(12,2) NULL;
GO
IF COL_LENGTH('gis.Inventory', 'EquipClass') IS NULL
ALTER TABLE gis.Inventory ADD EquipClass NVARCHAR(30) NULL;
GO

/* The property office is told when something is filed that it has to
   apply. One rule, addressed the way every other rule is. */
IF NOT EXISTS (SELECT 1 FROM app.NotifyRules WHERE EventCode = 'property_update_filed')
INSERT INTO app.NotifyRules (EventCode, MatchType, Email, DisplayName, Note, IsActive)
VALUES ('property_update_filed', 'always', 'configure-me@example.edu', 'Devin Blobaum',
        'Told when somebody files a change to a property record that needs applying.', 1);
GO
