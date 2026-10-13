<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ui.php';

/**
 * CFOP lookup for shop intake. Resolves survey, account PI, and fund
 * manager from the locally maintained ref.CfopAccounts directory in the
 * shop database (the university financial system remains the source of
 * CFOP validity; this directory is maintained through Admin).
 */

header('Content-Type: application/json');
if (!app_installed()) {
    http_response_code(503);
    echo json_encode(['error' => 'not installed']);
    exit;
}
current_user();

$cfop = strtoupper(trim((string)($_GET['cfop'] ?? '')));
if ($cfop === '') {
    echo json_encode(['found' => false]);
    exit;
}

$pdo = db('shop');
try {
    $stmt = $pdo->prepare('SELECT CfopCode, SurveyCode, AccountPI, FundManager FROM ref.CfopAccounts
                           WHERE CfopCode = ? AND IsActive = 1');
    $stmt->execute([$cfop]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    $row = false;   // directory table absent until example/admin seeding
}

if (!$row) {
    echo json_encode(['found' => false]);
    exit;
}
echo json_encode([
    'found'        => true,
    'cfop'         => $row['CfopCode'],
    'survey'       => $row['SurveyCode'],
    'account_pi'   => $row['AccountPI'],
    'fund_manager' => $row['FundManager'],
]);
