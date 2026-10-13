-- ============================================================
-- PRI Facilities Request System
-- 22_keysource.sql
--   The key assignment register joins the other campus exports on the
--   Data refresh screen. Its address was read off the footer of the
--   printed report: pride.prairie.illinois.edu/pride/c3po/index.php.
--
--   It is the awkward one. C3PO offers no download for this table, so it
--   is copied from the page rather than exported, and the printed PDF
--   cannot be read by machine - the report is set in a font whose glyphs
--   are drawings with no character map. Recording it here anyway means
--   the app can at least say how old the register is and where to go for
--   a fresh one.
-- Work database.
-- ============================================================

MERGE ref.DataSources AS t
USING (VALUES
    ('keys', 'Key assignments',
     'Who holds which key to which room. Copied from the C3PO key page, which offers no download; the printed report cannot be read by machine.',
     'https://pride.prairie.illinois.edu/pride/c3po/index.php?page=keys&',
     'KeyAssignments*.csv', 90, 40)
) AS s (SourceKey, Label, Description, SourceUrl, FilePattern, RefreshDays, SortOrder)
ON t.SourceKey = s.SourceKey
WHEN MATCHED THEN UPDATE SET
    t.Label = s.Label, t.Description = s.Description, t.SourceUrl = s.SourceUrl,
    t.FilePattern = s.FilePattern, t.SortOrder = s.SortOrder
WHEN NOT MATCHED THEN
    INSERT (SourceKey, Label, Description, SourceUrl, FilePattern, RefreshDays, SortOrder)
    VALUES (s.SourceKey, s.Label, s.Description, s.SourceUrl, s.FilePattern,
            s.RefreshDays, s.SortOrder);
GO
