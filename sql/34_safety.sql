/* ---------------------------------------------------------------------
   Safety, as two databases.

   An incident and a plan are not the same kind of record and are not
   asked for together. An incident is an event: it happened, somebody was
   there, something was done about it, and the report may name a person
   and describe an injury. A plan is a document: it has an owner, an
   effective date, a review date, and the rooms it covers.

   They are kept apart so each can be handed over, retained and read on
   its own terms.

   Neither holds a foreign key into another database. An incident names a
   room by its room code, a person by their NetID, and a vehicle by its
   fleet number - the same natural keys everything else here joins on.
   --------------------------------------------------------------------- */

USE PRI_FacilitiesIncidents;
GO

IF OBJECT_ID('rq.Incidents') IS NULL
CREATE TABLE rq.Incidents (
    IncidentID        INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber      NVARCHAR(30)  NOT NULL UNIQUE,

    /* What happened, and how bad. Both come from editable lists so the
       safety office can change the words without a developer. */
    IncidentKind      NVARCHAR(30)  NOT NULL,
    Severity          NVARCHAR(20)  NULL,

    OccurredAt        DATETIME2     NOT NULL,
    ReportedAt        DATETIME2     NOT NULL DEFAULT SYSDATETIME(),

    /* Where. A room code when it was indoors and the room is known; a
       written place when it was a field site, a car park, or a road. */
    BldgNumber        NVARCHAR(10)  NULL,
    RoomID            NVARCHAR(30)  NULL,
    RoomCode          NVARCHAR(40)  NULL,
    WrittenLocation   NVARCHAR(300) NULL,
    IsOffCampus       BIT           NOT NULL DEFAULT 0,

    /* When a vehicle was involved. Named by fleet number, because that
       is what the fleet database calls it. */
    FleetNumber       NVARCHAR(30)  NULL,

    SurveyCode        NVARCHAR(10)  NULL,
    ReportedByNetID   NVARCHAR(50)  NOT NULL,
    ReportedByName    NVARCHAR(150) NULL,
    ReportedByUserID  INT NULL,

    Description       NVARCHAR(MAX) NOT NULL,
    ImmediateAction   NVARCHAR(MAX) NULL,
    ContributingCause NVARCHAR(MAX) NULL,

    /* Whether anybody outside PRI has to be told, and whether they were.
       This is the question that gets asked first and answered last. */
    IsReportable      BIT           NOT NULL DEFAULT 0,
    ReportedTo        NVARCHAR(200) NULL,
    ReportedOutwardOn DATE          NULL,

    Status            NVARCHAR(20)  NOT NULL DEFAULT 'reported',
    ClosedAt          DATETIME2 NULL,
    ClosedByNetID     NVARCHAR(50)  NULL,
    Findings          NVARCHAR(MAX) NULL,

    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),

    CONSTRAINT CK_Incidents_Status CHECK (Status IN
        ('reported', 'investigating', 'actions_open', 'closed'))
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Incidents_When')
CREATE INDEX IX_Incidents_When ON rq.Incidents (OccurredAt DESC);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Incidents_Where')
CREATE INDEX IX_Incidents_Where ON rq.Incidents (RoomCode);
GO

/* Who was there. Separated from the report because one event can involve
   several people, and because an injury description belongs to a person
   rather than to an event. */
IF OBJECT_ID('rq.IncidentPeople') IS NULL
CREATE TABLE rq.IncidentPeople (
    IncidentPersonID  INT IDENTITY(1,1) PRIMARY KEY,
    IncidentID        INT NOT NULL,
    PersonName        NVARCHAR(150) NOT NULL,
    NetID             NVARCHAR(50)  NULL,
    Involvement       NVARCHAR(30)  NOT NULL DEFAULT 'involved',
    IsPriStaff        BIT           NOT NULL DEFAULT 1,

    InjuryDescription NVARCHAR(1000) NULL,
    BodyPart          NVARCHAR(100) NULL,
    TreatmentLevel    NVARCHAR(30)  NULL,
    TreatedAt         NVARCHAR(200) NULL,
    LostTimeDays      DECIMAL(6,1)  NULL,
    ReturnedOn        DATE          NULL,

    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_IncidentPeople_Incident FOREIGN KEY (IncidentID)
        REFERENCES rq.Incidents (IncidentID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IncidentPeople_Incident')
CREATE INDEX IX_IncidentPeople_Incident ON rq.IncidentPeople (IncidentID);
GO

/* What is being done so it does not happen again. An incident with no
   action and no reason recorded is a filing exercise. */
IF OBJECT_ID('rq.IncidentActions') IS NULL
CREATE TABLE rq.IncidentActions (
    IncidentActionID  INT IDENTITY(1,1) PRIMARY KEY,
    IncidentID        INT NOT NULL,
    Action            NVARCHAR(1000) NOT NULL,
    OwnerName         NVARCHAR(150) NULL,
    OwnerNetID        NVARCHAR(50)  NULL,
    DueOn             DATE NULL,
    DoneOn            DATE NULL,
    DoneNote          NVARCHAR(1000) NULL,
    /* When the fix is a work order, the ticket number ties them. */
    WorkTicketNumber  NVARCHAR(30)  NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_IncidentActions_Incident FOREIGN KEY (IncidentID)
        REFERENCES rq.Incidents (IncidentID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IncidentActions_Incident')
CREATE INDEX IX_IncidentActions_Incident ON rq.IncidentActions (IncidentID, DoneOn);
GO

IF OBJECT_ID('rq.IncidentEvents') IS NULL
CREATE TABLE rq.IncidentEvents (
    IncidentEventID   INT IDENTITY(1,1) PRIMARY KEY,
    IncidentID        INT NOT NULL,
    EventCode         NVARCHAR(30)  NOT NULL,
    Detail            NVARCHAR(1000) NULL,
    ByNetID           NVARCHAR(50)  NULL,
    ByName            NVARCHAR(150) NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_IncidentEvents_Incident FOREIGN KEY (IncidentID)
        REFERENCES rq.Incidents (IncidentID)
);
GO

USE PRI_FacilitiesPlans;
GO

/* The kinds of plan, and how often each must be looked at again. */
IF OBJECT_ID('ref.PlanTypes') IS NULL
CREATE TABLE ref.PlanTypes (
    PlanTypeCode      NVARCHAR(30)  NOT NULL PRIMARY KEY,
    Title             NVARCHAR(150) NOT NULL,
    ReviewMonths      INT           NOT NULL DEFAULT 12,
    AppliesTo         NVARCHAR(200) NULL,
    Description       NVARCHAR(1000) NULL,
    SortOrder         INT           NOT NULL DEFAULT 100,
    IsActive          BIT           NOT NULL DEFAULT 1
);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PlanTypes)
INSERT INTO ref.PlanTypes (PlanTypeCode, Title, ReviewMonths, AppliesTo, Description, SortOrder)
VALUES
 ('chp', 'Chemical hygiene plan', 12, 'Laboratories using hazardous chemicals',
  'Required wherever hazardous chemicals are used. Reviewed every year.', 10),
 ('lab', 'Laboratory safety plan', 12, 'Laboratories',
  'How a particular laboratory works safely: its hazards, its rules, its people.', 20),
 ('field', 'Field safety plan', 12, 'Field stations and field work',
  'For work away from campus: the site, the hazards, who to call.', 30),
 ('beap', 'Building emergency action plan', 24, 'Buildings',
  'Evacuation, shelter, assembly points and floor wardens for one building.', 40),
 ('sop', 'Standard operating procedure', 24, 'A task or a piece of equipment',
  'One procedure, written down, for something that has to be done the same way.', 50);
GO

IF OBJECT_ID('rq.SafetyPlans') IS NULL
CREATE TABLE rq.SafetyPlans (
    SafetyPlanID      INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber      NVARCHAR(30)  NOT NULL UNIQUE,
    PlanTypeCode      NVARCHAR(30)  NOT NULL,
    Title             NVARCHAR(300) NOT NULL,
    Reference         NVARCHAR(60)  NULL,
    Version           NVARCHAR(30)  NULL,

    /* Every plan has one person who answers for it. A plan without an
       owner is a plan nobody reviews. */
    OwnerName         NVARCHAR(150) NOT NULL,
    OwnerNetID        NVARCHAR(50)  NULL,
    SurveyCode        NVARCHAR(10)  NULL,
    UnitName          NVARCHAR(150) NULL,

    EffectiveOn       DATE          NULL,
    ReviewDueOn       DATE          NULL,
    LastReviewedOn    DATE          NULL,

    Summary           NVARCHAR(MAX) NULL,
    Status            NVARCHAR(20)  NOT NULL DEFAULT 'current',

    FiledByNetID      NVARCHAR(50)  NOT NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),

    CONSTRAINT CK_SafetyPlans_Status CHECK (Status IN
        ('draft', 'current', 'due', 'withdrawn')),
    CONSTRAINT FK_SafetyPlans_Type FOREIGN KEY (PlanTypeCode)
        REFERENCES ref.PlanTypes (PlanTypeCode)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SafetyPlans_Due')
CREATE INDEX IX_SafetyPlans_Due ON rq.SafetyPlans (ReviewDueOn);
GO

/* Which rooms a plan covers, so a room can answer "what plan applies to
   me" and a plan can answer "where does this apply". Room codes, because
   the rooms are in another database. */
IF OBJECT_ID('rq.PlanRooms') IS NULL
CREATE TABLE rq.PlanRooms (
    PlanRoomID        INT IDENTITY(1,1) PRIMARY KEY,
    SafetyPlanID      INT NOT NULL,
    RoomCode          NVARCHAR(40)  NULL,
    BldgNumber        NVARCHAR(10)  NULL,
    CoversWholeBuilding BIT         NOT NULL DEFAULT 0,
    Note              NVARCHAR(300) NULL,
    CONSTRAINT FK_PlanRooms_Plan FOREIGN KEY (SafetyPlanID)
        REFERENCES rq.SafetyPlans (SafetyPlanID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_PlanRooms_Room')
CREATE INDEX IX_PlanRooms_Room ON rq.PlanRooms (RoomCode);
GO

/* Every time somebody looked at it and said it still stands. */
IF OBJECT_ID('rq.PlanReviews') IS NULL
CREATE TABLE rq.PlanReviews (
    PlanReviewID      INT IDENTITY(1,1) PRIMARY KEY,
    SafetyPlanID      INT NOT NULL,
    ReviewedOn        DATE          NOT NULL,
    ReviewedByNetID   NVARCHAR(50)  NULL,
    ReviewedByName    NVARCHAR(150) NULL,
    Outcome           NVARCHAR(30)  NOT NULL DEFAULT 'unchanged',
    Note              NVARCHAR(1000) NULL,
    NextDueOn         DATE          NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_PlanReviews_Plan FOREIGN KEY (SafetyPlanID)
        REFERENCES rq.SafetyPlans (SafetyPlanID)
);
GO

/* --- the words the forms offer, and the ticket series ---------------- */
USE PRI_FacilitiesWork;
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_inc')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('ticket_prefix_inc', 'INC', 'text', 'Incident ticket prefix',
        'The letters in front of an incident report, as INC-FY27-00001.', 400);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_pln')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('ticket_prefix_pln', 'PLN', 'text', 'Safety plan reference prefix',
        'The letters in front of a safety plan, as PLN-FY27-00001.', 410);
GO
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'plan_warn_days')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('plan_warn_days', '60', 'int', 'Warn this many days before a plan is due for review',
        'A plan falling due inside this window appears on the safety office list while there '
        + 'is still time to review it.', 420);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'incident_kind')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('incident_kind', 'Kinds Of Incident', 'Safety', 'What happened.',
        'Incident report form', 100);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'incident_kind')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('incident_kind', 'injury',     'Injury',                            1, 1, 1),
 ('incident_kind', 'near_miss',  'Near miss - nobody was hurt',       2, 1, 1),
 ('incident_kind', 'exposure',   'Exposure to a chemical or biological agent', 3, 1, 1),
 ('incident_kind', 'spill',      'Spill or release',                  4, 1, 1),
 ('incident_kind', 'fire',       'Fire or alarm',                     5, 1, 1),
 ('incident_kind', 'vehicle',    'Vehicle accident',                  6, 1, 1),
 ('incident_kind', 'damage',     'Damage to property, nobody hurt',   7, 1, 1),
 ('incident_kind', 'other',      'Something else',                    8, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'incident_severity')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('incident_severity', 'How Serious An Incident Was', 'Safety',
        'Judged by the safety office, not by the person reporting.',
        'Incident report review', 110);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'incident_severity')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('incident_severity', 'minor',    'Minor',                    1, 1, 1),
 ('incident_severity', 'moderate', 'Moderate',                 2, 1, 1),
 ('incident_severity', 'serious',  'Serious',                  3, 1, 1),
 ('incident_severity', 'critical', 'Critical or life changing', 4, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'incident_treatment')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('incident_treatment', 'Treatment Given', 'Safety',
        'How far the medical response went.', 'Incident report form', 120);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'incident_treatment')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('incident_treatment', 'none',      'None needed',                 1, 1, 1),
 ('incident_treatment', 'first_aid', 'First aid on site',           2, 1, 1),
 ('incident_treatment', 'clinic',    'Seen at a clinic',            3, 1, 1),
 ('incident_treatment', 'emergency', 'Emergency department',        4, 1, 1),
 ('incident_treatment', 'admitted',  'Admitted to hospital',        5, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'incident_involvement')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('incident_involvement', 'How Somebody Was Involved', 'Safety',
        'Whether they were hurt, saw it, or helped.', 'Incident report form', 130);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'incident_involvement')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('incident_involvement', 'injured',   'Hurt',            1, 1, 1),
 ('incident_involvement', 'involved',  'Involved',        2, 1, 1),
 ('incident_involvement', 'witness',   'Saw it happen',   3, 1, 1),
 ('incident_involvement', 'responder', 'Gave first aid',  4, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'plan_review_outcome')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('plan_review_outcome', 'What A Plan Review Concluded', 'Safety',
        'What came of looking at a plan again.', 'Safety plan repository', 140);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'plan_review_outcome')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('plan_review_outcome', 'unchanged', 'Still stands, unchanged',   1, 1, 1),
 ('plan_review_outcome', 'revised',   'Revised',                   2, 1, 1),
 ('plan_review_outcome', 'replaced',  'Replaced by a new plan',    3, 1, 1),
 ('plan_review_outcome', 'withdrawn', 'Withdrawn, no longer needed', 4, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM app.NotifyRules WHERE EventCode = 'incident_reported')
INSERT INTO app.NotifyRules (EventCode, MatchType, Email, DisplayName, Note, IsActive)
VALUES ('incident_reported', 'always', 'configure-me@example.edu', 'Safety Office',
        'Told the moment an incident or near miss is reported.', 1);
GO
IF NOT EXISTS (SELECT 1 FROM app.NotifyRules WHERE EventCode = 'plan_review_due')
INSERT INTO app.NotifyRules (EventCode, MatchType, Email, DisplayName, Note, IsActive)
VALUES ('plan_review_due', 'always', 'configure-me@example.edu', 'Safety Office',
        'Told when a safety plan is coming up for review.', 1);
GO
