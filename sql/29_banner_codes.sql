-- ============================================================
-- PRI Facilities Request System
-- 29_banner_codes.sql
--   The Banner account codes: what kind of thing is being bought, as the
--   university classifies it. Nearly three thousand of them, so this is a
--   directory to search rather than a list to scroll, and it changes, so
--   it loads like every other campus export.
--
--   "Data enterable" is the column that matters. Most codes are budget
--   rollups that nobody may put on a purchase line, and offering them
--   would be offering a mistake.
-- Work database.
-- ============================================================

IF OBJECT_ID('ref.BannerAccountCodes', 'U') IS NULL
EXEC('
CREATE TABLE ref.BannerAccountCodes (
    AccountCode   NVARCHAR(20)  NOT NULL PRIMARY KEY,
    FullTitle     NVARCHAR(300) NULL,
    BannerTitle   NVARCHAR(200) NULL,
    Description   NVARCHAR(MAX) NULL,
    Examples      NVARCHAR(MAX) NULL,
    DoNotUseFor   NVARCHAR(MAX) NULL,
    SummaryCode   NVARCHAR(20)  NULL,
    Predecessor   NVARCHAR(20)  NULL,
    AccountType   NVARCHAR(20)  NULL,
    SamsCode      NVARCHAR(20)  NULL,
    IsBudgetCode  BIT NOT NULL DEFAULT 0,
    IsEnterable   BIT NOT NULL DEFAULT 0,
    ServiceDates  NVARCHAR(200) NULL,
    SourceLabel   NVARCHAR(120) NULL,
    UpdatedAt     DATETIME2 NULL
);
CREATE INDEX IX_BannerCodes_Enterable ON ref.BannerAccountCodes(IsEnterable, AccountCode);');
GO

-- The hand-seeded stand-in is gone now there is a real directory.
DELETE FROM ref.PickLists WHERE ListKey = 'banner_account';
DELETE FROM ref.PickListTypes WHERE ListKey = 'banner_account';
GO

MERGE ref.DataSources AS t
USING (VALUES
    ('banner', 'Banner account codes',
     'What kind of thing is being bought, as the university classifies it. Roughly three thousand codes; only the ones marked enterable are offered on a purchase line.',
     NULL, 'Active Banner Account Codes*.xlsx', 180, 50)
) AS s (SourceKey, Label, Description, SourceUrl, FilePattern, RefreshDays, SortOrder)
ON t.SourceKey = s.SourceKey
WHEN MATCHED THEN UPDATE SET t.Label = s.Label, t.Description = s.Description,
    t.FilePattern = s.FilePattern, t.SortOrder = s.SortOrder
WHEN NOT MATCHED THEN
    INSERT (SourceKey, Label, Description, SourceUrl, FilePattern, RefreshDays, SortOrder)
    VALUES (s.SourceKey, s.Label, s.Description, s.SourceUrl, s.FilePattern,
            s.RefreshDays, s.SortOrder);
GO
