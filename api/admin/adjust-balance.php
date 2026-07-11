<?php
// api/admin/adjust-balance.php
//
// Add or deduct a user's balance and log why. Call it with:
//   Header: X-Admin-Secret: <your ADMIN_SECRET>
//   Body:   { "uid": "...", "amount": 500, "direction": "add", "note": "eSewa top-up verified" }
// direction is "add" or "deduct" — amount is always a positive number,
// direction decides which way it moves.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/ledger.php';

apply_admin_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

require_admin();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = trim((string)($body['uid'] ?? ''));
$amount = (int)($body['amount'] ?? 0);
$direction = (string)($body['direction'] ?? 'add');
$note = trim((string)($body['note'] ?? ''));

if ($uid === '' || $amount <= 0 || $amount > 1000000) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Provide a uid and a positive amount']);
  exit;
}
if (!in_array($direction, ['add', 'deduct'], true)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'direction must be "add" or "deduct"']);
  exit;
}

$outcome = with_ledger(function (&$ledger) use ($uid, $amount, $direction, $note) {
  if (!isset($ledger['users'][$uid])) {
    $ledger['users'][$uid] = ['balance' => 0, 'purchases' => [], 'adminLog' => []];
  }
  if (!isset($ledger['users'][$uid]['adminLog'])) {
    $ledger['users'][$uid]['adminLog'] = [];
  }

  $delta = $direction === 'add' ? $amount : -$amount;
  $newBalance = (int)$ledger['users'][$uid]['balance'] + $delta;
  $ledger['users'][$uid]['balance'] = $newBalance;

  $ledger['users'][$uid]['adminLog'][] = [
    'delta' => $delta,
    'note' => $note !== '' ? $note : 'Manual ' . $direction,
    'resultingBalance' => $newBalance,
    'at' => date('c'),
  ];

  return ['balance' => $newBalance, 'email' => $ledger['users'][$uid]['email'] ?? ''];
});

echo json_encode(['success' => true, 'newBalance' => $outcome['balance']]);
