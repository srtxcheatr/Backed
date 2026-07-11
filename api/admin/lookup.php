<?php
// api/admin/lookup.php
//
// GET https://<backend>/api/admin/lookup.php?uid=...
// Header: X-Admin-Secret: <your ADMIN_SECRET>

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/ledger.php';

apply_admin_cors();
header('Content-Type: application/json');

require_admin();

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Provide a uid']);
  exit;
}

$user = with_ledger(function (&$ledger) use ($uid) {
  return $ledger['users'][$uid] ?? null;
});

if (!$user) {
  echo json_encode([
    'success' => true,
    'uid' => $uid,
    'found' => false,
    'balance' => 0,
    'email' => '',
    'adminLog' => [],
    'purchases' => [],
  ]);
  exit;
}

// Most recent first, capped so this never returns something huge.
$adminLog = array_reverse($user['adminLog'] ?? []);
$purchases = array_reverse($user['purchases'] ?? []);

echo json_encode([
  'success' => true,
  'uid' => $uid,
  'found' => true,
  'balance' => (int)($user['balance'] ?? 0),
  'email' => $user['email'] ?? '',
  'adminLog' => array_slice($adminLog, 0, 50),
  'purchases' => array_slice($purchases, 0, 50),
]);
