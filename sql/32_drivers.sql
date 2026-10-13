/* ---------------------------------------------------------------------
   Driver forms, and booking a vehicle against them.

   Nobody drives a University vehicle without their paperwork on file, and
   today that is somebody's memory plus a folder. So: the forms are filed
   here, they carry an expiry, and booking a vehicle asks the file rather
   than asking the person.

   The gate is the point. A booking form that merely reminds you to file
   your paperwork is a booking form that gets ignored; one that will not
   take the booking is one that gets the paperwork filed.

   Which forms, how long they last, and where to get them are all data,
   not code - because the answer differs by unit and changes when the
   University changes it.
   --------------------------------------------------------------------- */

/* --- first, a bug this would have made worse ------------------------- */
/* app.Attachments is keyed by RequestID alone. In the Work database that
   number belongs to five different kinds of request at once, so purchase
   5 and space request 5 have always shared their files. Nobody noticed
   because the only rows are photos on work orders. Adding scanned driver
   paperwork to that pile is not something to do first and fix later. */

USE PRI_FacilitiesWork;
GO
IF COL_LENGTH('app.Attachments', 'RequestType') IS NULL
BEGIN
    ALTER TABLE app.Attachments ADD RequestType NVARCHAR(20) NULL;
END
GO
UPDATE app.Attachments SET RequestType = 'work' WHERE RequestType IS NULL;
GO

USE PRI_FacilitiesMove;
GO
IF COL_LENGTH('app.Attachments', 'RequestType') IS NULL
    ALTER TABLE app.Attachments ADD RequestType NVARCHAR(20) NULL;
GO
UPDATE app.Attachments SET RequestType = 'move' WHERE RequestType IS NULL;
GO

USE PRI_FacilitiesShop;
GO
IF COL_LENGTH('app.Attachments', 'RequestType') IS NULL
    ALTER TABLE app.Attachments ADD RequestType NVARCHAR(20) NULL;
GO
UPDATE app.Attachments SET RequestType = 'shop' WHERE RequestType IS NULL;
GO

/* --- the kinds of form, and how long each one lasts ------------------ */
/* A real table rather than a pick list, because a form type carries more
   than a label: how many months it is good for, whether it is required
   before driving, and where the person is supposed to get it. */
IF OBJECT_ID('ref.DriverFormTypes') IS NULL
CREATE TABLE ref.DriverFormTypes (
    FormCode        NVARCHAR(30)  NOT NULL PRIMARY KEY,
    Title           NVARCHAR(150) NOT NULL,
    ValidMonths     INT           NOT NULL DEFAULT 12,
    IsRequired      BIT           NOT NULL DEFAULT 1,
    WhereToGetIt    NVARCHAR(500) NULL,
    Instructions    NVARCHAR(1000) NULL,
    SortOrder       INT           NOT NULL DEFAULT 100,
    IsActive        BIT           NOT NULL DEFAULT 1,
    UpdatedAt       DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedByNetID  NVARCHAR(50)  NULL
);
GO

/* Seeded with names close to what the University asks for. They are
   meant to be renamed: Configuration > Driver forms is where the titles,
   the validity periods and the instructions are edited, and nothing in
   the code knows these particular codes. */
IF NOT EXISTS (SELECT 1 FROM ref.DriverFormTypes)
INSERT INTO ref.DriverFormTypes
    (FormCode, Title, ValidMonths, IsRequired, WhereToGetIt, Instructions, SortOrder)
VALUES
 ('mvr', 'Motor Vehicle Record authorisation', 12, 1,
  'University Office of Risk Management',
  'Authorises the University to check your driving record. Renewed every year.', 10),
 ('certification', 'Driver certification and vehicle use agreement', 12, 1,
  'University Office of Risk Management',
  'Certifies that you hold a valid licence and agree to the conditions of driving a '
  + 'University vehicle. Renewed every year.', 20),
 ('defensive', 'Defensive driving course', 48, 0,
  'Division of Public Safety',
  'Not required of every driver. Where a unit requires it, it lasts four years.', 30);
GO

/* --- the filed forms ------------------------------------------------- */
USE PRI_FacilitiesShop;
GO

IF OBJECT_ID('sh.DriverForms') IS NULL
CREATE TABLE sh.DriverForms (
    DriverFormID      INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber      NVARCHAR(30)  NOT NULL UNIQUE,
    NetID             NVARCHAR(50)  NOT NULL,
    PersonName        NVARCHAR(150) NULL,
    SurveyCode        NVARCHAR(10)  NULL,
    FormCode          NVARCHAR(30)  NOT NULL,

    /* The dates are the whole record. CompletedOn is what the person
       signed; ExpiresOn is derived from it and the type's validity, and
       kept rather than computed so a type whose period changes later does
       not silently re-date every form ever filed. */
    CompletedOn       DATE          NOT NULL,
    ExpiresOn         DATE          NULL,

    LicenceState      NVARCHAR(10)  NULL,
    LicenceNumberLast4 NVARCHAR(8)  NULL,

    Status            NVARCHAR(20)  NOT NULL DEFAULT 'submitted',
    Note              NVARCHAR(1000) NULL,
    ReviewNote        NVARCHAR(1000) NULL,
    ReviewedByNetID   NVARCHAR(50)  NULL,
    ReviewedAt        DATETIME2     NULL,
    SubmittedByNetID  NVARCHAR(50)  NOT NULL,
    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),

    CONSTRAINT CK_DriverForms_Status CHECK (Status IN
        ('submitted', 'accepted', 'rejected', 'superseded'))
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_DriverForms_Who')
CREATE INDEX IX_DriverForms_Who ON sh.DriverForms (NetID, FormCode, Status);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_DriverForms_Expiry')
CREATE INDEX IX_DriverForms_Expiry ON sh.DriverForms (ExpiresOn);
GO

/* --- bookings -------------------------------------------------------- */
IF OBJECT_ID('sh.VehicleBookings') IS NULL
CREATE TABLE sh.VehicleBookings (
    BookingID         INT IDENTITY(1,1) PRIMARY KEY,
    TicketNumber      NVARCHAR(30)  NOT NULL UNIQUE,
    FleetNumber       NVARCHAR(30)  NOT NULL,
    RequestedByNetID  NVARCHAR(50)  NOT NULL,
    RequestedByUserID INT NULL,

    /* The driver is not always the person booking - a field lead books
       for the crew - so the clearance check is run against the driver,
       and that is who the record names. */
    DriverName        NVARCHAR(150) NOT NULL,
    DriverNetID       NVARCHAR(50)  NOT NULL,
    SurveyCode        NVARCHAR(10)  NULL,

    StartsAt          DATETIME2     NOT NULL,
    EndsAt            DATETIME2     NOT NULL,
    Destination       NVARCHAR(300) NULL,
    Purpose           NVARCHAR(1000) NULL,
    PassengerCount    INT           NULL,
    TowingTrailer     BIT           NOT NULL DEFAULT 0,

    OdometerOut       INT NULL,
    OdometerIn        INT NULL,
    WentOutAt         DATETIME2 NULL,
    CameBackAt        DATETIME2 NULL,

    Status            NVARCHAR(20)  NOT NULL DEFAULT 'booked',
    Note              NVARCHAR(1000) NULL,
    CancelReason      NVARCHAR(500) NULL,

    /* What the gate saw at the moment of booking. Kept so a question a
       year later - "was she cleared when she took it out?" - has an
       answer that does not depend on today's file. */
    ClearanceNote     NVARCHAR(500) NULL,
    ClearedByOverride NVARCHAR(50)  NULL,

    CreatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt         DATETIME2     NOT NULL DEFAULT SYSDATETIME(),

    CONSTRAINT CK_VehicleBookings_Status CHECK (Status IN
        ('booked', 'out', 'returned', 'cancelled'))
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_VehicleBookings_When')
CREATE INDEX IX_VehicleBookings_When
    ON sh.VehicleBookings (FleetNumber, StartsAt, EndsAt);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_VehicleBookings_Driver')
CREATE INDEX IX_VehicleBookings_Driver ON sh.VehicleBookings (DriverNetID, StartsAt DESC);
GO

/* --- settings, in the Work database where they all live -------------- */
USE PRI_FacilitiesWork;
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_drv')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('ticket_prefix_drv', 'DRV', 'text', 'Driver form ticket prefix',
        'The letters in front of a filed driver form, as DRV-FY27-00001.', 200);
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'ticket_prefix_bkg')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('ticket_prefix_bkg', 'VEH', 'text', 'Vehicle booking ticket prefix',
        'The letters in front of a vehicle booking, as VEH-FY27-00001.', 210);
GO

/* How long before an expiry the person starts being warned. */
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'driver_warn_days')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('driver_warn_days', '45', 'int', 'Warn this many days before a driver form expires',
        'A driver whose paperwork lapses inside this window is told on the booking form, and '
        + 'appears on the fleet office list, while there is still time to renew.', 220);
GO

/* Whether a booking may be forced through when the paperwork is not on
   file. Off: the gate is a gate. On: the fleet office can override it and
   the override is recorded against the booking with their name. */
IF NOT EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'driver_gate_override')
INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
VALUES ('driver_gate_override', '1', 'bool',
        'Let the fleet office book a driver whose forms are not on file',
        'The override is recorded on the booking with the name of whoever used it. Turn this '
        + 'off to make the paperwork absolute.', 230);
GO

IF NOT EXISTS (SELECT 1 FROM app.NotifyRules WHERE EventCode = 'driver_form_filed')
INSERT INTO app.NotifyRules (EventCode, MatchType, Email, DisplayName, Note, IsActive)
VALUES ('driver_form_filed', 'always', 'configure-me@example.edu', 'Adam Deany',
        'Told when a driver files paperwork that needs checking.', 1);
GO

IF NOT EXISTS (SELECT 1 FROM app.NotifyRules WHERE EventCode = 'vehicle_booked')
INSERT INTO app.NotifyRules (EventCode, MatchType, Email, DisplayName, Note, IsActive)
VALUES ('vehicle_booked', 'always', 'configure-me@example.edu', 'Adam Deany',
        'Told when a vehicle is booked.', 1);
GO

/* The state a booking is in, so the board and the forms read the same
   words and somebody can change them without a developer. */
IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'booking_status')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('booking_status', 'Vehicle booking states', 'Vehicles',
        'Where a vehicle booking has got to.', 'Vehicle booking form and board', 70);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'booking_status')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('booking_status', 'booked',    'Booked',            1, 1, 1),
 ('booking_status', 'out',       'Out',               2, 1, 1),
 ('booking_status', 'returned',  'Back',              3, 1, 1),
 ('booking_status', 'cancelled', 'Cancelled',         4, 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM ref.PickListTypes WHERE ListKey = 'driver_form_status')
INSERT INTO ref.PickListTypes (ListKey, Title, GroupName, Description, UsedOn, SortOrder)
VALUES ('driver_form_status', 'Driver form states', 'Vehicles',
        'What has happened to a filed driver form.', 'Driver form archive', 80);
GO
IF NOT EXISTS (SELECT 1 FROM ref.PickLists WHERE ListKey = 'driver_form_status')
INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected)
VALUES
 ('driver_form_status', 'submitted',  'Filed, not yet checked', 1, 1, 1),
 ('driver_form_status', 'accepted',   'Accepted',               2, 1, 1),
 ('driver_form_status', 'rejected',   'Rejected',               3, 1, 1),
 ('driver_form_status', 'superseded', 'Replaced by a newer one', 4, 1, 1);
GO
