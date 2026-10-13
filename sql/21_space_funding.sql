-- ============================================================
-- PRI Facilities Request System
-- 21_space_funding.sql
--   Two corrections to the space request.
--
--   The kinds of space came from the INHS form, which asks about an annex
--   greenhouse because INHS has one. The other four surveys do not, and
--   they hold core libraries, curation space, instrument rooms and vehicle
--   bays that had nowhere to go. The list is widened to the institute and
--   the check constraint with it.
--
--   Source of funds was a line of free text. When space costs money the
--   account decides who approves it, so it is now a real CFOP, and the
--   fund manager and principal investigator are recorded as they stood
--   when the request was filed rather than looked up later.
-- Work database.
-- ============================================================

IF OBJECT_ID('rq.SpaceRequests', 'U') IS NOT NULL
   AND COL_LENGTH('rq.SpaceRequests', 'FundingCfop') IS NULL
EXEC('
ALTER TABLE rq.SpaceRequests ADD
    FundingCfop        NVARCHAR(40)  NULL,   -- the account, chosen from the directory
    FundingPI          NVARCHAR(200) NULL,   -- as it stood when the request was filed
    FundingFundManager NVARCHAR(200) NULL,
    FundingSurveyCode  NVARCHAR(20)  NULL,   -- the survey that owns the account
    FundingDescription NVARCHAR(300) NULL,
    SubmitterNetID     NVARCHAR(64)  NULL,   -- the primary requester, who may not be the filer
    SubmitterSurvey    NVARCHAR(20)  NULL;');
GO

-- The kinds of space, widened from the five on the INHS form to what the
-- whole institute actually occupies. The old values are kept so requests
-- already filed still satisfy the constraint.
IF OBJECT_ID('rq.SpaceRequests', 'U') IS NOT NULL
   AND EXISTS (SELECT 1 FROM sys.check_constraints c
               WHERE c.parent_object_id = OBJECT_ID('rq.SpaceRequests')
                 AND c.definition LIKE '%greenhouse%'
                 AND c.definition NOT LIKE '%collections%')
BEGIN
    DECLARE @name SYSNAME;
    SELECT @name = c.name FROM sys.check_constraints c
     WHERE c.parent_object_id = OBJECT_ID('rq.SpaceRequests')
       AND c.definition LIKE '%greenhouse%';
    EXEC('ALTER TABLE rq.SpaceRequests DROP CONSTRAINT ' + @name);
    EXEC('
ALTER TABLE rq.SpaceRequests ADD CONSTRAINT CK_SpaceRequests_SpaceType
    CHECK (SpaceType IS NULL OR SpaceType IN
        (''office'',''lab'',''collections'',''storage'',''instrument'',
         ''processing'',''shop'',''growing'',''meeting'',''greenhouse'',''other''));');
END
GO
