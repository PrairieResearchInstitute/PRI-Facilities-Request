<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/picklists.php';

/**
 * Who to go to in each unit, for each subject.
 *
 * A person asking about space at INHS should be sent to Rachel Vinsel,
 * and a person asking the same question at ISWS should be sent to
 * whoever does it there. That used to be a sentence hard-coded on the
 * space request form naming one survey's contact to everybody, which was
 * wrong for five out of six readers.
 *
 * The director and the administrative assistant stay on ref.Surveys,
 * because every notification path already routes on them.
 */

/** One contact, or null. */
function unit_contact(string $unit, string $role): ?array
{
    static $cache = [];
    $ck = strtoupper($unit) . '|' . $role;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    try {
        $stmt = db('work')->prepare(
            'SELECT * FROM ref.UnitContacts
             WHERE UnitCode = ? AND RoleKey = ? AND IsActive = 1');
        $stmt->execute([strtoupper($unit), $role]);
        return $cache[$ck] = ($stmt->fetch() ?: null);
    } catch (Throwable $e) {
        return $cache[$ck] = null;
    }
}

/** Everything recorded, for the editing screen. */
function unit_contacts_all(): array
{
    try {
        return db('work')->query(
            'SELECT * FROM ref.UnitContacts ORDER BY UnitCode, RoleKey')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Save one cell of the grid; an empty name clears the contact. */
function unit_contact_save(string $unit, string $role, string $name, string $netid,
                           string $email, string $by): void
{
    $unit = strtoupper(trim($unit));
    $name = trim($name);
    $pdo = db('work');
    if ($name === '' && trim($email) === '') {
        $pdo->prepare('DELETE FROM ref.UnitContacts WHERE UnitCode = ? AND RoleKey = ?')
            ->execute([$unit, $role]);
        return;
    }
    $chk = $pdo->prepare('SELECT COUNT(*) FROM ref.UnitContacts WHERE UnitCode = ? AND RoleKey = ?');
    $chk->execute([$unit, $role]);
    if ((int)$chk->fetchColumn() > 0) {
        $pdo->prepare(
            'UPDATE ref.UnitContacts SET PersonName = ?, NetID = ?, Email = ?, IsActive = 1,
                UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
             WHERE UnitCode = ? AND RoleKey = ?'
        )->execute([$name, trim($netid) ?: null, trim($email) ?: null, $by, $unit, $role]);
    } else {
        $pdo->prepare(
            'INSERT INTO ref.UnitContacts (UnitCode, RoleKey, PersonName, NetID, Email,
                IsActive, UpdatedAt, UpdatedByNetID)
             VALUES (?, ?, ?, ?, ?, 1, SYSDATETIME(), ?)'
        )->execute([$unit, $role, $name, trim($netid) ?: null, trim($email) ?: null, $by]);
    }
}

/**
 * A line of prose naming the contact, for a form to show the person who
 * is actually filling it in. Says nothing at all when nobody is
 * recorded, rather than naming somebody else's contact.
 */
function unit_contact_line(string $unit, string $role, string $subject): string
{
    $c = unit_contact($unit, $role);
    if (!$c || trim((string)$c['PersonName']) === '') {
        return '';
    }
    $who = h((string)$c['PersonName']);
    if ($c['Email']) {
        $who = '<a href="mailto:' . h((string)$c['Email']) . '">' . $who . '</a>';
    }
    return 'For ' . h(strtoupper($unit)) . ', questions about ' . h($subject)
         . ' go to ' . $who . '.';
}

/** The grid the editor draws: every unit against every active role. */
function unit_contact_grid(): array
{
    $units = db('work')->query(
        'SELECT Code, Name, UnitKind FROM ref.Surveys WHERE IsActive = 1
         ORDER BY UnitKind, Code')->fetchAll();
    $roles = picklist('unit_contact_role');
    $have = [];
    foreach (unit_contacts_all() as $c) {
        $have[$c['UnitCode'] . '|' . $c['RoleKey']] = $c;
    }
    return ['units' => $units, 'roles' => $roles, 'have' => $have];
}
