<?php
// api/user/balance.php — GET, returns the caller's own balance.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/ledger.php';

apply_cors();
header('Content-Type: application/json');

$uid = require_firebase_uid();
$email = firebase_email();

$balance = with_ledger(function (&$ledger) use ($uid, $email) {
  if (!isset($ledger['users'][$uid])) {
    $ledger['users'][$uid] = ['balance' => 0, 'purchases' => [], 'adminLog' => []];
  }
  if ($email !== '') {
    $ledger['users'][$uid]['email'] = $email;
  }
  return (int)$ledger['users'][$uid]['balance'];
});

echo json_encode(['success' => true, 'balance' => $balance]);
