-- ============================================================
-- PRI Facilities Request System
-- 12_inhs_prefix.sql
--   The four INHS field sites added from the GIS master list carried no
--   survey prefix, so they sorted away from the other INHS entries and
--   read as though they belonged to nobody. Prefixed and resorted, with
--   the previous names kept as aliases so anything already filed or
--   imported against them still resolves.
-- Run against all three databases, then publish reference data.
-- ============================================================

-- Keep the old names findable before renaming.
INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
SELECT b.BuildingID, b.Name,
       LOWER(LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(REPLACE(b.Name, ',', ' '), '.', ' '), '/', ' '), '-', ' ')))),
       'manual'
FROM ref.Buildings b
WHERE b.BldgNumber IN (N'P004', N'P007', N'P010', N'2546')
  AND NOT EXISTS (
      SELECT 1 FROM ref.BuildingAliases a
      WHERE a.BuildingID = b.BuildingID
        AND a.AliasNorm = LOWER(LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(REPLACE(b.Name, ',', ' '), '.', ' '), '/', ' '), '-', ' ')))));
GO

UPDATE ref.Buildings
   SET Name = N'INHS Cache River Field House, 925 Sunflower Lane',
       SortKey = N'inhs cache river fi'
 WHERE BldgNumber = N'P004';
GO
UPDATE ref.Buildings
   SET Name = N'INHS Blackwell Forest Preserve, Urban Stream Restoration Field Coop',
       SortKey = N'inhs blackwell fore'
 WHERE BldgNumber = N'P007';
GO
UPDATE ref.Buildings
   SET Name = N'INHS Lost Mound Field Coop, 3159 Crim Drive',
       SortKey = N'inhs lost mound fie'
 WHERE BldgNumber = N'P010';
GO
UPDATE ref.Buildings
   SET Name = N'INHS Illinois River Biological Station, 1002 N Schrader Ave',
       SortKey = N'inhs illinois river'
 WHERE BldgNumber = N'2546';
GO

-- Short forms people actually say.
INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
SELECT b.BuildingID, v.AliasText, v.AliasNorm, 'manual'
FROM (VALUES
    (N'P004', N'INHS Cache River',        N'inhs cache river'),
    (N'P007', N'INHS Blackwell',          N'inhs blackwell'),
    (N'P010', N'INHS Lost Mound',         N'inhs lost mound'),
    (N'2546', N'INHS Illinois River',     N'inhs illinois river'),
    (N'2546', N'INHS Illinois River Biological Station', N'inhs illinois river biological station')
) AS v (BldgNumber, AliasText, AliasNorm)
JOIN ref.Buildings b ON b.BldgNumber = v.BldgNumber
WHERE NOT EXISTS (
    SELECT 1 FROM ref.BuildingAliases a
    WHERE a.BuildingID = b.BuildingID AND a.AliasNorm = v.AliasNorm);
GO
