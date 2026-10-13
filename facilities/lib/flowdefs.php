<?php
declare(strict_types=1);

/**
 * The flowchart definitions, shared by the live diagram page and the
 * briefing deck generator, which draws them as native shapes.
 */

const FLOWS = [
    'work' => [[
        ['File', 'anyone with a NetID', null],
        ['?Duplicate?', 'checked against open tickets', 'challenged first, filed with a note'],
        ['Triage', 'severity and cost, from Settings', null],
        ['Assign', 'or hand to F&S, keep their ref', null],
        ['Close', 'submitter notified', null],
    ], 'Work · wr.WorkRequests'],
    'move' => [[
        ['File', 'from-room and to-room first', null],
        ['Items', 'prefilled from the room', null],
        ['Schedule', 'facilities pick the day', null],
        ['Complete', 'item locations update', null],
    ], 'Move · mv.MoveItems'],
    'space' => [[
        ['File', 'holder or their assistant', null],
        ['Committee', 'survey space committee', 'returned with questions'],
        ['Director', 'the final say', 'denied, with the reason'],
        ['Assign', 'room assignment register', null],
        ['Occupy', 'PI, safety contact, occupants', null],
    ], 'Space · gis.RoomResponsibility'],
    'key' => [[
        ['File', 'person, room, reason', null],
        ['Decide', 'director or proxy, once', 'denied, with the reason'],
        ['Issue', 'key office, back end', null],
        ['Hold', 'on the register, by name', null],
        ['Return', 'or lost - audited yearly', null],
    ], 'Keys · gis.RoomKeys'],
    'purchase' => [[
        ['File', 'one-page data sheet', null],
        ['Approve', 'a grid, on the record', null],
        ['Buy', 'P-Card or purchase order', null],
        ['Reconcile', 'Emburse block, to the cent', null],
        ['Tag', 'arrival closes the expectation', null],
    ], 'Equipment · gis.Inventory'],
    'incident' => [[
        ['Report', 'four questions, anyone', null],
        ['Notify', 'safety office, instantly', null],
        ['Grade', 'severity, their call', null],
        ['Actions', 'owners and due dates', 'cannot close while one is open'],
        ['Close', 'written findings only', null],
    ], 'Incidents · rq.Incidents'],
    'plan' => [[
        ['Register', 'due date computed from kind', null],
        ['Cover', 'rooms, by room code', null],
        ['Review', 'a dated line, never an edit', 'withdrawn or replaced'],
        ['Next due', 'the interval restarts', null],
    ], 'Plans · rq.SafetyPlans'],
    'mileage' => [[
        ['Sticker', 'printed QR, drawn in-house', null],
        ['Scan', 'any phone camera', null],
        ['?Photo?', 'of the dashboard', 'or type it in - always allowed'],
        ['Read', 'on-device, declines if unsure', null],
        ['Record', 'odd readings flagged', null],
    ], 'Fleet · sh.VehicleReadings'],
    'estate' => [[
        ['Record', 'the agreement on the building', null],
        ['Dates', 'start, end, review window', null],
        ['Watch', 'warned 180 days out', null],
        ['Renew', 'or expire on the record', null],
    ], 'Space · est.Agreements'],
    'dataload' => [[
        ['Pull', 'a self-service export', null],
        ['Load', 'staged, reversible', null],
        ['Commit', 'explicit, on purpose', null],
        ['Publish', 'ref.* into all ten', null],
        ['Prove', 'source audit, every column', null],
    ], 'the register each file feeds'],
    'launch' => [[
        ['Workbook', 'regenerated fresh', null],
        ['Assign', 'names against tabs', null],
        ['Fix', 'done rows disappear', null],
        ['Re-run', 'the audit recomputes', null],
        ['?Blockers zero?', 'then launch', 'not yet - loop, smaller each time'],
    ], 'launch'],
];
