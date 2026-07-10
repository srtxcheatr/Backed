<?php
// api/purchase/balance.php
//
// The browser sends only a `sku`. Price comes from catalog.php,
// balance comes from ledger.php — never from anything the request
// says. A hacker can still force the "Buy" button to be clickable
// with dev tools and fire this request; it just won't go through if
// their real, server-side balance is too low.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/catalog.php';
require __DIR__ . '/../../src/ledger.php';
require __DIR__ . '/../../src/telegram.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$uid = require_firebase_uid();
$email = firebase_email();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$sku = (string)($body['sku'] ?? '');
$product = catalog_find($sku);

if (!$product) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Unknown product']);
  exit;
}

$buyerName = trim((string)($body['name'] ?? ''));
$waNum = trim((string)($body['waNum'] ?? ''));
if ($buyerName === '' || $waNum === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Fill in Name and WhatsApp!']);
  exit;
}

function notify_purchase(string $uid, string $email, string $buyerName, array $product, string $status, ?string $extra = null): void {
  telegram_notify(telegram_format('Purchase Attempt', [
    'username' => $buyerName,
    'email' => $email,
    'product' => $product['name'] . ' (' . $product['duration'] . ')',
    'price' => $product['price'],
    'date' => date('Y-m-d H:i:s'),
    'uid' => $uid,
    'status' => $status,
    'others' => $extra,
  ]));
}

$outcome = with_ledger(function (&$ledger) use ($uid, $product, $sku, $buyerName, $waNum) {
  if (!isset($ledger['users'][$uid])) {
    $ledger['users'][$uid] = ['balance' => 0, 'purchases' => []];
  }
  $balance = (int)$ledger['users'][$uid]['balance'];

  if ($balance < $product['price']) {
    return ['error' => 'Insufficient balance'];
  }

  $newBalance = $balance - $product['price'];
  $key = strtoupper(bin2hex(random_bytes(8)));

  $ledger['users'][$uid]['balance'] = $newBalance;
  $ledger['users'][$uid]['purchases'][] = [
    'sku' => $sku,
    'name' => $product['name'],
    'row' => $product['row'],
    'duration' => $product['duration'],
    'price' => $product['price'],
    'key' => $key,
    'buyerName' => $buyerName,
    'whatsapp' => $waNum,
    'at' => date('c'),
  ];

  return ['newBalance' => $newBalance, 'key' => $key];
});

if (isset($outcome['error'])) {
  notify_purchase($uid, $email, $buyerName, $product, 'failed', $outcome['error']);
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $outcome['error']]);
  exit;
}

notify_purchase($uid, $email, $buyerName, $product, 'success', 'Key: ' . $outcome['key'] . ' · WA: ' . $waNum);

echo json_encode([
  'success' => true,
  'newBalance' => $outcome['newBalance'],
  'key' => $outcome['key'],
]);
