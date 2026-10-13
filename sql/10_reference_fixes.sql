-- ============================================================
-- PRI Facilities Request System
-- 10_reference_fixes.sql
--   Buildings present in the facilities GIS master list but missing from
--   the original dropdown, the number for the ISAS curations space, and
--   the leading zero convention Facilities and Services uses.
-- Run against all three databases, then publish reference data.
-- ============================================================

-- ---------- ISAS Main Curations Space is building 2600 ----------
-- The GIS master list names it 1812 Federal Dr / ISAS Repository.
IF EXISTS (SELECT 1 FROM ref.Buildings WHERE BldgNumber = N'TBD')
   AND NOT EXISTS (SELECT 1 FROM ref.Buildings WHERE BldgNumber = N'2600')
BEGIN
    UPDATE ref.Buildings
       SET BldgNumber = N'2600',
           Name = N'ISAS Main Curations Space (ISAS Repository)',
           AddressLine = N'1812 Federal Dr',
           City = N'Urbana'
     WHERE BldgNumber = N'TBD';
END
GO

-- ---------- leading zeros ----------
-- Facilities and Services writes a four digit building number with the
-- leading zero. Anything numeric and shorter is padded, everywhere the
-- number is stored as a natural key.
UPDATE ref.Buildings
   SET BldgNumber = RIGHT(N'0000' + BldgNumber, 4)
 WHERE BldgNumber NOT LIKE N'%[^0-9]%' AND LEN(BldgNumber) < 4;
GO
IF OBJECT_ID('wr.WorkRequests', 'U') IS NOT NULL
EXEC('UPDATE wr.WorkRequests SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
       WHERE BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;');
GO
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL
EXEC('UPDATE sh.ShopRequests SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
       WHERE BldgNumber IS NOT NULL AND BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;');
GO
IF OBJECT_ID('mv.MoveItems', 'U') IS NOT NULL
EXEC('
UPDATE mv.MoveItems SET FromBldgNumber = RIGHT(''0000'' + FromBldgNumber, 4)
 WHERE FromBldgNumber IS NOT NULL AND FromBldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(FromBldgNumber) < 4;
UPDATE mv.MoveItems SET ToBldgNumber = RIGHT(''0000'' + ToBldgNumber, 4)
 WHERE ToBldgNumber IS NOT NULL AND ToBldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(ToBldgNumber) < 4;');
GO
IF OBJECT_ID('gis.Rooms', 'U') IS NOT NULL
EXEC('
UPDATE gis.Rooms SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
 WHERE BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;
UPDATE gis.Floors SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
 WHERE BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;
UPDATE gis.Inventory SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
 WHERE BldgNumber IS NOT NULL AND BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;
UPDATE gis.Occupants SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
 WHERE BldgNumber IS NOT NULL AND BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;
UPDATE gis.Safety SET BldgNumber = RIGHT(''0000'' + BldgNumber, 4)
 WHERE BldgNumber IS NOT NULL AND BldgNumber NOT LIKE ''%[^0-9]%'' AND LEN(BldgNumber) < 4;');
GO
UPDATE ref.Rooms SET BldgNumber = RIGHT(N'0000' + BldgNumber, 4)
 WHERE BldgNumber NOT LIKE N'%[^0-9]%' AND LEN(BldgNumber) < 4;
GO

-- ---------- buildings in the GIS master list, absent from the dropdown ----------
-- Seven real PRI facilities. Anyone at these sites previously had to file
-- under the catch all entry.
MERGE ref.Buildings AS t
USING (VALUES
    (N'2566', N'isas rantoul 0001', N'ISAS Rantoul 1 (Taylor Studios Studio 1, Warehouse Suite A)',
     N'1310 Harmon Dr', N'Rantoul', 0),
    (N'2130', N'isas rantoul 0002', N'ISAS Rantoul 2/3 (Taylor Studios Studio 2, Warehouse Suite B)',
     N'1330 Harmon Dr', N'Rantoul', 0),
    (N'0337', N'paleobotanical bui', N'Paleobotanical Building / Coal Ball Collection',
     NULL, NULL, 1),
    (N'2546', N'il river biologica', N'Illinois River Biological Station, 1002 N Schrader Ave',
     N'1002 N Schrader Ave', N'Havana', 0),
    (N'P004', N'cache river field ', N'Cache River Field House, 925 Sunflower Lane',
     N'925 Sunflower Lane', N'Belknap', 0),
    (N'P007', N'blackwell forest p', N'Blackwell Forest Preserve, Urban Stream Restoration Field Coop',
     N'Butterfield Rd', N'Warrenville', 0),
    (N'P010', N'lost mound field c', N'Lost Mound Field Coop, 3159 Crim Drive',
     N'3159 Crim Drive', N'Savanna', 0)
) AS s (BldgNumber, SortKey, Name, AddressLine, City, IsOnCampus)
ON t.BldgNumber = s.BldgNumber
WHEN NOT MATCHED THEN
    INSERT (BldgNumber, SortKey, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText, IsActive)
    VALUES (s.BldgNumber, s.SortKey, s.Name, s.AddressLine, s.City, s.IsOnCampus, 0, 0, 1);
GO

-- Aliases for the new entries, so a legacy file naming them in free text
-- still resolves.
INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
SELECT b.BuildingID, v.AliasText, v.AliasNorm, 'seed'
FROM (VALUES
    (N'2566', N'ISAS Rantoul 1', N'isas rantoul 1'),
    (N'2566', N'Taylor Studios Studio 1', N'taylor studios studio 1'),
    (N'2130', N'ISAS Rantoul 2/3', N'isas rantoul 2 3'),
    (N'2130', N'Taylor Studios Studio 2', N'taylor studios studio 2'),
    (N'0337', N'Paleobotanical Building', N'paleobotanical building'),
    (N'0337', N'Coal Ball Collection', N'coal ball collection'),
    (N'2546', N'IL River Biological Station', N'il river biological station'),
    (N'P004', N'Cache River Field House', N'cache river field house'),
    (N'P007', N'Blackwell Forest Preserve', N'blackwell forest preserve'),
    (N'P010', N'Lost Mound Field Coop', N'lost mound field coop')
) AS v (BldgNumber, AliasText, AliasNorm)
JOIN ref.Buildings b ON b.BldgNumber = v.BldgNumber
WHERE NOT EXISTS (
    SELECT 1 FROM ref.BuildingAliases a
    WHERE a.BuildingID = b.BuildingID AND a.AliasNorm = v.AliasNorm);
GO

-- The curations space alias, now that it carries a number.
INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
SELECT b.BuildingID, N'ISAS Repository', N'isas repository', 'gis'
FROM ref.Buildings b
WHERE b.BldgNumber = N'2600'
  AND NOT EXISTS (SELECT 1 FROM ref.BuildingAliases a
                  WHERE a.BuildingID = b.BuildingID AND a.AliasNorm = N'isas repository');
GO
