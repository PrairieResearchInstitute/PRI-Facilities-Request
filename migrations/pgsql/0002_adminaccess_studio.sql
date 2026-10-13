-- 0002_adminaccess_studio.sql: let the 'studio' access function into app_adminaccess.
-- The baseline took its CHECK from sql/23_real_people.sql, which rebuilt the
-- SQL Server constraint without 'studio' (added by sql/14_workflow.sql). The
-- application grants 'studio' (facilities/lib/studio.php STUDIO_FUNCTION, the
-- People and Access lists in facilities/lib/adminviews3.php) and the live
-- registers hold it, so the list below is every function the code offers.
ALTER TABLE work.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE work.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE move.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE move.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE shop.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE shop.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE keys.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE keys.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE fleet.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE fleet.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE space.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE space.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE equipment.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE equipment.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE incidents.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE incidents.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE purchasing.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE purchasing.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
ALTER TABLE plans.app_adminaccess DROP CONSTRAINT app_adminaccess_accessfunction_check1;
ALTER TABLE plans.app_adminaccess ADD CONSTRAINT app_adminaccess_accessfunction_check1
    CHECK (accessfunction IN ('director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory', 'it', 'finance', 'deputy', 'executive', 'studio'));
