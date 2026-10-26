<?php
declare(strict_types=1);

/**
 * Purchase request, as a data sheet.
 *
 * The people filling this in have a quote open beside it and are copying
 * from it. That is not a series of questions, it is a document, and the
 * first version treated it as a questionnaire - eight sections stacked in
 * a reading column, four screens of scrolling to see one purchase. You
 * could not tell whether the funding added up without scrolling away from
 * the items.
 *
 * So it is laid out the way the paper form it replaces was: buyer and
 * vendor abreast at the top, the item lines across the full width with
 * their total under them, funding and documents abreast at the bottom
 * with the funding total beside the item total. One page, no scrolling
 * on a normal screen, and every number that has to agree with another
 * number is visible at the same time as it.
 *
 * The long explanations have not been deleted, they are folded away. A
 * person filling this in the second time does not need to read them, and
 * a person filling it in the first time can open them.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/purchase.php';
require_once __DIR__ . '/lib/attachments.php';
require_once __DIR__ . '/lib/bannercodes.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
$pdo = db('work');

$buildings = $pdo->query('SELECT BldgNumber, Name FROM ref.Buildings WHERE IsActive = 1
                          ORDER BY SortKey')->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $head = [
        'survey'        => strtoupper(trim((string)($_POST['survey_code'] ?? ''))) ?: (string)$user['SurveyCode'],
        'staff_name'    => trim((string)($_POST['staff_name'] ?? '')) ?: (string)$user['DisplayName'],
        'staff_netid'   => strtolower(trim((string)($_POST['staff_netid'] ?? ''))) ?: (string)$user['NetID'],
        'req_date'      => trim((string)($_POST['req_date'] ?? '')) ?: date('n/j/Y'),
        'purchase_type' => trim((string)($_POST['purchase_type'] ?? '')),
        'destination'   => trim((string)($_POST['destination'] ?? '')),
        'bldg'          => trim((string)($_POST['bldg'] ?? '')),
        'room'          => trim((string)($_POST['room'] ?? '')),
        'justification' => trim((string)($_POST['justification'] ?? '')),
        'notes'         => trim((string)($_POST['notes'] ?? '')),
        'vendor_name'   => trim((string)($_POST['vendor_name'] ?? '')),
        'vendor_web'    => trim((string)($_POST['vendor_web'] ?? '')),
        'vendor_addr1'  => trim((string)($_POST['vendor_addr1'] ?? '')),
        'vendor_addr2'  => trim((string)($_POST['vendor_addr2'] ?? '')),
        'vendor_city'   => trim((string)($_POST['vendor_city'] ?? '')),
        'vendor_state'  => trim((string)($_POST['vendor_state'] ?? '')),
        'vendor_zip'    => trim((string)($_POST['vendor_zip'] ?? '')),
        'vendor_phone'  => trim((string)($_POST['vendor_phone'] ?? '')),
        'vendor_notes'  => trim((string)($_POST['vendor_notes'] ?? '')),
        'split'         => in_array($_POST['split'] ?? 'dollar',
                                    ['dollar', 'percent', 'item'], true)
                           ? (string)$_POST['split'] : 'dollar',
    ];

    if ($head['purchase_type'] === '') {
        $errors[] = 'Choose the purchase method. It decides what else is needed.';
    }
    if ($head['vendor_name'] === '') {
        $errors[] = 'Name the suggested vendor.';
    }
    if ($head['justification'] === '') {
        $errors[] = 'A business justification is required.';
    }

    // ---- the items --------------------------------------------------
    $items = [];
    foreach ((array)($_POST['item_description'] ?? []) as $k => $desc) {
        $desc = trim((string)$desc);
        if ($desc === '') {
            continue;
        }
        $qty = (float)($_POST['item_quantity'][$k] ?? 0);
        $cost = (float)str_replace([',', '$'], '', (string)($_POST['item_unit_cost'][$k] ?? '0'));
        $items[] = [
            'catalog'      => trim((string)($_POST['item_catalog'][$k] ?? '')),
            'description'  => $desc,
            'quantity'     => $qty ?: 1,
            'units'        => trim((string)($_POST['item_units'][$k] ?? '')),
            'unit_cost'    => $cost,
            'Amount'       => round(($qty ?: 1) * $cost, 2),
            'account_code' => trim((string)($_POST['item_account'][$k] ?? '')),
            'url'          => trim((string)($_POST['item_url'][$k] ?? '')),
            // Ticking equipment sends the line to the property inventory
            // as an expected arrival once the purchase is approved.
            'is_equipment' => !empty($_POST['item_equipment'][$k]),
        ];
    }
    if (!$items) {
        $errors[] = 'Add at least one product line, with a description.';
    }
    // A Banner code that is not enterable is one the university will
    // reject, so it is caught here rather than three approvals later.
    foreach ($items as $i) {
        if ($i['account_code'] !== '' && !banner_code_valid($i['account_code'])) {
            $errors[] = 'Account code ' . h($i['account_code']) . ' is not one that can be '
                      . 'entered on a purchase line. Pick it from the list.';
        }
    }

    // ---- the funding ------------------------------------------------
    // Each account is looked up again here. The people who must agree are
    // read off the directory, never off the form, because the form can be
    // edited and the directory is the record.
    $funds = [];
    foreach ((array)($_POST['fund_cfop'] ?? []) as $k => $code) {
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            continue;
        }
        $acct = purchase_account($code);
        if (!$acct) {
            $errors[] = 'Account ' . h($code) . ' is not in the directory, so it cannot be '
                      . 'charged. Pick it from the list rather than typing it.';
            continue;
        }
        $piNet = strtolower(trim((string)($_POST['fund_pi_netid'][$k] ?? '')))
               ?: strtolower((string)($acct['AccountPINetID'] ?? ''));
        $fmNet = strtolower(trim((string)($_POST['fund_fm_netid'][$k] ?? '')))
               ?: strtolower((string)($acct['FundManagerNetID'] ?? ''));
        $funds[] = [
            '_account'  => $acct,
            'amount'    => (float)str_replace([',', '$', '%'], '',
                                (string)($_POST['fund_amount'][$k] ?? '0')),
            'pi_netid'  => $piNet,
            'pi_email'  => (string)($acct['AccountPIEmail'] ?? '')
                           ?: ($piNet ? $piNet . '@illinois.edu' : ''),
            'fm_netid'  => $fmNet,
            'fm_email'  => (string)($acct['FundManagerEmail'] ?? '')
                           ?: ($fmNet ? $fmNet . '@illinois.edu' : ''),
        ];
    }
    if (!$funds) {
        $errors[] = 'Name at least one account to charge this to.';
    }

    // The split has to add up, or somebody is going to be surprised.
    if ($funds && $head['split'] === 'percent') {
        $sum = array_sum(array_column($funds, 'amount'));
        if (abs($sum - 100) > 0.01) {
            $errors[] = 'The percentages come to ' . rtrim(rtrim(number_format($sum, 2), '0'), '.')
                      . '%, not 100%.';
        }
    }
    if ($funds && $head['split'] === 'dollar' && $items) {
        $sum = array_sum(array_column($funds, 'amount'));
        $total = purchase_total($items);
        if (abs($sum - $total) > 0.01) {
            $errors[] = 'The funding comes to $' . number_format($sum, 2)
                      . ' but the items come to $' . number_format($total, 2) . '.';
        }
    }

    if (!$errors) {
        $made = purchase_create($head, $items, $funds, $user);
        if (!empty($_FILES['docs']['name'][0])) {
            attach_store_many('work', $made['id'], 'docs', 'purchase', $user, 'purchase');
        }
        flash_set('ok', 'Purchase request ' . $made['ticket'] . ' submitted, for $'
            . number_format($made['total'], 2) . '. Each account needs its principal '
            . 'investigator and its fund manager to approve.');
        header('Location: request.php?type=purchase&id=' . $made['id']);
        exit;
    }
}

/** What was typed last time, so an error never costs the typing. */
function was(string $name, string $fallback = ''): string
{
    return (string)($_POST[$name] ?? $fallback);
}

ui_top('Purchase Request', '', 'sheet', 'index.php', 'request types');
flash_show();
?>

<?php if ($errors): ?>
<div class="panel err"><ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="purchaseform">
    <?= csrf_field() ?>

    <?php /* ---- buyer and vendor, abreast ---------------------------- */ ?>
    <div class="sheetrow two">
        <div class="panel">
            <h2>Buyer</h2>
            <div class="fields">
                <label class="half">Deliver to
                    <input name="staff_name" id="staff_name" data-typeahead="person"
                           data-fill="netid:staff_netid,survey:survey_code" required
                           value="<?= h(was('staff_name', (string)$user['DisplayName'])) ?>"
                           autocomplete="off"></label>
                <input type="hidden" name="staff_netid" id="staff_netid"
                       value="<?= h(was('staff_netid', (string)$user['NetID'])) ?>">
                <label>Unit
                    <input name="survey_code" id="survey_code" readonly tabindex="-1"
                           value="<?= h(was('survey_code', (string)$user['SurveyCode'])) ?>"></label>
                <label>Date
                    <input name="req_date" value="<?= h(was('req_date', date('n/j/Y'))) ?>"></label>
                <label class="half">Purchase method
                    <?= picklist_select('purchase_type', 'purchase_type', was('purchase_type'),
                            ['blank' => '--Select Method--', 'required' => true]) ?></label>
                <label class="half">Destination
                    <?= picklist_select('purchase_destination', 'destination',
                            was('destination', 'PRI Central Receiving (2204 Griffith Dr, Champaign)')) ?></label>
            </div>

            <div class="fields" data-loc-group style="margin-top:0.4rem">
                <label>Building
                    <select name="bldg" data-loc-building>
                        <option value="">Choose a building</option>
                        <?php foreach ($buildings as $b): ?>
                        <option value="<?= h((string)$b['BldgNumber']) ?>"
                            <?= (string)$b['BldgNumber'] === was('bldg') ? 'selected' : '' ?>>
                            <?= h($b['BldgNumber'] . ' — ' . $b['Name']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label>Floor
                    <select name="floor" data-loc-floor disabled><option value="">Choose one</option></select></label>
                <label>Room
                    <select name="room" data-loc-room data-initial="<?= h(was('room')) ?>">
                        <option value="">Choose one</option>
                    </select></label>
                <input type="hidden" name="room_code" data-loc-code>
                <p class="muted wide" data-loc-note hidden></p>
            </div>

            <div class="fields" style="margin-top:0.4rem">
                <label class="half">Business justification
                    <textarea name="justification" rows="3" required><?= h(was('justification')) ?></textarea></label>
                <label class="half">Notes or shipping instructions
                    <textarea name="notes" rows="3"><?= h(was('notes')) ?></textarea></label>
            </div>
        </div>

        <div class="panel">
            <h2>Suggested Vendor</h2>
            <div class="fields">
                <label class="half">Vendor name
                    <input name="vendor_name" required value="<?= h(was('vendor_name')) ?>"></label>
                <label class="half">Web address
                    <input name="vendor_web" value="<?= h(was('vendor_web')) ?>"></label>
                <label class="half">Address
                    <input name="vendor_addr1" value="<?= h(was('vendor_addr1')) ?>"></label>
                <label class="half">Address 2
                    <input name="vendor_addr2" value="<?= h(was('vendor_addr2')) ?>"></label>
                <label>City <input name="vendor_city" value="<?= h(was('vendor_city')) ?>"></label>
                <label>State <input name="vendor_state" value="<?= h(was('vendor_state')) ?>"></label>
                <label>Zip <input name="vendor_zip" value="<?= h(was('vendor_zip')) ?>"></label>
                <label>Phone <input name="vendor_phone" value="<?= h(was('vendor_phone')) ?>"></label>
                <label class="wide">Vendor notes
                    <input name="vendor_notes" value="<?= h(was('vendor_notes')) ?>"></label>
            </div>
        </div>
    </div>

    <?php /* ---- the lines ------------------------------------------- */ ?>
    <div class="panel lines">
        <h2>Product Information</h2>
        <div class="scroll-x">
            <table id="itemtable">
                <tr><th>#</th><th>Catalog</th><th>Description</th><th>Qty</th>
                    <th>Units</th><th>Unit cost</th><th>Amount</th><th>Account code</th>
                    <th>Link</th>
                    <th title="Tagged property that needs an inventory number">Equip</th><th></th></tr>
                <tr class="itemrow">
                    <td class="rowno">1</td>
                    <td><input name="item_catalog[]" size="8"></td>
                    <td><input name="item_description[]" size="26"></td>
                    <td><input name="item_quantity[]" size="3" class="qty" inputmode="decimal" value="1"></td>
                    <td><input name="item_units[]" size="5"></td>
                    <td><input name="item_unit_cost[]" size="7" class="cost" inputmode="decimal"></td>
                    <td class="amount muted">0.00</td>
                    <td><input name="item_account[]" size="11" data-typeahead="banner"
                               placeholder="code or words" autocomplete="off"></td>
                    <td><input name="item_url[]" size="12"></td>
                    <td class="equipcell"><input type="checkbox" name="item_equipment[0]" value="1"></td>
                    <td><button type="button" class="rowdel" title="Remove this line">&times;</button></td>
                </tr>
            </table>
        </div>
        <div class="sheettotal">
            <button type="button" id="additem">Add a Line</button>
            <span class="big">Items: $<span id="grandtotal">0.00</span></span>
            <details class="hint">
                <summary>About the account code and the equipment tick</summary>
                <p>The <strong>account code</strong> says what kind of thing is being bought, as
                the university classifies it. Type a few letters of what it is &mdash; &ldquo;office
                supplies&rdquo;, &ldquo;laboratory&rdquo; &mdash; or the code itself, and pick from
                the list. It is also what the card charge is reconciled against in Emburse, so
                getting it right here saves the work later.</p>
                <p>Tick <strong>Equip</strong> on any line that will be tagged property. Once the
                purchase is approved those lines go to the property inventory as expected
                arrivals, so an inventory number can be put against them the day they turn up
                rather than months later.</p>
            </details>
        </div>
    </div>

    <?php /* ---- funding and documents, abreast ------------------------ */ ?>
    <div class="sheetrow two">
        <div class="panel lines">
            <h2>Funding</h2>
            <div class="sheettotal" style="margin:0 0 0.4rem">
                <span style="font-size:0.78rem">Divide the cost by</span>
                <?php foreach (PURCHASE_SPLITS as $k => $label): ?>
                <label class="check flat"><input type="radio" name="split"
                    value="<?= h($k) ?>" <?= was('split', 'dollar') === $k ? 'checked' : '' ?>>
                    <?= h(match ($k) { 'dollar' => 'dollars', 'percent' => 'percent',
                                       default => 'item number' }) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="scroll-x">
                <table id="fundtable">
                    <tr><th>#</th><th>Account (CFOPA)</th><th>Principal investigator</th>
                        <th>Fund manager</th><th>Amount</th><th></th></tr>
                    <tr class="fundrow">
                        <td class="rowno">1</td>
                        <td><input name="fund_cfop[]" id="fund_cfop_0" data-typeahead="cfop"
                                   data-fill="account_pi:fund_pi_0,fund_manager:fund_fm_0,survey:fund_sv_0"
                                   size="24" placeholder="1-XXXXXX-XXXXXX-XXXXXX-XXX"
                                   autocomplete="off"></td>
                        <td><input id="fund_pi_0" readonly tabindex="-1" size="14">
                            <input type="hidden" name="fund_pi_netid[]"></td>
                        <td><input id="fund_fm_0" readonly tabindex="-1" size="14">
                            <input type="hidden" name="fund_fm_netid[]">
                            <input type="hidden" id="fund_sv_0"></td>
                        <td><input name="fund_amount[]" size="8" class="fundamt" inputmode="decimal"></td>
                        <td><button type="button" class="rowdel" title="Remove this line">&times;</button></td>
                    </tr>
                </table>
            </div>
            <div class="sheettotal">
                <button type="button" id="addfund">Add an Account</button>
                <span class="big">Funding: <span id="fundtotal">0.00</span></span>
                <span id="fundcheck" class="muted"></span>
            </div>
            <details class="hint">
                <summary>Who this asks, and what has to add up</summary>
                <p>Each account listed here asks two people: the principal investigator on it, and
                its fund manager. Their names come from the account directory, so nobody has to
                remember who they are &mdash; and the investigator's administrative assistant may
                answer for them.</p>
                <p>Dollars must add up to the item total; percentages must add up to 100.</p>
            </details>
        </div>

        <div class="panel">
            <h2>Documentation</h2>
            <p class="muted">Quotes, sole source justifications, specifications. Up to ten
            megabytes each.</p>
            <label>Upload documents
                <input type="file" name="docs[]" multiple
                       accept=".pdf,.doc,.docx,.xls,.xlsx,image/*"></label>
            <details class="hint">
                <summary>What happens after this</summary>
                <p>Every account you charge asks its principal investigator and its fund manager
                to approve. When all of them have, it goes to Finance. Nothing here waits on a
                dollar threshold.</p>
                <p>Type part of an account or a surname and pick from the list rather than typing
                it out. The keyboard works throughout: Tab and Shift-Tab move, the arrow keys open
                and move through a list, Tab or Enter chooses.</p>
            </details>
        </div>
    </div>

    <?php /* The signature line of the document, kept in view. */ ?>
    <div class="sheetsubmit">
        <button class="primary">Submit Purchase Request</button>
        <span class="running">Items <strong>$<span id="grandtotal2">0.00</span></strong>
            &middot; funding <strong><span id="fundtotal2">0.00</span></strong>
            <span id="fundcheck2"></span></span>
        <span class="muted">Every account is asked its principal investigator and its fund
        manager, then Finance.</span>
    </div>
</form>

<?php ui_bottom(['location.js', 'purchase.js']); ?>
