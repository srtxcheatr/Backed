<?php
// api/admin/add-balance.php
//
// Your top-up flow still writes a PENDING claim straight to Firestore
// from the browser (unchanged) — that's fine, it's just a claim, not
// money moving. Once you've actually verified a real eSewa payment,
// use this to credit the buyer's balance in the new ledger.
//
// Call it with header:  X-Admin-Secret: <your ADMIN_SECRET>
// and JSON body:  { "uid": "...", "amount": 500 }
//
// Set ADMIN_SECRET as an environment variable on Render — pick a long
// random string, don't reuse a password from anywhere else.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/ledger.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$expected = getenv('ADMIN_SECRET');
$given = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
if (!$expected || !hash_equals($expected, $given)) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Not authorized']);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = trim((string)($body['uid'] ?? ''));
$amount = (int)($body['amount'] ?? 0);

if ($uid === '' || $amount <= 0 || $amount > 1000000) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Provide a uid and a positive amount']);
  exit;
}

$outcome = with_ledger(function (&$ledger) use ($uid, $amount) {
  if (!isset($ledger['users'][$uid])) {
    $ledger['users'][$uid] = ['balance' => 0, 'purchases' => []];
  }
  $ledger['users'][$uid]['balance'] = (int)$ledger['users'][$uid]['balance'] + $amount;
  return $ledger['users'][$uid]['balance'];
});

echo json_encode(['success' => true, 'newBalance' => $outcome]);
