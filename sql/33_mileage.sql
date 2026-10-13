/* ---------------------------------------------------------------------
   Odometer readings.

   Everything anybody wants to know about a fleet - service due, cost per
   mile, which vehicles are worked and which sit - is built from a dated
   number and nothing else. The number is easy to collect and nobody
   collects it, because collecting it means finding the right vehicle in a
   list of three hundred and twenty six on a phone, in a car park.

   So the vehicle identifies itself: a sticker in the cab, scanned with
   the camera, opens this vehicle's page. What is left is one number and a
   photograph of the dash, which is the evidence that the number is real.
   --------------------------------------------------------------------- */

USE PRI_FacilitiesShop;
GO

IF OBJECT_ID('sh.VehicleReadings') IS NULL
CREATE TABLE sh.VehicleReadings (
    ReadingID       INT IDENTITY(1,1) PRIMARY KEY,
    FleetNumber     NVARCHAR(30)  NOT NULL,
    Miles           INT           NOT NULL,
    ReadOn          DATE          NOT NULL,
    ReadByNetID     NVARCHAR(50)  NOT NULL,
    ReadByName      NVARCHAR(150) NULL,
    SurveyCode      NVARCHAR(10)  NULL,

    /* How it arrived, so the value of the sticker can be measured rather
       than assumed: qr, manual, booking, shop, import. */
    Source          NVARCHAR(20)  NOT NULL DEFAULT 'manual',

    /* Whether the number was typed or accepted from the photograph. Kept
       because a suggestion that is usually wrong should be switched off,
       and there is no way to know without counting. */
    WasSuggested    BIT           NOT NULL DEFAULT 0,
    SuggestedMiles  INT           NULL,

    PhotoAttachmentID INT NULL,
    Note            NVARCHAR(500) NULL,

    /* What the reading looked like against the one before it. Written at
       the time, because "is 4,000 miles in a week plausible for this
       vehicle" is a question about that week, not about today. */
    MilesSinceLast  INT NULL,
    DaysSinceLast   INT NULL,
    IsQueried       BIT           NOT NULL DEFAULT 0,
    QueryNote       NVARCHAR(300) NULL,

    CreatedAt       DATETIME2     NOT NULL DEFAULT SYSDATETIME(),

    CONSTRAINT CK_VehicleReadings_Source CHECK (Source IN
        ('qr', 'manual', 'booking', 'shop', 'import')),
    CONSTRAINT CK_VehicleReadings_Miles CHECK (Miles >= 0)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_VehicleReadings_Vehicle')
CREATE INDEX IX_VehicleReadings_Vehicle
    ON sh.VehicleReadings (FleetNumber, ReadOn DESC, ReadingID DESC);
GO

/* The vehicle carries its own last reading so a list of three hundred
   vehicles is one query rather than three hundred. */
IF COL_LENGTH('ref.FleetVehicles', 'LastMiles') IS NULL
    ALTER TABLE ref.FleetVehicles ADD LastMiles INT NULL;
GO
IF COL_LENGTH('ref.FleetVehicles', 'LastMilesOn') IS NULL
    ALTER TABLE ref.FleetVehicles ADD LastMilesOn DATE NULL;
GO

USE PRI_FacilitiesWork;
GO

/* What counts as a reading worth querying. Both are settings because the
   right numbers differ between a pool car and a field station truck, and
   whoever runs the fleet will want to move them. */
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'mileage_max_per_day')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('mileage_max_per_day', '600', 'int', 'Miles a day before a reading is queried',
        'A reading implying more than this per day since the last one is accepted and flagged, '
        + 'not refused. Somebody drove to Chicago and back; somebody else typed a digit twice.',
        300);
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'mileage_base_url')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('mileage_base_url', '', 'text', 'Address the vehicle stickers point at',
        'What goes in the QR code, as a phone will see it - for example '
        + 'https://facilities.prairie.illinois.edu/. Leave it blank to use the address this '
        + 'page was reached on, which is right for testing and wrong for a printed sticker.',
        310);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'mileage_source')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('mileage_source', 'How A Reading Arrived', 'Vehicles',
        'Where an odometer reading came from.', 'Mileage history', 90);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'mileage_source')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('mileage_source', 'qr',      'Scanned the sticker', 1, 1, 1),
 ('mileage_source', 'manual',  'Typed in',            2, 1, 1),
 ('mileage_source', 'booking', 'Taking it out or bringing it back', 3, 1, 1),
 ('mileage_source', 'shop',    'On a shop request',   4, 1, 1),
 ('mileage_source', 'import',  'Loaded from a file',  5, 1, 1);
GO
