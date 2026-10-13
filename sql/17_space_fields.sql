-- ============================================================
-- PRI Facilities Request System
-- 17_space_fields.sql
--   The INHS space request form asks for more than the first cut of
--   ours did: who else is affected, what the change actually is, what it
--   might cost and out of whose funds, and which of five kinds of space
--   is wanted. Those are the questions the committee decides on, so they
--   belong on the form rather than in a covering note.
-- Work database.
-- ============================================================

IF OBJECT_ID('rq.SpaceRequests', 'U') IS NOT NULL
   AND COL_LENGTH('rq.SpaceRequests', 'SpaceType') IS NULL
EXEC('
ALTER TABLE rq.SpaceRequests ADD
    SubmittedByNames NVARCHAR(300) NULL,   -- everybody submitting, not just the person signed in
    RequesterEmail   NVARCHAR(200) NULL,
    StaffAffected    NVARCHAR(MAX) NULL,   -- people affected who are not submitting
    RequestedChange  NVARCHAR(MAX) NULL,   -- what should change
    PotentialCosts   NVARCHAR(300) NULL,
    FundingSource    NVARCHAR(300) NULL,   -- including the grant name
    SpaceType        NVARCHAR(60)  NULL
        CHECK (SpaceType IS NULL OR SpaceType IN
               (''office'',''storage'',''lab'',''greenhouse'',''other'')),
    SpaceTypeOther   NVARCHAR(200) NULL;');
GO
