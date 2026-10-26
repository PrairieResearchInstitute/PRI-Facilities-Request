<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Notifications. Recipient lists are configuration held in app.Settings
 * and edited on the admin screen, never on a public form.
 *
 * Every notification is written to app.Notifications whether or not the
 * host can send mail, so the record exists on shared hosting with no mail
 * transport and an administrator can see exactly what went where. The
 * notify_mode setting decides whether delivery is attempted:
 *   log  - record only, nothing leaves the server (default)
 *   mail - record and hand to the host mail transport
 */

function notify_recipients(string $settingKey, array $extra = []): array
{
    $raw = (string)setting($settingKey, '');
    $list = array_merge(preg_split('/[,;\s]+/', $raw) ?: [], $extra);
    return notify_clean($list);
}

/** Deduplicate, validate, and keep the first spelling of each address. */
function notify_clean(array $list): array
{
    $out = [];
    foreach ($list as $addr) {
        $addr = trim((string)$addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($addr)] = $addr;
        }
    }
    return array_values($out);
}

/**
 * Everyone who should hear about one request. Three sources merge:
 * standing rules for the event, rules whose condition the request meets,
 * and the director and proxy of the survey it belongs to.
 *
 * $context understands: survey, work_type, owner, request_kind, severity,
 * building, life_safety, specimen_risk.
 */
function notify_audience(string $eventCode, array $context = [], array $extra = []): array
{
    $addresses = $extra;
    $why = [];

    try {
        $stmt = db('work')->prepare(
            'SELECT * FROM app.NotifyRules
             WHERE IsActive = 1 AND EventCode IN (?, ?)');
        $stmt->execute([$eventCode, 'any']);
        $rules = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Rules table not present yet: fall back to the old lists.
        return notify_recipients(notify_legacy_key($eventCode), $extra);
    }

    foreach ($rules as $rule) {
        if (!notify_rule_matches($rule, $context)) {
            continue;
        }
        $addresses[] = $rule['Email'];
        $why[strtolower((string)$rule['Email'])] =
            $rule['MatchType'] === 'always'
                ? 'standing recipient'
                : trim((string)$rule['MatchType'] . ' ' . (string)$rule['MatchValue']);
    }

    // The survey director and their proxy, for the unit that owns the work.
    $survey = strtoupper(trim((string)($context['survey'] ?? '')));
    if ($survey !== '') {
        try {
            $s = db('work')->prepare(
                'SELECT DirectorName, DirectorEmail, ProxyName, ProxyEmail, NotifyDirector
                 FROM ref.Surveys WHERE Code = ?');
            $s->execute([$survey]);
            $row = $s->fetch();
            if ($row && !empty($row['NotifyDirector'])) {
                foreach (['DirectorEmail', 'ProxyEmail'] as $col) {
                    if (!empty($row[$col])) {
                        $addresses[] = $row[$col];
                        $why[strtolower((string)$row[$col])] =
                            $survey . ($col === 'DirectorEmail' ? ' director' : ' director proxy');
                    }
                }
            }
        } catch (Throwable $e) { /* columns not present yet */ }
    }

    $clean = notify_clean($addresses);
    // Remember why each person was included, for the outbox.
    $GLOBALS['notify_last_reasons'] = array_intersect_key($why,
        array_flip(array_map('strtolower', $clean)));
    return $clean;
}

function notify_rule_matches(array $rule, array $context): bool
{
    $type = (string)$rule['MatchType'];
    $value = trim((string)$rule['MatchValue']);

    switch ($type) {
        case 'always':
            return true;
        case 'severity_min':
            return (int)($context['severity'] ?? 0) >= (int)$value;
        case 'life_safety':
            return !empty($context['life_safety']);
        case 'specimen_risk':
            return !empty($context['specimen_risk']);
        case 'survey':
            return strcasecmp((string)($context['survey'] ?? ''), $value) === 0;
        case 'work_type':
            return stripos((string)($context['work_type'] ?? ''), $value) !== false;
        case 'owner':
            return strcasecmp((string)($context['owner'] ?? ''), $value) === 0;
        case 'request_kind':
            return strcasecmp((string)($context['request_kind'] ?? ''), $value) === 0;
        case 'building':
            return strcasecmp((string)($context['building'] ?? ''), $value) === 0;
    }
    return false;
}

function notify_legacy_key(string $eventCode): string
{
    return [
        'work_request_created' => 'notify_work',
        'move_request_created' => 'notify_move',
        'shop_request_created' => 'notify_shop',
    ][$eventCode] ?? 'notify_work';
}

/**
 * Record, and optionally send, one notification in the given database.
 */
function notify_send(string $dbName, string $eventCode, array $recipients,
                     string $subject, string $body,
                     ?int $requestId = null, ?string $ticket = null): void
{
    if (!$recipients) {
        return;
    }

    /* A recipient arrives either as a bare address or as a row carrying
       its address, name and the reason it was included. Both shapes are
       in use across the app, so they are flattened here, once, before
       anything tries to put one in an SMTP envelope - which is what a
       row shape reaching smtp_send() would have done: RCPT TO:<Array>,
       every message failing, and the reason buried in a log.

       The reason travels alongside rather than inside, so the transport
       only ever sees addresses. */
    $addrs = [];
    $why = [];
    foreach ($recipients as $one) {
        $addr = trim(is_array($one) ? (string)($one['email'] ?? '') : (string)$one);
        if ($addr === '' || in_array($addr, $addrs, true)) {
            continue;
        }
        $addrs[] = $addr;
        if (is_array($one) && trim((string)($one['why'] ?? '')) !== '') {
            $why[strtolower($addr)] = trim((string)$one['why']);
        }
    }
    if (!$addrs) {
        return;
    }

    $mode = (string)setting('notify_mode', 'log');
    $status = 'logged';
    $error = null;
    $sentAt = null;

    if ($mode === 'smtp') {
        // Exchange and Outlook: talk SMTP directly rather than relying on
        // a local mail transport the shared host may not have.
        require_once __DIR__ . '/smtp.php';
        $log = [];
        $ok = false;
        try {
            $ok = smtp_send($addrs, $subject, $body, $log);
        } catch (Throwable $e) {
            $log[] = '! ' . $e->getMessage();
        }
        $status = $ok ? 'sent' : 'failed';
        $sentAt = $ok ? date('Y-m-d H:i:s') : null;
        if (!$ok) {
            $error = mb_substr(implode(' | ', array_slice($log, -6)), 0, 600);
        }
    } elseif ($mode === 'mail') {
        $from = (string)setting('notify_from', 'configure-me@example.edu');
        $headers = 'From: ' . $from . "\r\n"
                 . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $ok = false;
        try {
            $ok = @mail(implode(', ', $addrs), $subject, $body, $headers);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $status = $ok ? 'sent' : 'failed';
        $sentAt = $ok ? date('Y-m-d H:i:s') : null;
        if (!$ok && $error === null) {
            $error = 'The host mail transport rejected the message or is not configured.';
        }
    }

    // Record why each person was on the list, so a question about it a
    // year later has an answer.
    //
    // A recipient arrives either as a bare address or as a row carrying
    // its address, name and the reason it was included. Both shapes are
    // in use, and the row shape carries its own reason - which is better
    // than the one in the global, because the global belongs to whichever
    // audience was built last and that may not be this one.
    $reasons = $GLOBALS['notify_last_reasons'] ?? [];
    $detail = $error;
    $parts = [];
    foreach ($addrs as $addr) {
        // The row's own reason first: it belongs to this message, whereas
        // the global belongs to whichever audience was built last, which
        // may have been for something else entirely.
        $r = $why[strtolower($addr)] ?? (string)($reasons[strtolower($addr)] ?? '');
        $parts[] = $addr . ($r !== '' ? ' (' . $r . ')' : '');
    }
    $body .= "\n\n---\nRecipients: " . implode('; ', $parts);

    db($dbName)->prepare(
        'INSERT INTO app.Notifications (RequestID, TicketNumber, EventCode, Recipients,
            Subject, Body, Status, ErrorText, SentAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$requestId, $ticket, $eventCode, implode(', ', $addrs),
                $subject, $body, $status, $detail, $sentAt]);
}

/**
 * The recipients as one readable line. They arrive as rows carrying an
 * address, a name and the reason they were included, so imploding the
 * list itself would record "Array, Array".
 */
function notify_recipient_list(array $recipients): string
{
    $out = [];
    foreach ($recipients as $r) {
        $out[] = is_array($r) ? (string)($r['email'] ?? '') : (string)$r;
    }
    return implode(', ', array_filter($out));
}

/**
 * Shop request notice, worded after the notice the current shop system
 * sends, minus the reply-all instruction: decisions are recorded on the
 * approval chain now rather than gathered from replies.
 */
function notify_shop_request(array $req, array $vehicle = null): void
{
    $extra = [];
    foreach (['AccountPIEmail', 'FundManagerEmail'] as $k) {
        if (!empty($req[$k])) {
            $extra[] = $req[$k];
        }
    }
    $to = notify_audience('shop_request_created', [
        'survey'       => $req['SurveyCode'] ?? '',
        'request_kind' => $vehicle ? 'vehicle' : 'general',
        'building'     => $req['BldgNumber'] ?? '',
    ], $extra);
    $subject = 'PRIOps: ' . $req['TicketNumber'] . ' needs a decision';

    $lines = [
        'Shop Request Received',
        '',
        'Work Order Number: ' . $req['TicketNumber'],
        'Requested By: ' . $req['RequestedByName'] . ' (' . $req['RequestedByNetID'] . ')',
        'Requested On: ' . date('n/j/Y'),
        'Requested Completion Date: ' . $req['NeededByDate'],
        'Account: ' . $req['CfopCode'],
        'Account PI: ' . $req['AccountPI'],
        'Fund Manager: ' . $req['FundManager'],
    ];
    if ($vehicle) {
        $lines[] = 'Vehicle: ' . $vehicle['FleetNumber'] . ' ' . $vehicle['Description']
                 . ($vehicle['HomeBldgNumber'] ? ' (building ' . $vehicle['HomeBldgNumber'] . ')' : '');
    }
    $lines[] = '';
    $lines[] = 'Work Requested:';
    $lines[] = $req['WorkDescription'];
    $lines[] = '';
    $lines[] = 'Business Justification:';
    $lines[] = $req['BusinessJustification'];
    $lines[] = '';
    $lines[] = 'Account PI and Fund Manager: record your decision on the request rather than by '
             . 'replying to this message. Open it here:';
    $lines[] = notify_base_url() . 'detail.php?type=shop&id=' . (int)$req['ShopRequestID'];

    notify_send('shop', 'shop_request_created', $to, $subject, implode("\n", $lines),
                (int)$req['ShopRequestID'], $req['TicketNumber']);
}

function notify_work_request(array $req): void
{
    $urgent = ((int)$req['ReportedSeverity'] >= 3) || !empty($req['LifeSafety']);
    $to = notify_audience('work_request_created', [
        'survey'        => $req['SurveyCode'] ?? '',
        'work_type'     => $req['TypeName'] ?? '',
        'owner'         => $req['OwnerCode'] ?? '',
        'building'      => $req['BldgNumber'] ?? '',
        'severity'      => (int)$req['ReportedSeverity'],
        'life_safety'   => !empty($req['LifeSafety']),
        'specimen_risk' => !empty($req['SpecimenRisk']),
    ]);
    $subject = 'PRIOps: ' . ($urgent ? 'Urgent, ' : '') . $req['TicketNumber']
             . ' submitted, ' . $req['Title'];

    $lines = [
        'Facilities Work Request Received',
        '',
        'Work Order Number: ' . $req['TicketNumber'],
        'Requested By: ' . $req['RequestedByName'] . ' (' . $req['CreatedByNetID'] . ')',
        'Requested On: ' . date('n/j/Y'),
        'Building: ' . $req['BuildingName'] . ' (' . $req['BldgNumber'] . ')',
        'Location: ' . trim(($req['RoomNumber'] ? 'Room ' . $req['RoomNumber'] . ' ' : '')
                          . (string)$req['WrittenLocation']),
        'Work Type: ' . $req['TypeName'],
        'Reported Severity: ' . $req['ReportedSeverity'],
    ];
    if (!empty($req['LifeSafety'])) {
        $lines[] = 'FLAGGED: risk to people';
    }
    if (!empty($req['SpecimenRisk'])) {
        $lines[] = 'FLAGGED: risk to collections, specimens, or live animals';
    }
    $lines[] = '';
    $lines[] = 'Problem Reported:';
    $lines[] = $req['Description'];
    $lines[] = '';
    $lines[] = notify_base_url() . 'detail.php?type=work&id=' . (int)$req['WorkRequestID'];

    notify_send('work', 'work_request_created', $to, $subject, implode("\n", $lines),
                (int)$req['WorkRequestID'], $req['TicketNumber']);
}

function notify_move_request(array $req): void
{
    $to = notify_audience('move_request_created', [
        'survey'   => $req['SurveyCode'] ?? '',
        'building' => $req['BldgNumber'] ?? '',
    ]);
    $subject = 'PRIOps: ' . $req['TicketNumber'] . ' submitted';
    $lines = [
        'Move Request Received',
        '',
        'Work Order Number: ' . $req['TicketNumber'],
        'Requested By: ' . $req['RequestedByName'] . ' (' . $req['RequestedByNetID'] . ')',
        'Requested Completion Date: ' . $req['NeededByDate'],
        'Items: ' . $req['ItemCount'],
        'Technology items included: ' . (!empty($req['HasTechItems']) ? 'yes, IT review first' : 'no'),
        '',
        notify_base_url() . 'detail.php?type=move&id=' . (int)$req['MoveRequestID'],
    ];
    notify_send('move', 'move_request_created', $to, $subject, implode("\n", $lines),
                (int)$req['MoveRequestID'], $req['TicketNumber']);
}

function notify_base_url(): string
{
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $dir . '/';
}
