/* ---------------------------------------------------------------------
   The estate: what PRI occupies that it does not own.

   Two different kinds of fact were sitting in one spreadsheet and both
   were lost on import.

   The first kind belongs to the building itself and is true whoever
   holds it: what sort of building it is, who owns it, what condition it
   is in, which survey uses it. Those become columns on ref.Buildings.

   The second kind is an arrangement with somebody else - a lease, a
   ground lease, a sponsor arrangement, a memorandum of understanding -
   and it has a counterparty, a reference, dates, terms, and a split of
   who pays for what. That is not a property of a building; it is a
   document about a building, a building can have more than one over
   time, and it expires. So it is its own table.

   University owned buildings need none of this. It is the off campus and
   near campus facilities that carry agreements, and those are the ones
   where nobody currently knows what expires when.
   --------------------------------------------------------------------- */

USE PRI_FacilitiesSpace;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = 'est')
    EXEC('CREATE SCHEMA est');
GO

IF OBJECT_ID('est.Agreements') IS NULL
CREATE TABLE est.Agreements (
    AgreementID       INT IDENTITY(1,1) PRIMARY KEY,

    /* Buildings are in this database, so this is a real relationship
       rather than a natural key held at arm's length. */
    BuildingID        INT           NOT NULL,
    BldgNumber        NVARCHAR(10)  NOT NULL,

    AgreementKind     NVARCHAR(30)  NOT NULL,
    Reference         NVARCHAR(60)  NULL,
    Counterparty      NVARCHAR(200) NULL,
    Title             NVARCHAR(300) NULL,

    /* The words the estate spreadsheet used, kept as they were written
       rather than forced into a tidier vocabulary nobody uses. */
    TenureStatus      NVARCHAR(60)  NULL,
    TermsBasis        NVARCHAR(60)  NULL,
    LeaseCostBasis    NVARCHAR(60)  NULL,
    OmCostBasis       NVARCHAR(60)  NULL,

    /* How the lease, utilities and operating cost divide between
       contract money and the indirect cost recovery. They are shares of
       one, not amounts, which is how the spreadsheet held them. */
    ContractShare     DECIMAL(5,4)  NULL,
    IcrShare          DECIMAL(5,4)  NULL,
    AnnualCost        DECIMAL(12,2) NULL,

    StartsOn          DATE          NULL,
    EndsOn            DATE          NULL,
    /* When somebody has to look at it again. Usually before it ends,
       because a renewal decided the week it expires is not a decision. */
    ReviewDueOn       DATE          NULL,
    NoticeDays        INT           NULL,

    Status            NVARCHAR(20)  NOT NULL DEFAULT 'current',
    OwnerName         NVARCHAR(150) NULL,
    OwnerNetID        NVARCHAR(50)  NULL,
    Notes             NVARCHAR(MAX) NULL,

    SourceLabel       NVARCHAR(80)  NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedByNetID    NVARCHAR(64)  NULL,

    CONSTRAINT CK_Agreements_Status CHECK (Status IN
        ('draft', 'current', 'expired', 'superseded', 'ended', 'unknown')),
    CONSTRAINT CK_Agreements_Shares CHECK (
        (ContractShare IS NULL AND IcrShare IS NULL)
        OR (ContractShare BETWEEN 0 AND 1 AND IcrShare BETWEEN 0 AND 1)),
    CONSTRAINT FK_Agreements_Building FOREIGN KEY (BuildingID)
        REFERENCES ref.Buildings (BuildingID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Agreements_Building')
CREATE INDEX IX_Agreements_Building ON est.Agreements (BldgNumber);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Agreements_Due')
CREATE INDEX IX_Agreements_Due ON est.Agreements (ReviewDueOn);
GO

/* Renewals, amendments, notices given, rent reviews. An agreement that
   is only ever overwritten cannot answer what it used to say. */
IF OBJECT_ID('est.AgreementEvents') IS NULL
CREATE TABLE est.AgreementEvents (
    AgreementEventID  INT IDENTITY(1,1) PRIMARY KEY,
    AgreementID       INT NOT NULL,
    EventCode         NVARCHAR(30)  NOT NULL,
    HappenedOn        DATE          NULL,
    Detail            NVARCHAR(1000) NULL,
    ByNetID           NVARCHAR(50)  NULL,
    ByName            NVARCHAR(150) NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_AgreementEvents_Agreement FOREIGN KEY (AgreementID)
        REFERENCES est.Agreements (AgreementID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_AgreementEvents_Agreement')
CREATE INDEX IX_AgreementEvents_Agreement ON est.AgreementEvents (AgreementID);
GO

USE PRI_FacilitiesWork;
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'agreement_warn_days')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('agreement_warn_days', '180', 'int',
        'Warn this many days before a lease or agreement falls due',
        'A lease needs deciding long before it expires. Six months is the default because '
        + 'that is roughly the notice period on most of them.', 430);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'agreement_kind')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('agreement_kind', 'Kinds Of Agreement', 'Estate',
        'What sort of arrangement lets PRI occupy the building.',
        'Building agreements', 150);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'agreement_kind')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('agreement_kind', 'lease',        'Lease',                        1, 1, 1),
 ('agreement_kind', 'ground_lease', 'Ground lease or leasehold',    2, 1, 1),
 ('agreement_kind', 'sponsor',      'Sponsor arrangement',          3, 1, 1),
 ('agreement_kind', 'mou',          'Memorandum of understanding',  4, 1, 1),
 ('agreement_kind', 'licence',      'Licence or permit to occupy',  5, 1, 1),
 ('agreement_kind', 'easement',     'Easement',                     6, 1, 1),
 ('agreement_kind', 'legacy',       'Legacy arrangement, terms not written down', 7, 1, 1),
 ('agreement_kind', 'unknown',      'Not known',                    8, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'building_ownership')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('building_ownership', 'Who Owns The Building', 'Estate',
        'The owner, which decides whether an agreement is needed at all.',
        'Buildings and rooms', 160);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'building_ownership')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('building_ownership', 'UI',         'University of Illinois',      1, 1, 1),
 ('building_ownership', 'Private',    'Private',                     2, 1, 1),
 ('building_ownership', 'State',      'State of Illinois',           3, 1, 1),
 ('building_ownership', 'State-IDNR', 'State - IDNR',                4, 1, 1),
 ('building_ownership', 'Federal',    'Federal',                     5, 1, 1),
 ('building_ownership', 'Local',      'Local government',            6, 1, 1),
 ('building_ownership', 'SIU',        'Southern Illinois University', 7, 1, 1),
 ('building_ownership', 'Unknown',    'Not known',                   8, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'building_type')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('building_type', 'What The Building Is For', 'Estate', 'Its role in the estate.',
        'Buildings and rooms', 170);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'building_type')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('building_type', 'Primary office',    'Primary office',   1, 1, 1),
 ('building_type', 'Field laboratory',  'Field laboratory', 2, 1, 1),
 ('building_type', 'Warehouse',         'Warehouse',        3, 1, 1),
 ('building_type', 'Seasonal use',      'Seasonal use',     4, 1, 1),
 ('building_type', 'Storage',           'Storage',          5, 1, 1),
 ('building_type', 'Other',             'Something else',   6, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM app.NotifyRules WHERE EventCode = 'agreement_due')
INSERT INTO app.NotifyRules (EventCode, MatchType, Email, DisplayName, Note, IsActive)
VALUES ('agreement_due', 'always', 'configure-me@example.edu', 'Facilities Office',
        'Told when a lease, sponsor arrangement or memorandum is coming up for decision.', 1);
GO
