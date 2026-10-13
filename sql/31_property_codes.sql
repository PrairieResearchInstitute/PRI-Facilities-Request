/* ---------------------------------------------------------------------
   Two constraints that were written for a narrower set of screens than
   the one property update form now covers.

   gis.InventoryEvents.EventCode allowed five words, chosen when each
   change had its own screen and its own verb. The one form distinguishes
   more than that - a room move and a change of custodian were both
   "transferred", which is exactly the distinction an audit needs - so the
   vocabulary grows rather than being flattened back.

   gis.Inventory.Status allowed four ways of leaving. The removal reason
   list offers six, and two of them - traded in, stripped for parts -
   would have been rejected on save. That is a form offering a choice the
   database refuses, which is the worst kind of bug to find in front of
   somebody.
   --------------------------------------------------------------------- */

USE PRI_FacilitiesWork;
GO

DECLARE @c NVARCHAR(200);

SELECT @c = c.name FROM sys.check_constraints c
 JOIN sys.tables t ON t.object_id = c.parent_object_id
 JOIN sys.columns col ON col.object_id = t.object_id AND col.column_id = c.parent_column_id
 WHERE t.name = 'InventoryEvents' AND col.name = 'EventCode';
IF @c IS NOT NULL
    EXEC('ALTER TABLE gis.InventoryEvents DROP CONSTRAINT [' + @c + ']');
GO

ALTER TABLE gis.InventoryEvents WITH CHECK
ADD CONSTRAINT CK_InventoryEvents_EventCode CHECK (EventCode IN (
    'ingested',      /* the old word for a registration */
    'registered',    /* it arrived and was given its number */
    'moved',         /* it is in a different room */
    'reassigned',    /* somebody else answers for it */
    'transferred',   /* the old word, which meant either of the two above */
    'corrected',     /* the record was wrong */
    'edited',        /* the old word for a correction */
    'removed',       /* it left the books */
    'restored'       /* it came back */
));
GO

DECLARE @s NVARCHAR(200);
SELECT @s = c.name FROM sys.check_constraints c
 JOIN sys.tables t ON t.object_id = c.parent_object_id
 JOIN sys.columns col ON col.object_id = t.object_id AND col.column_id = c.parent_column_id
 WHERE t.name = 'Inventory' AND col.name = 'Status';
IF @s IS NOT NULL
    EXEC('ALTER TABLE gis.Inventory DROP CONSTRAINT [' + @s + ']');
GO

/* Every code in the removal_reason pick list, plus the two states an item
   can be in while it is still ours. If a reason is added to that list, it
   has to be added here too - which is the price of the database refusing
   to hold a status nobody defined. */
ALTER TABLE gis.Inventory WITH CHECK
ADD CONSTRAINT CK_Inventory_Status CHECK (Status IN (
    'active',
    'returned',
    'surplus',
    'trashed',
    'lost',
    'transferred',
    'traded',
    'cannibalised'
));
GO
