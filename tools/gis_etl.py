"""
PRI Facilities Request System - GIS and C3PO extract.

Flattens the facilities geodatabase and the C3PO exports into a small set
of CSV files under storage/gis, which the application then loads into SQL
Server. Rerunnable: it reads source data and writes extracts, and never
writes to the databases itself.

    py -3 tools\\gis_etl.py

Sources
    gis\\Facilities\\...\\PRI_Facilities_2023_02_02.gdb   floor plans and space data
    C3PO Data\\C3poAssignedSpaceReport*.csv               who sits where
    C3PO Data\\C3poInventoryPage*.csv                     tagged property
    C3PO Data\\C3PO*.csv                                  employee roster

Notes
    Room codes are BUILDING_FLOOR_ROOM, zero padded, e.g. 0109_01_0189.
    The Rooms layer stores BLDG_ID unpadded ("109"), so every building
    identifier is normalised to the four digit form used everywhere else
    in this application.
    Geometry is NAD83 Illinois East, US survey feet. Paths are written in
    those feet with the Y axis negated so an SVG viewBox renders upright.
"""

import csv
import glob
import os
import re
import sys
import warnings

warnings.filterwarnings("ignore")

try:
    import pyogrio
except ImportError:
    sys.exit("pyogrio is required:  py -3 -m pip install pyogrio geopandas")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GDB = os.path.join(ROOT, "reference", "gis", "Facilities", "Facilities_GIS_Version01_2023_03_26",
                   "PRI_Facilities_2023_02_02.gdb")
C3PO = os.path.join(ROOT, "reference", "C3PO Data")


def _data_root():
    """config.local.json dataFolder (relative to the app folder), as
    facilities/lib/paths.php resolves it; the app folder when absent."""
    import json
    cfg = os.path.join(ROOT, "config.local.json")
    folder = ""
    if os.path.isfile(cfg):
        with open(cfg, encoding="utf-8-sig") as fh:
            folder = (json.load(fh).get("dataFolder") or "").strip()
    if not folder:
        return ROOT
    path = os.path.normpath(os.path.join(ROOT, folder.replace("\\", os.sep)))
    if not os.path.isdir(path):
        sys.exit("The data folder named in config.local.json does not exist: " + path)
    return path


OUT = os.path.join(_data_root(), "storage", "gis")

# The March 2023 generation supersedes the earlier near-duplicates.
L_ROOMS = "Rooms_area_2023_02_02"
L_WALLS = "Walls_line_2023_02_02"
L_DOORS = "Doors_line_2023_02_02"
L_FOOT = "Footprint_geographic_area_2023_02_02"
L_BLDGS = "All_PRI_Buildings_v06_2023_02_02_point"
L_NAME = "ROOM_NAME_2023_03_23"
L_CAT = "ROOM_CATEGORY_2023_03_23"
L_USE = "ROOM_USE_2023_03_23"
L_ARCH = "All_PRI_ARCHIBUS_2023_03_22b"
L_ORG = "Org_RoomCode_2023_02_08"
L_KEYS = "keys_2022_01_14c"
L_PEOPLE = "All_PRI_People_Archibus_2023_02_05"
L_AWA = "AWA_with_ROOM_CODE_2023_03_27"
L_COLOR = "CATEGORY_color_key_v2_2023_02_22"
L_COLORGEN = "CATEGORY_color_key_v2_GENERAL_2023_03_23"
L_FH = "FH_2023_02_14_v3"
L_ES = "ES_Loc_2023_02_14_v1"


def norm_bldg(value) -> str:
    """0109 style. Numeric identifiers pad to four digits; P codes stay."""
    s = ("" if value is None else str(value)).strip()
    if s == "" or s.lower() == "nan":
        return ""
    if s.isdigit():
        return s.zfill(4)
    return s.upper()


def norm_room(value) -> str:
    """Comparable room key: drop leading zeros and upper case."""
    s = ("" if value is None else str(value)).strip().upper()
    if s in ("", "NAN"):
        return ""
    s = re.sub(r"\s+", "", s)
    return s.lstrip("0") or "0"


def room_code(bldg, floor, room) -> str:
    b = norm_bldg(bldg)
    f = ("" if floor is None else str(floor)).strip()
    r = ("" if room is None else str(room)).strip()
    if not (b and f and r):
        return ""
    return f"{b}_{f}_{r}"


def read(layer, geometry=False):
    return pyogrio.read_dataframe(GDB, layer=layer, read_geometry=geometry)


def s(value) -> str:
    """Cell to clean string; the sources use several spellings of blank."""
    if value is None:
        return ""
    t = str(value).strip()
    return "" if t.lower() in ("nan", "none", "<na>", "0000aa") else t


def ring_path(coords) -> str:
    """One polygon ring as an SVG path, Y negated so it renders upright."""
    pts = [f"{x:.1f} {-y:.1f}" for x, y in coords]
    if not pts:
        return ""
    return "M" + pts[0] + "L" + "L".join(pts[1:]) + "Z"


def geom_path(geom) -> str:
    """Polygon or multipolygon to a single SVG path, exterior rings only."""
    if geom is None or geom.is_empty:
        return ""
    parts = []
    polys = geom.geoms if geom.geom_type == "MultiPolygon" else [geom]
    for poly in polys:
        parts.append(ring_path(list(poly.exterior.coords)))
        for hole in poly.interiors:
            parts.append(ring_path(list(hole.coords)))
    return "".join(p for p in parts if p)


def line_path(geom) -> str:
    if geom is None or geom.is_empty:
        return ""
    parts = []
    lines = geom.geoms if geom.geom_type == "MultiLineString" else [geom]
    for line in lines:
        pts = [f"{x:.1f} {-y:.1f}" for x, y in line.coords]
        if len(pts) > 1:
            parts.append("M" + pts[0] + "L" + "L".join(pts[1:]))
    return "".join(parts)


def write_csv(name, header, rows):
    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, name)
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(header)
        w.writerows(rows)
    print(f"  {name:<24} {len(rows):>6} rows")
    return path


def newest(pattern):
    hits = sorted(glob.glob(os.path.join(C3PO, pattern)))
    return hits[-1] if hits else None


def load_c3po(pattern):
    """
    Rows as dictionaries. The assigned space report carries two columns
    both headed "Name" (the person, then their NetID), so repeated headers
    are suffixed rather than allowed to overwrite each other.
    """
    path = newest(pattern)
    if not path:
        print(f"  (no file matching {pattern})")
        return []
    with open(path, encoding="utf-8-sig", newline="") as fh:
        reader = csv.reader(fh)
        try:
            header = next(reader)
        except StopIteration:
            return []
        seen, cols = {}, []
        for name in header:
            name = (name or "").strip()
            if name in seen:
                seen[name] += 1
                cols.append(f"{name}.{seen[name]}")
            else:
                seen[name] = 0
                cols.append(name)
        return [dict(zip(cols, row)) for row in reader]


def main():
    print("Reading the geodatabase ...")
    rooms = read(L_ROOMS, geometry=True)
    names = read(L_NAME).set_index("ROOM_CODE", drop=False)
    cats = read(L_CAT).set_index("ROOM_CODE", drop=False)
    uses = read(L_USE)
    # ROOM_USE holds one row per space share; keep the largest share per room.
    uses = uses.sort_values("Space_Perc", ascending=False).drop_duplicates("ROOM_CODE")
    uses = uses.set_index("ROOM_CODE", drop=False)

    # Everything else that carries exactly one row per room folds into the
    # room table, so one query answers a question about a room.
    arch = read(L_ARCH).drop_duplicates("ROOM_CODE").set_index("ROOM_CODE", drop=False)
    org = read(L_ORG).drop_duplicates("ROOM_CODE").set_index("ROOM_CODE", drop=False)
    keys_tbl = read(L_KEYS)
    keys_tbl = keys_tbl[keys_tbl["Room_Code"].notna()].drop_duplicates("Room_Code")
    keys_tbl = keys_tbl.set_index("Room_Code", drop=False)

    def attr(frame, code, column):
        try:
            row = frame.loc[code]
        except (KeyError, TypeError):
            return ""
        if hasattr(row, "iloc") and getattr(row, "ndim", 1) > 1:
            row = row.iloc[0]
        return s(row.get(column))

    # ---- rooms, the combined table ----
    # A room occasionally exists as more than one polygon (an L shape split
    # by the drawing, or a room mapped twice). Those merge into one row
    # carrying every part, because the room is one room to everyone using
    # this application.
    merged = {}
    room_rows = []
    floors = {}
    for _, r in rooms.iterrows():
        bldg = norm_bldg(r.BLDG_ID)
        floor = s(r.FLOOR)
        rid = s(r.ROOM_ID)
        code = s(r.ROOM_CODE) or room_code(bldg, floor, rid)
        if not code:
            continue
        path = geom_path(r.geometry)
        if not path:
            continue
        cx, cy = (r.geometry.centroid.x, -r.geometry.centroid.y)
        minx, miny, maxx, maxy = r.geometry.bounds
        area = float(r.Area or 0)
        if code in merged:
            row = merged[code]
            row[16] += path                      # another part of the same room
            prev = float(row[13])
            row[13] = f"{prev + area:.1f}"
            if area > prev:                      # label the largest part
                row[14], row[15] = f"{cx:.1f}", f"{cy:.1f}"
        else:
            merged[code] = [
                code, bldg, floor, rid,
                attr(names, code, "Room_Name"),
                attr(names, code, "Collection_Type"),
                attr(cats, code, "Cat_01"),
                attr(cats, code, "Desc_01"),
                attr(uses, code, "Use_03"),
                attr(uses, code, "Desc_03"),
                attr(uses, code, "Department"),
                attr(uses, code, "Division"),
                attr(uses, code, "Division_Name"),
                f"{area:.1f}",
                f"{cx:.1f}", f"{cy:.1f}", path,
                # Folded in from the remaining one-row-per-room tables.
                attr(arch, code, "Other_Description"),
                attr(arch, code, "DRS_Inspect"),
                attr(arch, code, "Coll_Rprt"),
                attr(arch, code, "Cat_02"),
                attr(arch, code, "Desc_02"),
                attr(arch, code, "Space_Perc"),
                attr(arch, code, "Split"),
                attr(org, code, "Org_2A"),          # survey: INHS, ISGS ...
                attr(org, code, "Org_1"),
                attr(org, code, "Org_1A"),
                attr(org, code, "Org_3A"),
                attr(org, code, "Org_4A"),
                attr(keys_tbl, code, "KEY_CODE_ROOM"),
                attr(keys_tbl, code, "KEY_CODE_BUILDING"),
            ]
            room_rows.append(merged[code])
        key = (bldg, floor)
        b = floors.get(key)
        floors[key] = b = b or [minx, -maxy, maxx, -miny]
        b[0] = min(b[0], minx)
        b[1] = min(b[1], -maxy)
        b[2] = max(b[2], maxx)
        b[3] = max(b[3], -miny)

    print("Combining room attributes ...")
    write_csv("rooms.csv",
              ["RoomCode", "BldgNumber", "FloorCode", "RoomID", "RoomName", "CollectionType",
               "CategoryCode", "CategoryDesc", "UseCode", "UseDesc", "Department", "Division",
               "DivisionName", "AreaSqFt", "CentroidX", "CentroidY", "PathData",
               "OtherDescription", "DrsInspect", "CollectionReport", "Category02", "Desc02",
               "SpacePercent", "SplitSpace", "SurveyCode", "Org1", "Org1A", "Org3A", "Org4A",
               "KeyCodeRoom", "KeyCodeBuilding"],
              room_rows)

    # ---- walls, one path per building and floor, drawn behind the rooms ----
    walls = read(L_WALLS, geometry=True)
    by_floor = {}
    for _, w in walls.iterrows():
        key = (norm_bldg(w.Building), s(w.Floor))
        if not key[0] or not key[1]:
            continue
        p = line_path(w.geometry)
        if p:
            by_floor.setdefault(key, []).append(p)
    doors = read(L_DOORS, geometry=True)
    door_by_floor = {}
    for _, dr in doors.iterrows():
        key = (norm_bldg(dr.Building), s(dr.Floor))
        if not key[0] or not key[1]:
            continue
        p = line_path(dr.geometry)
        if p:
            door_by_floor.setdefault(key, []).append(p)

    floor_rows = []
    for (bldg, floor), box in sorted(floors.items()):
        floor_rows.append([
            bldg, floor,
            f"{box[0]:.1f}", f"{box[1]:.1f}", f"{box[2]:.1f}", f"{box[3]:.1f}",
            "".join(by_floor.get((bldg, floor), []))[:1000000],
            "".join(door_by_floor.get((bldg, floor), []))[:400000],
        ])
    write_csv("floors.csv",
              ["BldgNumber", "FloorCode", "MinX", "MinY", "MaxX", "MaxY", "WallPath", "DoorPath"],
              floor_rows)

    # ---- building outlines, for a campus level view ----
    foot = read(L_FOOT, geometry=True)
    foot_rows = []
    for _, f in foot.iterrows():
        bldg = norm_bldg(f.Building)
        if not bldg or f.geometry is None or f.geometry.is_empty:
            continue
        minx, miny, maxx, maxy = f.geometry.bounds
        foot_rows.append([bldg, f"{minx:.1f}", f"{-maxy:.1f}", f"{maxx:.1f}", f"{-miny:.1f}",
                          geom_path(f.geometry)])
    write_csv("footprints.csv",
              ["BldgNumber", "MinX", "MinY", "MaxX", "MaxY", "PathData"], foot_rows)

    # ---- the published space category colour key ----
    colour_rows = []
    for scope, layer, code_col, name_col in (
        ("detail", L_COLOR, "Category01", "Category02"),
        ("general", L_COLORGEN, "Category03", "Category04"),
    ):
        for _, c in read(layer).iterrows():
            code = s(c[code_col])
            if not code:
                continue
            colour_rows.append([scope, code, s(c[name_col]),
                                str(int(c.red or 0)), str(int(c.green or 0)), str(int(c.blue or 0))])
    write_csv("category_colors.csv",
              ["Scope", "CategoryCode", "CategoryName", "Red", "Green", "Blue"], colour_rows)

    # ---- buildings, address and coordinates ----
    bl = read(L_BLDGS)
    bldg_rows = []
    for _, b in bl.iterrows():
        bldg_rows.append([
            norm_bldg(b.Building), s(b.Name_1), s(b.Name_2), s(b.Location),
            " ".join(x for x in [s(b.Address1), s(b.Address2)] if x),
            s(b.Address3), s(b.Address4), s(b.Address5),
            s(b.X_WKID4326), s(b.Y_WKID4326),
        ])
    write_csv("buildings.csv",
              ["BldgNumber", "Name1", "Name2", "Location", "AddressLine", "City", "State",
               "PostalCode", "Longitude", "Latitude"],
              bldg_rows)

    # ---- safety equipment: fume hoods and eyewash or shower stations ----
    safety = []
    fh = read(L_FH)
    for _, f in fh.iterrows():
        code = s(f.ROOM_CODE) or room_code(f.BLDG_ID_2, f.Floor, f.Room_ID)
        safety.append([code, norm_bldg(f.BLDG_ID_2), s(f.Room), "fume_hood",
                       s(f.Hood_Code), "", "", "", s(f.Location), "", ""])
    es = read(L_ES)
    for _, e in es.iterrows():
        code = s(e.ROOM_CODE) if "ROOM_CODE" in es.columns else ""
        kind = "eyewash" if "eye" in s(e.Type).lower() else (
            "safety_shower" if "shower" in s(e.Type).lower() else "safety_station")
        safety.append([code, norm_bldg(e.BLDG_ID), s(e.Room), kind, s(e.Tag), s(e.Brand),
                       s(e.Model), s(e.SN), s(e.Desc_), s(e.Status), s(e.Last_Test)])
    write_csv("safety.csv",
              ["RoomCode", "BldgNumber", "RoomID", "Kind", "Tag", "Brand", "Model",
               "SerialNumber", "Descriptor", "Status", "LastTested"],
              safety)

    # ---- C3PO: who sits where ----
    print("Reading the C3PO exports ...")
    occ_rows = []
    for row in load_c3po("C3poAssignedSpaceReport*.csv"):
        code = s(row.get("Room Code")) or room_code(row.get("Building #"), row.get("Floor"),
                                                    row.get("Room"))
        occ_rows.append([
            code, norm_bldg(row.get("Building #")), s(row.get("Floor")), s(row.get("Room")),
            s(row.get("Name")), s(row.get("Name.1")), s(row.get("UIN")), s(row.get("Org")),
            s(row.get("Emp Type")), s(row.get("Primary Office")), s(row.get("Confirmed")),
            s(row.get("Notes")),
        ])
    write_csv("occupants.csv",
              ["RoomCode", "BldgNumber", "FloorCode", "RoomID", "PersonName", "NetID", "UIN",
               "Org", "EmpType", "PrimaryOffice", "ConfirmedOn", "Notes"],
              occ_rows)

    # Roster, for people whose org matters but who hold no assigned room.
    roster = []
    for row in load_c3po("C3PO2*.csv"):
        roster.append([
            s(row.get("Netid")), s(row.get("Employee")), s(row.get("Org")), s(row.get("UIN")),
            s(row.get("Building")), s(row.get("Room")), s(row.get("Position")),
            s(row.get("Position Type")), s(row.get("Field Station/Off-Campus Office")),
        ])
    write_csv("roster.csv",
              ["NetID", "PersonName", "Org", "UIN", "BuildingCommonName", "RoomID",
               "Position", "PositionType", "FieldStation"],
              roster)

    # ---- C3PO: tagged property. Location reads "0109 00000279 Building Name". ----
    room_index = {}
    for r in room_rows:
        room_index.setdefault((r[1], norm_room(r[3])), r[0])

    inv_rows = []
    unmatched = 0
    for row in load_c3po("C3poInventoryPage*.csv"):
        loc = s(row.get("Location"))
        parts = loc.split()
        bldg = norm_bldg(parts[0]) if parts else ""
        banner_room = parts[1] if len(parts) > 1 else ""
        code = room_index.get((bldg, norm_room(banner_room)), "")
        if not code:
            unmatched += 1
        money = lambda v: re.sub(r"[^0-9.\-]", "", s(v)) or ""
        inv_rows.append([
            code, bldg, s(banner_room), s(row.get("Property Tag (PTag) #")),
            s(row.get("Description")), s(row.get("Model #")), s(row.get("Serial #")),
            s(row.get("Person")), s(row.get("Acq. Date")), money(row.get("Cost")),
            money(row.get("Computed Depreciated Value")),
            s(row.get("Original Funding Source"))[:200], s(row.get("Banner Location Code")),
        ])
    write_csv("inventory.csv",
              ["RoomCode", "BldgNumber", "BannerRoom", "PropertyTag", "Description", "Model",
               "SerialNumber", "Person", "AcquiredOn", "Cost", "DepreciatedValue",
               "FundingSource", "BannerLocationCode"],
              inv_rows)
    if unmatched:
        print(f"  note: {unmatched} inventory rows carry a location with no matching room "
              f"polygon (off-site or unmapped space); they load with the building only.")

    print(f"\nExtracts written to {OUT}")
    print("Load them from Admin -> Building map -> Load GIS and C3PO data.")


if __name__ == "__main__":
    main()
