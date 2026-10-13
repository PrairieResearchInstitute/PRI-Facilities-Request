-- ============================================================
-- PRI Facilities Request System
-- 04_seed_reference.sql   RUN AGAINST ALL THREE DATABASES
-- Generated from Build Specification v1.0, Section 18 and Appendix C.
-- Idempotent: every section skips itself when data already exists.
-- Work-database-only sections (work types, natures, synonyms, WR counter)
-- guard on OBJECT_ID and no-op in the move and shop databases.
--
-- KNOWN DATA GAPS, seeded as flagged rather than invented (Section 3):
--   * Storage Building 2 is absent from the source dropdown. SB1, SB3,
--     SB4, SB5 seed; SB2 does not exist here. Confirm demolition or add.
--   * ISAS Main Curations Space, 1812 Federal Dr, carries no building
--     number. It seeds with BldgNumber 'TBD' and must receive a locally
--     assigned number through Admin before reference data publishes.
-- ============================================================

IF NOT EXISTS (SELECT 1 FROM ref.Surveys)
BEGIN
    INSERT INTO ref.Surveys (Code, Name) VALUES (N'INHS', N'Illinois Natural History Survey');
    INSERT INTO ref.Surveys (Code, Name) VALUES (N'ISGS', N'Illinois State Geological Survey');
    INSERT INTO ref.Surveys (Code, Name) VALUES (N'ISWS', N'Illinois State Water Survey');
    INSERT INTO ref.Surveys (Code, Name) VALUES (N'ISAS', N'Illinois State Archaeological Survey');
    INSERT INTO ref.Surveys (Code, Name) VALUES (N'ISTC', N'Illinois Sustainable Technology Center');
    INSERT INTO ref.Surveys (Code, Name) VALUES (N'OED', N'Office of the Executive Director');
END
GO

IF NOT EXISTS (SELECT 1 FROM ref.Owners)
BEGIN
    INSERT INTO ref.Owners (Code, Name, ShowsOnBoard) VALUES (N'FS', N'Facilities and Services', 1);
    INSERT INTO ref.Owners (Code, Name, ShowsOnBoard) VALUES (N'IT', N'PRI Information Technology', 1);
    INSERT INTO ref.Owners (Code, Name, ShowsOnBoard) VALUES (N'SAFETY', N'Research Safety', 1);
END
GO

IF NOT EXISTS (SELECT 1 FROM ref.Buildings)
BEGIN
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P015', N'academic building il', NULL, N'Academic Building, Illinois Central College', N'Illinois Central College', N'East Peoria', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P149', N'college of vet med', NULL, N'College of Vet Med', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1073', N'forbes natural histo', NULL, N'Forbes Natural History Building (formerly I-Building)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0549', N'getz hall phillips t', NULL, N'Getz Hall, Phillips Tract', N'1735 N Cottonwood Rd', N'Urbana', 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0307', N'inhs bio control lab', N'BCL', N'INHS Bio Control Lab (BCL)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P006', N'inhs chicago botanic', NULL, N'INHS Chicago Botanic Garden Coop', N'1000 Lake Cook Rd', N'Glencoe', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0187', N'inhs entomology lab ', NULL, N'INHS Entomology Lab / Med Ent Building', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1268', N'inhs evers lab', NULL, N'INHS Evers Lab', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1279', N'inhs forbes biologic', N'FBS', N'INHS Forbes Biological Station (FBS)', N'12500 CR 1950N', N'Havana', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2518', N'inhs great rivers fi', N'GRFS', N'INHS Great Rivers Field Station (GRFS)', N'918 Union St', N'Alton', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1282', N'inhs illinois river ', N'IRBS', N'INHS Illinois River Biological Station (IRBS)', N'704 N Schrader St', N'Havana', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P012', N'inhs illinois river ', NULL, N'INHS Illinois River NWR Bunkhouse', N'19008 East CR 2110N', N'Havana', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1280', N'inhs kaskaskia biolo', N'KBS', N'INHS Kaskaskia Biological Station (KBS)', N'1235 County Rd 1000 N', N'Sullivan', 0, 1, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P009', N'inhs lake michigan b', N'LMBS', N'INHS Lake Michigan Biological Station (LMBS)', N'400 17th St', N'Zion', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1048', N'inhs pest management', NULL, N'INHS Pest Management Lab / Kinney Lab', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1101', N'inhs pond site', NULL, N'INHS Pond Site', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P002', N'inhs ridge lake biol', N'RLBS', N'INHS Ridge Lake Biological Station (RLBS)', N'17211 Ridge Lake Rd', N'Charleston', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P003', N'inhs sam parr biolog', N'SPBS', N'INHS Sam Parr Biological Station (SPBS)', N'6401 Meacham Rd', N'Kinmundy', 0, 1, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P005', N'inhs south shore sta', NULL, N'INHS South Shore State Park / Carlyle Lake Field Coop', N'20100 Hazlet Park Rd', N'Carlyle', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P008', N'inhs springfield', NULL, N'INHS Springfield', N'1 Natural Resources Way', N'Springfield', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0313', N'inhs storage buildin', N'SB1', N'INHS Storage Building 1 (SB1)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0311', N'inhs storage buildin', N'SB3', N'INHS Storage Building 3 (SB3)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0309', N'inhs storage buildin', N'SB4', N'INHS Storage Building 4 (SB4)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0325', N'inhs storage buildin', N'SB5', N'INHS Storage Building 5 (SB5)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1099', N'inhs wildlife buildi', NULL, N'INHS Wildlife Building / Grein Farm', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P011', N'inhs yorkville', NULL, N'INHS Yorkville', N'13608 Fox Rd', N'Yorkville', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'TBD', N'isas main curations ', NULL, N'ISAS Main Curations Space', N'1812 Federal Dr', N'Urbana', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2563', N'isas american bottom', N'ABFS', N'ISAS American Bottom Field Station (ABFS)', N'13/15 Gateway Dr', N'Collinsville', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0158', N'isas atam offices be', NULL, N'ISAS ATAM Offices (Bevier Hall)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2571', N'isas central illinoi', N'CIFS', N'ISAS Central Illinois Field Office (CIFS)', N'1108 N Cunningham', N'Urbana', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2515', N'isas nifs', NULL, N'ISAS NIFS', N'21 N Union St', N'Elgin', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0330', N'isas south farms war', NULL, N'ISAS South Farms Warehouse (Repository)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2523', N'isas springfield res', NULL, N'ISAS Springfield Research Office', N'1023 Dorlan Ave', N'Springfield', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2132', N'isas wifs flot lab', NULL, N'ISAS WIFS FLOT Lab', N'1204 W Jackson St', N'Macomb', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2127', N'isas wifs jacksonvil', NULL, N'ISAS WIFS Jacksonville', N'604 E Vandalia Rd', N'Jacksonville', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2123', N'isas wifs macomb', NULL, N'ISAS WIFS Macomb', N'1206 W Jackson St', N'Macomb', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0128', N'isgs applied researc', N'ARL', N'ISGS Applied Research Lab (ARL)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2579', N'isgs carbondale sout', NULL, N'ISGS Carbondale (Southern) Field Office', N'SIU Research Park', N'Carbondale', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1480', N'isgs chicago field o', NULL, N'ISGS Chicago Field Office', N'8 S Michigan Ave #814', N'Chicago', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0305', N'isgs clay liner buil', NULL, N'ISGS Clay Liner Building (Core Storage 2)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P013', N'isgs coastal geology', NULL, N'ISGS Coastal Geology Group', N'901 W Taylor St', N'Chicago', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0315', N'isgs core descriptio', NULL, N'ISGS Core Description Lab / Print Shop', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1039', N'isgs core storage po', NULL, N'ISGS Core Storage Polebarn (Core Storage 1)', N'Research Park', NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0523', N'isgs esa section off', NULL, N'ISGS ESA Section Office, 201 W Curtis Rd', NULL, N'Savoy', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1115', N'isgs esa section off', NULL, N'ISGS ESA Section Office, 203 W Curtis Rd, Suite 1', NULL, N'Savoy', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1132', N'isgs esa section off', NULL, N'ISGS ESA Section Office, 203 W Curtis Rd, Suite 3', NULL, N'Savoy', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'2586', N'isgs lake michigan f', NULL, N'ISGS Lake Michigan Field Office', N'701 North Point Dr', N'Winthrop Harbor', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1102', N'isgs lincoln avenue ', NULL, N'ISGS Lincoln Avenue East Pole Barn', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1098', N'isgs lincoln avenue ', NULL, N'ISGS Lincoln Avenue West Pole Barn', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1499', N'isgs lisle northern ', NULL, N'ISGS Lisle (Northern) Field Office', N'550/650 Warrenville Rd', N'Lisle', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P014', N'isgs michael a bilan', NULL, N'ISGS Michael A Bilandic Building', N'160 N LaSalle St', N'Chicago', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0507', N'isgs wetlands sectio', NULL, N'ISGS Wetlands Section Office at Illini Plaza', N'1817/1819 S Neil St', N'Champaign', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1501', N'isgs wetlands storag', NULL, N'ISGS Wetlands Storage Garage', N'1713 S State St', NULL, 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0206', N'istc illinois sustai', NULL, N'ISTC (Illinois Sustainable Technology Center)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1522', N'isws bondville road ', NULL, N'ISWS Bondville Road Field Station', NULL, N'Bondville', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1104', N'isws building 0001 o', NULL, N'ISWS Building 01, Office of the Director', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0289', N'isws building 0002 l', NULL, N'ISWS Building 02, Labs and IL Room', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1106', N'isws building 0003 c', NULL, N'ISWS Building 03, Chemistry and Technology labs', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1107', N'isws building 0004 g', NULL, N'ISWS Building 04, Groundwater Science', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1108', N'isws building 0005 w', NULL, N'ISWS Building 05, Water Quality and Surface Water', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1109', N'isws building 0006 c', NULL, N'ISWS Building 06, Climate and Atmospheric Science', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1110', N'isws building 0007 s', NULL, N'ISWS Building 07, Storage and field staging', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1111', N'isws building 0008 g', NULL, N'ISWS Building 08, Garage and vehicle storage', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1112', N'isws building 0009 c', NULL, N'ISWS Building 09, Chemistry and Technology offices', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1026', N'isws building 0010 f', NULL, N'ISWS Building 10, Field equipment storage', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1139', N'isws building 0011 s', NULL, N'ISWS Building 11, Shipping, Receiving, CHAMP, shops', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'1495', N'isws east peoria war', NULL, N'ISWS East Peoria Warehouse (Lawrence Building)', NULL, N'East Peoria', 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P171', N'meat science lab', NULL, N'Meat Science Lab', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P242', N'morrill hall', NULL, N'Morrill Hall', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0110', N'npl isas central off', NULL, N'NPL / ISAS Central Offices', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0109', N'nrb natural resource', NULL, N'NRB (Natural Resources Building)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0133', N'nrb garage shop', NULL, N'NRB Garage / Shop', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0134', N'nrb greenhouse', NULL, N'NRB Greenhouse', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0321', N'nrsa annex isgs main', NULL, N'NRSA / Annex / ISGS Main Core Facility', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'0124', N'nsrc national soybea', NULL, N'NSRC (National Soybean Research Center)', NULL, NULL, 1, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P888', N'off site staff resid', NULL, N'Off-Site Staff Residence', N'Various', NULL, 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P999', N'other', NULL, N'Other', NULL, NULL, 0, 0, 1);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P889', N'staff residence out ', NULL, N'Staff Residence Out-of-State', N'Various', NULL, 0, 0, 0);
    INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City, IsOnCampus, IsRemote, RequiresFreeText)
        VALUES (N'P191', N'turner hall', NULL, N'Turner Hall', NULL, NULL, 1, 0, 0);
END
GO

IF NOT EXISTS (SELECT 1 FROM ref.BuildingAliases)
BEGIN
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Academic Building, Illinois Central College', N'academic building illinois central college', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P015';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Academic Building', N'academic building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P015';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'College of Vet Med', N'college of vet med', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P149';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Forbes Natural History Building (formerly I-Building)', N'forbes natural history building formerly i building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1073';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Forbes Natural History Building', N'forbes natural history building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1073';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'I-Building', N'i building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1073';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Getz Hall, Phillips Tract', N'getz hall phillips tract', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0549';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Getz Hall', N'getz hall', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0549';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Bio Control Lab (BCL)', N'inhs bio control lab bcl', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0307';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Bio Control Lab', N'inhs bio control lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0307';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'BCL', N'bcl', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0307';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Chicago Botanic Garden Coop', N'inhs chicago botanic garden coop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P006';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Entomology Lab / Med Ent Building', N'inhs entomology lab med ent building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0187';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Entomology Lab', N'inhs entomology lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0187';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Med Ent Building', N'med ent building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0187';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Evers Lab', N'inhs evers lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1268';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Forbes Biological Station (FBS)', N'inhs forbes biological station fbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1279';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Forbes Biological Station', N'inhs forbes biological station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1279';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'FBS', N'fbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1279';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Great Rivers Field Station (GRFS)', N'inhs great rivers field station grfs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2518';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Great Rivers Field Station', N'inhs great rivers field station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2518';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'GRFS', N'grfs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2518';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Illinois River Biological Station (IRBS)', N'inhs illinois river biological station irbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1282';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Illinois River Biological Station', N'inhs illinois river biological station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1282';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'IRBS', N'irbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1282';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Illinois River NWR Bunkhouse', N'inhs illinois river nwr bunkhouse', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P012';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Kaskaskia Biological Station (KBS)', N'inhs kaskaskia biological station kbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1280';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Kaskaskia Biological Station', N'inhs kaskaskia biological station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1280';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'KBS', N'kbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1280';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Lake Michigan Biological Station (LMBS)', N'inhs lake michigan biological station lmbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P009';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Lake Michigan Biological Station', N'inhs lake michigan biological station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P009';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'LMBS', N'lmbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P009';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Pest Management Lab / Kinney Lab', N'inhs pest management lab kinney lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1048';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Pest Management Lab', N'inhs pest management lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1048';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Kinney Lab', N'kinney lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1048';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Pond Site', N'inhs pond site', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1101';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Ridge Lake Biological Station (RLBS)', N'inhs ridge lake biological station rlbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P002';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Ridge Lake Biological Station', N'inhs ridge lake biological station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P002';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'RLBS', N'rlbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P002';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Sam Parr Biological Station (SPBS)', N'inhs sam parr biological station spbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P003';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Sam Parr Biological Station', N'inhs sam parr biological station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P003';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'SPBS', N'spbs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P003';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS South Shore State Park / Carlyle Lake Field Coop', N'inhs south shore state park carlyle lake field coop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P005';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS South Shore State Park', N'inhs south shore state park', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P005';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Carlyle Lake Field Coop', N'carlyle lake field coop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P005';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Springfield', N'inhs springfield', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P008';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 1 (SB1)', N'inhs storage building 1 sb1', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0313';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 1', N'inhs storage building 1', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0313';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'SB1', N'sb1', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0313';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 3 (SB3)', N'inhs storage building 3 sb3', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0311';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 3', N'inhs storage building 3', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0311';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'SB3', N'sb3', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0311';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 4 (SB4)', N'inhs storage building 4 sb4', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0309';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 4', N'inhs storage building 4', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0309';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'SB4', N'sb4', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0309';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 5 (SB5)', N'inhs storage building 5 sb5', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0325';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Storage Building 5', N'inhs storage building 5', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0325';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'SB5', N'sb5', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0325';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Wildlife Building / Grein Farm', N'inhs wildlife building grein farm', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1099';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Wildlife Building', N'inhs wildlife building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1099';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Grein Farm', N'grein farm', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1099';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'INHS Yorkville', N'inhs yorkville', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P011';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS Main Curations Space', N'isas main curations space', 'seed' FROM ref.Buildings WHERE BldgNumber = N'TBD';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS American Bottom Field Station (ABFS)', N'isas american bottom field station abfs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2563';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS American Bottom Field Station', N'isas american bottom field station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2563';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ABFS', N'abfs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2563';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS ATAM Offices (Bevier Hall)', N'isas atam offices bevier hall', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0158';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS ATAM Offices', N'isas atam offices', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0158';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Bevier Hall', N'bevier hall', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0158';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS Central Illinois Field Office (CIFS)', N'isas central illinois field office cifs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2571';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS Central Illinois Field Office', N'isas central illinois field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2571';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'CIFS', N'cifs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2571';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS NIFS', N'isas nifs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2515';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS South Farms Warehouse (Repository)', N'isas south farms warehouse repository', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0330';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS South Farms Warehouse', N'isas south farms warehouse', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0330';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Repository', N'repository', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0330';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS Springfield Research Office', N'isas springfield research office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2523';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS WIFS FLOT Lab', N'isas wifs flot lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2132';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS WIFS Jacksonville', N'isas wifs jacksonville', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2127';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS WIFS Macomb', N'isas wifs macomb', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2123';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Applied Research Lab (ARL)', N'isgs applied research lab arl', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0128';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Applied Research Lab', N'isgs applied research lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0128';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ARL', N'arl', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0128';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Carbondale (Southern) Field Office', N'isgs carbondale southern field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2579';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Carbondale Field Office', N'isgs carbondale field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2579';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Chicago Field Office', N'isgs chicago field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1480';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Clay Liner Building (Core Storage 2)', N'isgs clay liner building core storage 2', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0305';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Clay Liner Building', N'isgs clay liner building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0305';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Core Storage 2', N'core storage 2', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0305';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Coastal Geology Group', N'isgs coastal geology group', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P013';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Core Description Lab / Print Shop', N'isgs core description lab print shop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0315';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Core Description Lab', N'isgs core description lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0315';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Print Shop', N'print shop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0315';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Core Storage Polebarn (Core Storage 1)', N'isgs core storage polebarn core storage 1', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1039';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Core Storage Polebarn', N'isgs core storage polebarn', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1039';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Core Storage 1', N'core storage 1', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1039';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS ESA Section Office, 201 W Curtis Rd', N'isgs esa section office 201 w curtis rd', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0523';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS ESA Section Office, 203 W Curtis Rd, Suite 1', N'isgs esa section office 203 w curtis rd suite 1', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1115';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS ESA Section Office, 203 W Curtis Rd, Suite 3', N'isgs esa section office 203 w curtis rd suite 3', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1132';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Lake Michigan Field Office', N'isgs lake michigan field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'2586';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Lincoln Avenue East Pole Barn', N'isgs lincoln avenue east pole barn', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1102';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Lincoln Avenue West Pole Barn', N'isgs lincoln avenue west pole barn', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1098';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Lisle (Northern) Field Office', N'isgs lisle northern field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1499';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Lisle Field Office', N'isgs lisle field office', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1499';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Michael A Bilandic Building', N'isgs michael a bilandic building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P014';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Wetlands Section Office at Illini Plaza', N'isgs wetlands section office at illini plaza', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0507';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Wetlands Storage Garage', N'isgs wetlands storage garage', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1501';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISTC (Illinois Sustainable Technology Center)', N'istc illinois sustainable technology center', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0206';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISTC', N'istc', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0206';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Illinois Sustainable Technology Center', N'illinois sustainable technology center', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0206';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Bondville Road Field Station', N'isws bondville road field station', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1522';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 01, Office of the Director', N'isws building 01 office of the director', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1104';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 01', N'isws building 01', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1104';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 02, Labs and IL Room', N'isws building 02 labs and il room', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0289';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 02', N'isws building 02', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0289';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 03, Chemistry and Technology labs', N'isws building 03 chemistry and technology labs', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1106';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 03', N'isws building 03', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1106';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 04, Groundwater Science', N'isws building 04 groundwater science', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1107';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 04', N'isws building 04', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1107';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 05, Water Quality and Surface Water', N'isws building 05 water quality and surface water', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1108';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 05', N'isws building 05', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1108';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 06, Climate and Atmospheric Science', N'isws building 06 climate and atmospheric science', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1109';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 06', N'isws building 06', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1109';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 07, Storage and field staging', N'isws building 07 storage and field staging', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1110';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 07', N'isws building 07', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1110';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 08, Garage and vehicle storage', N'isws building 08 garage and vehicle storage', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1111';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 08', N'isws building 08', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1111';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 09, Chemistry and Technology offices', N'isws building 09 chemistry and technology offices', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1112';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 09', N'isws building 09', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1112';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 10, Field equipment storage', N'isws building 10 field equipment storage', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1026';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 10', N'isws building 10', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1026';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 11, Shipping, Receiving, CHAMP, shops', N'isws building 11 shipping receiving champ shops', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1139';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS Building 11', N'isws building 11', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1139';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS East Peoria Warehouse (Lawrence Building)', N'isws east peoria warehouse lawrence building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1495';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISWS East Peoria Warehouse', N'isws east peoria warehouse', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1495';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Lawrence Building', N'lawrence building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'1495';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Meat Science Lab', N'meat science lab', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P171';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Morrill Hall', N'morrill hall', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P242';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NPL / ISAS Central Offices', N'npl isas central offices', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0110';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NPL', N'npl', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0110';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISAS Central Offices', N'isas central offices', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0110';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRB (Natural Resources Building)', N'nrb natural resources building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0109';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRB', N'nrb', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0109';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Natural Resources Building', N'natural resources building', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0109';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRB Garage / Shop', N'nrb garage shop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0133';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRB Garage', N'nrb garage', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0133';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Shop', N'shop', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0133';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRB Greenhouse', N'nrb greenhouse', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0134';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRSA / Annex / ISGS Main Core Facility', N'nrsa annex isgs main core facility', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0321';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NRSA', N'nrsa', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0321';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Annex', N'annex', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0321';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'ISGS Main Core Facility', N'isgs main core facility', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0321';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NSRC (National Soybean Research Center)', N'nsrc national soybean research center', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0124';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'NSRC', N'nsrc', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0124';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'National Soybean Research Center', N'national soybean research center', 'seed' FROM ref.Buildings WHERE BldgNumber = N'0124';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Off-Site Staff Residence', N'off site staff residence', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P888';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Other', N'other', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P999';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Staff Residence Out-of-State', N'staff residence out of state', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P889';
    INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source)
        SELECT BuildingID, N'Turner Hall', N'turner hall', 'seed' FROM ref.Buildings WHERE BldgNumber = N'P191';
END
GO

-- Occupancy derivable from building names only. Forbes (1073), NRB (0109),
-- and NRSA (0321) house multiple surveys per Section 5.2 but the spec does
-- not enumerate them; they and the unprefixed campus buildings seed no
-- occupant rows and must be completed through Admin.
IF NOT EXISTS (SELECT 1 FROM ref.BuildingOccupants)
BEGIN
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0307' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P006' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0187' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1268' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1279' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2518' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1282' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P012' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1280' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P009' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1048' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1101' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P002' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P003' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P005' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P008' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0313' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0311' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0309' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0325' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1099' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P011' AND s.Code = N'INHS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'TBD' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2563' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0158' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2571' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2515' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0330' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2523' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2132' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2127' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2123' AND s.Code = N'ISAS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0128' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2579' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1480' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0305' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P013' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0315' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1039' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0523' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1115' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1132' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'2586' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1102' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1098' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1499' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'P014' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0507' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1501' AND s.Code = N'ISGS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0206' AND s.Code = N'ISTC';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1522' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1104' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0289' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1106' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1107' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1108' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1109' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1110' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1111' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1112' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1026' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1139' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'1495' AND s.Code = N'ISWS';
    INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary)
        SELECT b.BuildingID, s.SurveyID, 1 FROM ref.Buildings b CROSS JOIN ref.Surveys s
        WHERE b.BldgNumber = N'0110' AND s.Code = N'ISAS';
END
GO

IF NOT EXISTS (SELECT 1 FROM app.Settings)
BEGIN
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'severity_points_1', N'0', N'number', N'Severity 1 points', N'Points for reporter severity 1, minor', 10);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'severity_points_2', N'10', N'number', N'Severity 2 points', N'Points for reporter severity 2, moderate', 20);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'severity_points_3', N'22', N'number', N'Severity 3 points', N'Points for reporter severity 3, serious', 30);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'severity_points_4', N'35', N'number', N'Severity 4 points', N'Points for reporter severity 4, emergency', 40);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'life_safety_points', N'25', N'number', N'Life safety points', N'Added when a reporter flags risk to people', 50);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'specimen_risk_points', N'20', N'number', N'Specimen risk points', N'Added when a reporter flags risk to collections or live animals', 60);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'corroboration_points', N'7', N'number', N'Corroboration points', N'Base value of one confirmation', 70);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'corroboration_scale', N'sqrt', N'text', N'Corroboration curve', N'Curve applied to confirmation count: sqrt, linear, or log', 80);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'corroboration_cap', N'30', N'number', N'Corroboration cap', N'Ceiling on the confirmation component', 90);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'distinct_unit_points', N'4', N'number', N'Distinct unit points', N'Added for each additional survey confirming', 100);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'distinct_unit_cap', N'16', N'number', N'Distinct unit cap', N'Ceiling on the distinct unit component', 110);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'age_points_per_day', N'1.5', N'number', N'Age points per day', N'Automatic escalation per day open', 120);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'age_cap', N'20', N'number', N'Age cap', N'Ceiling on the age component', 130);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'remote_site_points', N'12', N'number', N'Remote site points', N'Standing bump for buildings flagged remote', 140);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'band_elevated', N'20', N'number', N'Elevated band threshold', N'Score at which a request leaves routine', 150);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'band_high', N'40', N'number', N'High band threshold', N'Score at which a request becomes high', 160);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'band_critical', N'60', N'number', N'Critical band threshold', N'Score at which a request becomes critical', 170);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'dup_window_days', N'45', N'number', N'Duplicate window, days', N'How far back the duplicate check looks', 180);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'dup_radius_meters', N'25', N'number', N'Duplicate radius, meters', N'Pin proximity treated as the same location', 190);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'dup_text_threshold', N'0.22', N'number', N'Duplicate text threshold', N'Wording similarity above which a match is offered', 200);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'estimate_approval_threshold', N'5000', N'number', N'Approval threshold, dollars', N'Dollar estimate above which a work request enters approval', 210);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'director_approval_threshold', N'25000', N'number', N'Director threshold, dollars', N'Dollar estimate above which the Director signs', 220);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'ticket_prefix_wr', N'FWO', N'text', N'Work ticket prefix', N'Facilities Work Order prefix; tickets read FWO-FY27-00001', 230);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'ticket_prefix_mv', N'SMO', N'text', N'Move ticket prefix', N'Move Order prefix; tickets read SMO-FY27-00001', 240);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'ticket_prefix_sh', N'SWO', N'text', N'Shop ticket prefix', N'Shop Work Order prefix; tickets read SWO-FY27-00001', 250);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'bypass_confirm_count', N'3', N'number', N'Bypass confirmation count', N'Confirmations that trigger the unit head review bypass, Section 9.1', 260);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'bypass_survey_count', N'2', N'number', N'Bypass survey count', N'Distinct surveys required for the unit head review bypass, Section 9.1', 270);
    INSERT INTO app.Settings (SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder)
        VALUES (N'unit_head_fund_types', N'ICR,GRF', N'text', N'Unit head fund types', N'Funding types whose use for repairs or maintenance opens unit head review (2026-08-06 ruling)', 280);
END
GO

IF OBJECT_ID('ref.WorkTypes', 'U') IS NOT NULL
    EXEC('IF NOT EXISTS (SELECT 1 FROM ref.WorkTypes) BEGIN INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Water, plumbing, drainage'', 12, 2, 2 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Electrical and power'', 10, 2, 3 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''HVAC and environmental control'', 8, 2, 5 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Cold storage and specimen climate'', 14, 3, 1 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Fire, alarm, life safety'', 15, 3, 1 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Eyewash and safety fixtures'', 10, 3, 2 FROM ref.Owners WHERE Code = N''SAFETY''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Structure, envelope, interior'', 8, 2, 7 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Doors, locks, access'', 5, 1, 5 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Card and electronic access'', 5, 2, 3 FROM ref.Owners WHERE Code = N''IT''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Research and collections systems'', 12, 2, 2 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Grounds, roads, exterior'', 3, 1, 14 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Remote site utilities'', 8, 2, 5 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Pest and wildlife'', 4, 1, 7 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Custodial and waste'', 2, 1, 7 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Spills and hazardous materials'', 15, 3, 1 FROM ref.Owners WHERE Code = N''SAFETY''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Elevator, lift, hoist'', 8, 2, 3 FROM ref.Owners WHERE Code = N''FS''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays) SELECT OwnerID, N''Network and telecommunications'', 5, 1, 5 FROM ref.Owners WHERE Code = N''IT''; INSERT INTO ref.WorkTypes (OwnerID, Name, CategoryWeight, SeverityFloor, TargetResponseDays, IsPhoneOnly, PhoneInstruction) SELECT OwnerID, N''Elevator entrapment'', 0, 4, 0, 1, N''Elevator entrapment is an emergency. Do not file a ticket - call 911 now, then notify Facilities after everyone is out.'' FROM ref.Owners WHERE Code = N''FS''; END');
GO

IF OBJECT_ID('ref.Natures', 'U') IS NOT NULL
    EXEC('IF NOT EXISTS (SELECT 1 FROM ref.Natures) BEGIN INSERT INTO ref.Natures (Name) VALUES (N''Repair a failure''); INSERT INTO ref.Natures (Name) VALUES (N''Correct a hazard''); INSERT INTO ref.Natures (Name) VALUES (N''Replace at end of life''); INSERT INTO ref.Natures (Name) VALUES (N''Install or modify''); INSERT INTO ref.Natures (Name) VALUES (N''Relocate or move''); INSERT INTO ref.Natures (Name) VALUES (N''Inspect or certify''); INSERT INTO ref.Natures (Name) VALUES (N''Service or supply''); INSERT INTO ref.Natures (Name) VALUES (N''Event or project support''); END');
GO

IF OBJECT_ID('ref.TermSynonyms', 'U') IS NOT NULL
    EXEC('IF NOT EXISTS (SELECT 1 FROM ref.TermSynonyms) BEGIN INSERT INTO ref.TermSynonyms (GroupKey, Term) VALUES (N''flood'', N''flood''); INSERT INTO ref.TermSynonyms (GroupKey, Term) VALUES (N''flood'', N''flooding''); INSERT INTO ref.TermSynonyms (GroupKey, Term) VALUES (N''flood'', N''standing water''); INSERT INTO ref.TermSynonyms (GroupKey, Term) VALUES (N''flood'', N''water on floor''); END');
GO

IF OBJECT_ID('wr.WorkRequests', 'U') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM app.Counters WHERE CounterName = 'FWO')
    INSERT INTO app.Counters (CounterName, CounterYear, CurrentValue) VALUES ('FWO', CASE WHEN MONTH(SYSDATETIME()) >= 7 THEN YEAR(SYSDATETIME()) + 1 ELSE YEAR(SYSDATETIME()) END, 0);
GO
IF OBJECT_ID('mv.MoveRequests', 'U') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM app.Counters WHERE CounterName = 'SMO')
    INSERT INTO app.Counters (CounterName, CounterYear, CurrentValue) VALUES ('SMO', CASE WHEN MONTH(SYSDATETIME()) >= 7 THEN YEAR(SYSDATETIME()) + 1 ELSE YEAR(SYSDATETIME()) END, 0);
GO
IF OBJECT_ID('sh.ShopRequests', 'U') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM app.Counters WHERE CounterName = 'SWO')
    INSERT INTO app.Counters (CounterName, CounterYear, CurrentValue) VALUES ('SWO', CASE WHEN MONTH(SYSDATETIME()) >= 7 THEN YEAR(SYSDATETIME()) + 1 ELSE YEAR(SYSDATETIME()) END, 0);
GO
