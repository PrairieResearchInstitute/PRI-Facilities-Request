-- ============================================================
-- PRI Facilities Request System
-- 18_accounts.sql
--   The PRIDE active grants report carries the principal investigator's
--   NetID and the date an account ends, both of which matter: the NetID
--   turns a name into a person the approval chain can reach, and the end
--   date stops work being charged to an account that has closed.
-- Shop database.
-- ============================================================

IF OBJECT_ID('ref.CfopAccounts', 'U') IS NOT NULL
   AND COL_LENGTH('ref.CfopAccounts', 'AccountPINetID') IS NULL
EXEC('
ALTER TABLE ref.CfopAccounts ADD
    AccountPINetID   NVARCHAR(64) NULL,
    FundManagerNetID NVARCHAR(64) NULL,
    EndsOn           NVARCHAR(40) NULL;');
GO

-- The report spells fund types out in full: "GRF - Main Public Service",
-- "103 Indirect Cost Recovery". The column was sized for a short code.
IF OBJECT_ID('ref.CfopAccounts', 'U') IS NOT NULL
   AND COL_LENGTH('ref.CfopAccounts', 'FundType') < 240
EXEC('ALTER TABLE ref.CfopAccounts ALTER COLUMN FundType NVARCHAR(120) NULL;');
GO
IF OBJECT_ID('ref.CfopAccounts', 'U') IS NOT NULL
   AND COL_LENGTH('ref.CfopAccounts', 'ActivityCode') < 80
EXEC('ALTER TABLE ref.CfopAccounts ALTER COLUMN ActivityCode NVARCHAR(40) NULL;');
GO

-- A handful of accounts in the report carry no owning survey. That is a
-- fact about the account, not a gap to be filled with a guess.
IF OBJECT_ID('ref.CfopAccounts', 'U') IS NOT NULL
   AND EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID('ref.CfopAccounts')
                 AND name = 'SurveyCode' AND is_nullable = 0)
EXEC('ALTER TABLE ref.CfopAccounts ALTER COLUMN SurveyCode NVARCHAR(20) NULL;');
GO

-- The unit head rule keys on fund type. The PRIDE report spells these
-- out in words, so the setting matches on a fragment rather than a code.
IF EXISTS (SELECT 1 FROM app.Settings WHERE SettingKey = 'unit_head_fund_types')
    UPDATE app.Settings
       SET SettingValue = 'ICR,GRF,Indirect Cost Recovery',
           HelpText = 'Fund types whose use for repairs or maintenance opens unit head review. '
                    + 'Matched as fragments against the account fund type from the PRIDE report, '
                    + 'so "ICR" catches "ISGS ICR - REG" and "Indirect Cost Recovery".'
     WHERE SettingKey = 'unit_head_fund_types';
GO
