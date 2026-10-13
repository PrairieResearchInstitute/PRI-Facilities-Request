-- ============================================================
-- PRI Facilities Request System
-- 26_unit_contacts.sql
--   Each unit has more than a director and an assistant. INHS has Rachel
--   Vinsel for space; the other surveys have their own, and every unit
--   has somebody for safety. Those people are who a person asking about
--   space or reporting a hazard should actually reach, and there was
--   nowhere to record them.
--
--   The director and the administrative assistant stay on ref.Surveys.
--   Everything routes on those two and moving them would touch every
--   notification path for no gain; this table holds the roles beside
--   them.
-- Work database, replicated by the reference publish.
-- ============================================================

IF OBJECT_ID('ref.UnitContacts', 'U') IS NULL
EXEC('
CREATE TABLE ref.UnitContacts (
    UnitContactID INT IDENTITY(1,1) PRIMARY KEY,
    UnitCode    NVARCHAR(20)  NOT NULL,   -- INHS, ISAS, ISGS, ISTC, ISWS, OED
    RoleKey     NVARCHAR(40)  NOT NULL,   -- space, safety, facilities, ...
    PersonName  NVARCHAR(160) NULL,
    NetID       NVARCHAR(64)  NULL,
    Email       NVARCHAR(200) NULL,
    Phone       NVARCHAR(60)  NULL,
    Note        NVARCHAR(300) NULL,
    IsActive    BIT NOT NULL DEFAULT 1,
    UpdatedAt   DATETIME2 NULL,
    UpdatedByNetID NVARCHAR(64) NULL,
    CONSTRAINT UQ_UnitContact UNIQUE (UnitCode, RoleKey)
);
CREATE INDEX IX_UnitContacts_Role ON ref.UnitContacts(RoleKey, UnitCode);');
GO

-- The roles themselves are an editable list, so a unit can be given a
-- contact for something nobody has thought of yet.
MERGE ref.PickListTypes AS t
USING (VALUES ('unit_contact_role', 'People', 'Unit contact roles',
    'Who to go to in each survey or unit for a particular subject. The director and the administrative assistant are set separately, on the notifications screen.',
    'Directors and contacts, Space request, Safety', 5)
) AS s (ListKey, GroupName, Title, Description, UsedOn, SortOrder)
ON t.ListKey = s.ListKey
WHEN MATCHED THEN UPDATE SET t.GroupName = s.GroupName, t.Title = s.Title,
    t.Description = s.Description, t.UsedOn = s.UsedOn, t.SortOrder = s.SortOrder
WHEN NOT MATCHED THEN INSERT (ListKey, GroupName, Title, Description, UsedOn, SortOrder)
    VALUES (s.ListKey, s.GroupName, s.Title, s.Description, s.UsedOn, s.SortOrder);
GO

MERGE ref.PickLists AS t
USING (VALUES
 ('unit_contact_role', 'space',      'Space - who to ask about rooms and space requests', 10, 1),
 ('unit_contact_role', 'safety',     'Safety - the unit safety officer or coordinator', 20, 1),
 ('unit_contact_role', 'facilities', 'Facilities - building problems for this unit', 30, 0),
 ('unit_contact_role', 'property',   'Property and equipment', 40, 0),
 ('unit_contact_role', 'fleet',      'Vehicles', 50, 0),
 ('unit_contact_role', 'keys',       'Keys and access', 60, 0),
 ('unit_contact_role', 'finance',    'Accounts and charging', 70, 0),
 ('unit_contact_role', 'collections','Collections and specimens', 80, 0)
) AS s (ListKey, Code, Label, SortOrder, IsProtected)
ON t.ListKey = s.ListKey AND t.Code = s.Code
WHEN NOT MATCHED THEN
    INSERT (ListKey, Code, Label, SortOrder, IsActive, IsProtected, UpdatedAt, UpdatedByNetID)
    VALUES (s.ListKey, s.Code, s.Label, s.SortOrder, 1, s.IsProtected, SYSDATETIME(), 'seed');
GO

-- The one we know. The rest are left empty on purpose: an invented name
-- is worse than a blank, because a blank asks to be filled.
MERGE ref.UnitContacts AS t
USING (VALUES
    ('INHS', 'space', 'Vinsel, Rachel', 'rvinsel2', 'configure-me@example.edu',
     'Questions about the INHS space request process')
) AS s (UnitCode, RoleKey, PersonName, NetID, Email, Note)
ON t.UnitCode = s.UnitCode AND t.RoleKey = s.RoleKey
WHEN MATCHED THEN UPDATE SET t.PersonName = s.PersonName, t.NetID = s.NetID,
    t.Email = s.Email, t.Note = s.Note, t.IsActive = 1
WHEN NOT MATCHED THEN
    INSERT (UnitCode, RoleKey, PersonName, NetID, Email, Note, IsActive, UpdatedAt, UpdatedByNetID)
    VALUES (s.UnitCode, s.RoleKey, s.PersonName, s.NetID, s.Email, s.Note, 1,
            SYSDATETIME(), 'seed');
GO
