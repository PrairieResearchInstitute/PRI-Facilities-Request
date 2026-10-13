-- ============================================================
-- PRI Facilities Request System
-- 24_picklists.sql
--   Every short list a form offers, in one table that an administrator
--   can edit, instead of scattered through the code as constants.
--
--   These are the lists nobody can predict: the kinds of key PRI issues,
--   the ways a piece of property leaves, the services a vehicle needs.
--   They change with practice, and changing them should not need a
--   developer.
--
--   Longer reference data stays where it is - buildings, rooms, accounts,
--   people, work types and natures all have their own tables with their
--   own screens, because they carry more than a code and a label.
-- Work database, replicated by the reference publish.
-- ============================================================

IF OBJECT_ID('ref.PickLists', 'U') IS NULL
EXEC('
CREATE TABLE ref.PickLists (
    PickListID  INT IDENTITY(1,1) PRIMARY KEY,
    ListKey     NVARCHAR(40)  NOT NULL,   -- key_type, removal_reason, ...
    Code        NVARCHAR(60)  NOT NULL,   -- what is stored on the record
    Label       NVARCHAR(160) NOT NULL,   -- what the person reads
    SortOrder   INT NOT NULL DEFAULT 100,
    IsActive    BIT NOT NULL DEFAULT 1,
    Note        NVARCHAR(300) NULL,       -- why this entry exists
    IsProtected BIT NOT NULL DEFAULT 0,   -- the code is wired into logic
    UpdatedAt   DATETIME2 NULL,
    UpdatedByNetID NVARCHAR(64) NULL,
    CONSTRAINT UQ_PickList UNIQUE (ListKey, Code)
);
CREATE INDEX IX_PickLists_List ON ref.PickLists(ListKey, SortOrder);');
GO

-- One row per list, describing what it is for, so the editing screen can
-- explain itself rather than showing bare keys.
IF OBJECT_ID('ref.PickListTypes', 'U') IS NULL
EXEC('
CREATE TABLE ref.PickListTypes (
    ListKey     NVARCHAR(40)  NOT NULL PRIMARY KEY,
    GroupName   NVARCHAR(60)  NOT NULL,
    Title       NVARCHAR(120) NOT NULL,
    Description NVARCHAR(400) NULL,
    UsedOn      NVARCHAR(300) NULL,       -- which screens read it
    SortOrder   INT NOT NULL DEFAULT 100
);');
GO

MERGE ref.PickListTypes AS t
USING (VALUES
 ('key_type',        'Keys and access', 'Kinds of key',
  'What can be issued to a person: a cut key, a card credential, a padlock.',
  'Key request, Key assignment', 10),
 ('key_state',       'Keys and access', 'States a key can be in',
  'Where a key stands. Active and Returned are wired into the register and cannot be renamed.',
  'Key assignment, Key register', 20),
 ('emp_status',      'Keys and access', 'Employment status',
  'Used when issuing a key, and to find keys still out with people who have left. Terminated is wired into that check.',
  'Key request, Key assignment, Data audit', 30),
 ('access_function', 'Keys and access', 'Back end access functions',
  'What somebody can see and decide on the administration side.',
  'Back end access', 40),
 ('space_kind',      'Space',           'Kinds of space request',
  'What the person is asking for: more space, a change of use, a relocation, a release.',
  'Space request', 10),
 ('space_type',      'Space',           'Types of space',
  'The kind of room being asked for, across the whole institute rather than one survey.',
  'Space request', 20),
 ('room_access',     'Space',           'Room access restrictions',
  'How a room is controlled: open, card only, escorted, restricted.',
  'Room assignment', 30),
 ('removal_reason',  'Property',        'Ways property leaves',
  'How an item stops being ours: surplus, trashed, lost, transferred off unit.',
  'Equipment', 10),
 ('equip_condition', 'Property',        'Equipment condition',
  'The state an item is in, for replacement planning.',
  'Equipment', 20),
 ('equip_class',     'Property',        'Equipment classes',
  'Broad classes of tagged property, for reporting and for deciding what needs calibration.',
  'Equipment', 30),
 ('vehicle_class',   'Vehicles',        'Vehicle classes',
  'What kind of thing it is: a truck, a trailer, a boat, an ATV. Field stations hold more than cars.',
  'Fleet vehicles', 10),
 ('vehicle_service', 'Vehicles',        'Vehicle service types',
  'What is being asked for on a vehicle. This is the list the shop request shows once Vehicle is chosen.',
  'Shop request (vehicle), Fleet vehicles', 20),
 ('vehicle_urgency', 'Vehicles',        'Vehicle availability',
  'Whether the vehicle can still be driven, which decides how fast the shop has to act.',
  'Shop request (vehicle)', 30),
 ('shop_kind',       'Shop',            'Kinds of shop request',
  'The two very different things the shop is asked for: work on a building or object, and work on a vehicle.',
  'Shop request', 10),
 ('shop_service',    'Shop',            'Shop work types',
  'What is being asked of the shop when it is not a vehicle: fabrication, repair, modification, installation.',
  'Shop request (shop work)', 20),
 ('safety_kind',     'Safety',          'Kinds of safety equipment',
  'What the safety register holds: eyewashes, showers, fume hoods, extinguishers, AEDs, spill kits.',
  'Safety manifest, Room detail', 10),
 ('safety_hazard',   'Safety',          'Room hazards',
  'What is present in a room. The hazard profile decides what equipment the room must carry and what goes on its door card.',
  'Room hazard profile', 20),
 ('safety_plan_type','Safety',          'Types of safety plan',
  'The documents held in the plan repository.',
  'Safety plan repository', 30),
 ('incident_type',   'Safety',          'Kinds of incident',
  'What happened: injury, spill, exposure, fire, near miss.',
  'Incident report', 40)
) AS s (ListKey, GroupName, Title, Description, UsedOn, SortOrder)
ON t.ListKey = s.ListKey
WHEN MATCHED THEN UPDATE SET t.GroupName = s.GroupName, t.Title = s.Title,
    t.Description = s.Description, t.UsedOn = s.UsedOn, t.SortOrder = s.SortOrder
WHEN NOT MATCHED THEN INSERT (ListKey, GroupName, Title, Description, UsedOn, SortOrder)
    VALUES (s.ListKey, s.GroupName, s.Title, s.Description, s.UsedOn, s.SortOrder);
GO

-- The entries themselves, seeded from what the code used to hold as
-- constants. IsProtected marks a code that logic keys on: the label can
-- still be reworded, but the code must not change.
MERGE ref.PickLists AS t
USING (VALUES
 ('key_type','Key','Key',10,0), ('key_type','iCard','iCard',20,0),
 ('key_type','iCard (UIUC F&S system)','iCard (UIUC F&S system)',30,0),
 ('key_type','Padlock','Padlock',40,0), ('key_type','Cabinet key','Cabinet key',50,0),
 ('key_type','Swipe Card','Swipe card or fob',60,0), ('key_type','Other','Other',99,0),

 ('key_state','Active','Active',10,1), ('key_state','Returned','Returned',20,1),
 ('key_state','Lost','Lost',30,1), ('key_state','Void','Void',40,1),

 ('emp_status','Active','Active',10,1), ('emp_status','Terminated','Terminated',20,1),
 ('emp_status','Retired','Retired',30,0), ('emp_status','Student','Student',40,0),
 ('emp_status','Visitor','Visitor',50,0), ('emp_status','Affiliate','Affiliate',60,0),

 ('access_function','director','Director',10,1), ('access_function','proxy','Director''s proxy',20,1),
 ('access_function','facilities','Facilities staff',30,1), ('access_function','safety','Safety staff',40,1),
 ('access_function','fleet','Fleet and vehicles',50,1), ('access_function','equipment','Equipment and property',60,1),
 ('access_function','inventory','Inventory staff',70,1), ('access_function','finance','Finance staff',80,1),
 ('access_function','it','IT staff',90,1), ('access_function','deputy','Deputy director',95,1),
 ('access_function','executive','Executive director',96,1),

 ('space_kind','additional','Additional space',10,1),
 ('space_kind','change_use','Change how a room is used',20,1),
 ('space_kind','relocate','Relocate to different space',30,1),
 ('space_kind','release','Release space we no longer need',40,1),
 ('space_kind','shared','Share space with another unit',50,1),

 ('space_type','office','Office or workstation',10,0),
 ('space_type','lab','Laboratory: wet, analytical, or instrument bench',20,0),
 ('space_type','collections','Collections, curation, or core and specimen storage',30,0),
 ('space_type','storage','General storage, including large equipment',40,0),
 ('space_type','instrument','Dedicated instrument or equipment room',50,0),
 ('space_type','processing','Field, sample, or artifact processing space',60,0),
 ('space_type','shop','Shop, vehicle bay, or fabrication space',70,0),
 ('space_type','growing','Greenhouse, growth chamber, or outdoor research plot',80,0),
 ('space_type','meeting','Meeting, conference, or teaching space',90,0),
 ('space_type','other','Other',99,1),

 ('room_access','open','Open access',10,0),
 ('room_access','card','Card credential only',20,0),
 ('room_access','key','Keyed, issued individually',30,0),
 ('room_access','escort','Escorted access only',40,0),
 ('room_access','restricted','Restricted - approval required',50,0),

 ('removal_reason','surplus','Surplus',10,1), ('removal_reason','trashed','Trashed',20,1),
 ('removal_reason','lost','Lost or stolen',30,1),
 ('removal_reason','transferred','Transferred off unit',40,1),
 ('removal_reason','traded','Traded in',50,0),
 ('removal_reason','cannibalised','Stripped for parts',60,0),

 ('equip_condition','new','New',10,0), ('equip_condition','good','Good',20,0),
 ('equip_condition','fair','Fair - serviceable',30,0),
 ('equip_condition','poor','Poor - due for replacement',40,0),
 ('equip_condition','unserviceable','Unserviceable',50,0),

 ('equip_class','instrument','Laboratory instrument',10,0),
 ('equip_class','computer','Computer or IT equipment',20,0),
 ('equip_class','field','Field equipment',30,0),
 ('equip_class','vehicle','Vehicle, trailer or boat',40,0),
 ('equip_class','furniture','Furniture and fittings',50,0),
 ('equip_class','shop','Shop and workshop tools',60,0),
 ('equip_class','av','Audio visual',70,0),
 ('equip_class','other','Other',99,0),

 ('vehicle_class','car','Car',10,0), ('vehicle_class','truck','Truck or pickup',20,0),
 ('vehicle_class','van','Van',30,0), ('vehicle_class','suv','SUV',40,0),
 ('vehicle_class','trailer','Trailer',50,0), ('vehicle_class','boat','Boat',60,0),
 ('vehicle_class','atv','ATV or UTV',70,0), ('vehicle_class','tractor','Tractor or mower',80,0),
 ('vehicle_class','other','Other',99,0),

 ('vehicle_service','oil','Oil and filter change',10,0),
 ('vehicle_service','inspection','Safety inspection',20,0),
 ('vehicle_service','tires','Tyres - rotation, repair or replacement',30,0),
 ('vehicle_service','brakes','Brakes',40,0),
 ('vehicle_service','battery','Battery or electrical',50,0),
 ('vehicle_service','engine','Engine or transmission',60,0),
 ('vehicle_service','hvac','Heating or air conditioning',70,0),
 ('vehicle_service','body','Body, glass or damage repair',80,0),
 ('vehicle_service','trailer','Trailer, hitch or lights',85,0),
 ('vehicle_service','diagnose','Something is wrong - please diagnose',90,0),
 ('vehicle_service','scheduled','Scheduled service due',95,0),
 ('vehicle_service','other','Other',99,0),

 ('vehicle_urgency','driveable','Driveable - can wait for a slot',10,0),
 ('vehicle_urgency','limited','Driveable but should not go far',20,0),
 ('vehicle_urgency','down','Off the road - cannot be driven',30,0),
 ('vehicle_urgency','stranded','Stranded away from base',40,0),

 ('shop_kind','shop','Shop work - build, modify or repair something',10,1),
 ('shop_kind','vehicle','Vehicle - service, repair or inspection',20,1),

 ('shop_service','fabricate','Build something new',10,0),
 ('shop_service','modify','Modify something existing',20,0),
 ('shop_service','repair','Repair something broken',30,0),
 ('shop_service','install','Install or mount',40,0),
 ('shop_service','maintain','Scheduled maintenance',50,0),
 ('shop_service','other','Other',99,0),

 ('safety_kind','eyewash','Eyewash station',10,0),
 ('safety_kind','shower','Emergency shower',20,0),
 ('safety_kind','combo','Combination shower and eyewash',25,0),
 ('safety_kind','hood','Fume hood',30,0),
 ('safety_kind','extinguisher','Fire extinguisher',40,0),
 ('safety_kind','aed','AED',50,0),
 ('safety_kind','firstaid','First aid kit',60,0),
 ('safety_kind','spillkit','Spill kit',70,0),
 ('safety_kind','bsc','Biosafety cabinet',80,0),
 ('safety_kind','gasmonitor','Gas or oxygen monitor',85,0),
 ('safety_kind','alarm','Fire alarm pull station',90,0),
 ('safety_kind','shutoff','Emergency shut off',95,0),
 ('safety_kind','other','Other',99,0),

 ('safety_hazard','chemical','Chemicals',10,0),
 ('safety_hazard','flammable','Flammables',20,0),
 ('safety_hazard','compressed','Compressed gas',30,0),
 ('safety_hazard','cryogen','Cryogens',40,0),
 ('safety_hazard','biological','Biological material',50,0),
 ('safety_hazard','radiation','Radiation',60,0),
 ('safety_hazard','laser','Lasers',70,0),
 ('safety_hazard','electrical','High voltage',80,0),
 ('safety_hazard','machine','Machinery or moving parts',85,0),
 ('safety_hazard','noise','Noise',90,0),
 ('safety_hazard','none','No particular hazard',99,0),

 ('safety_plan_type','chp','Chemical hygiene plan',10,0),
 ('safety_plan_type','lab','Laboratory safety plan',20,0),
 ('safety_plan_type','field','Field safety plan',30,0),
 ('safety_plan_type','building','Building emergency action plan',40,0),
 ('safety_plan_type','sop','Standard operating procedure',50,0),
 ('safety_plan_type','jha','Job hazard analysis',60,0),
 ('safety_plan_type','boat','Boat or watercraft plan',70,0),

 ('incident_type','injury','Injury',10,0), ('incident_type','spill','Spill or release',20,0),
 ('incident_type','exposure','Exposure',30,0), ('incident_type','fire','Fire or alarm',40,0),
 ('incident_type','property','Property damage',50,0),
 ('incident_type','nearmiss','Near miss - nobody hurt',60,0),
 ('incident_type','vehicle','Vehicle accident',70,0)
) AS s (ListKey, Code, Label, SortOrder, IsProtected)
ON t.ListKey = s.ListKey AND t.Code = s.Code
WHEN NOT MATCHED THEN
    INSERT (ListKey, Code, Label, SortOrder, IsActive, IsProtected, UpdatedAt, UpdatedByNetID)
    VALUES (s.ListKey, s.Code, s.Label, s.SortOrder, 1, s.IsProtected, SYSDATETIME(), 'seed');
GO
