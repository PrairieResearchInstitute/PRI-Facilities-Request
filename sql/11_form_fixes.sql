-- ============================================================
-- PRI Facilities Request System
-- 11_form_fixes.sql
--   Natures that were missing, and the shop request columns the
--   drawings and the location dropdowns need.
-- Idempotent; run against all three databases.
-- ============================================================

-- ---------- natures ----------
-- A fallen tree fits none of the original eight: it is not a repair, not
-- a replacement, not an install, and only sometimes a hazard. Removal and
-- weather response are their own kinds of work.
IF OBJECT_ID('ref.Natures', 'U') IS NOT NULL
EXEC('
IF NOT EXISTS (SELECT 1 FROM ref.Natures WHERE Name = ''Remove or clean up'')
    INSERT INTO ref.Natures (Name) VALUES (''Remove or clean up'');
IF NOT EXISTS (SELECT 1 FROM ref.Natures WHERE Name = ''Storm or incident response'')
    INSERT INTO ref.Natures (Name) VALUES (''Storm or incident response'');
IF NOT EXISTS (SELECT 1 FROM ref.Natures WHERE Name = ''Secure or make safe'')
    INSERT INTO ref.Natures (Name) VALUES (''Secure or make safe'');
');
GO

-- ---------- shop requests: floor, and the drawing count ----------
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL
   AND COL_LENGTH('sh.ShopRequests', 'FloorCode') IS NULL
EXEC('ALTER TABLE sh.ShopRequests ADD FloorCode NVARCHAR(20) NULL;');
GO

-- ---------- attachments: what the file is for ----------
-- A drawing and a photograph of a leak are both attachments, but they
-- are not the same thing to the person reading the ticket.
IF OBJECT_ID('app.Attachments', 'U') IS NOT NULL
   AND COL_LENGTH('app.Attachments', 'Purpose') IS NULL
EXEC('
ALTER TABLE app.Attachments ADD
    Purpose     NVARCHAR(20) NOT NULL DEFAULT ''photo''
        CHECK (Purpose IN (''photo'',''drawing'',''document'',''other'')),
    Caption     NVARCHAR(300) NULL;');
GO

-- ---------- move items: where the item came from in the inventory ----------
-- When an item is picked off the room's inventory rather than typed, the
-- link is kept so completing the move can update the property record.
IF OBJECT_ID('mv.MoveItems', 'U') IS NOT NULL
   AND COL_LENGTH('mv.MoveItems', 'InventorySourceID') IS NULL
EXEC('ALTER TABLE mv.MoveItems ADD InventorySourceID INT NULL;');
GO
