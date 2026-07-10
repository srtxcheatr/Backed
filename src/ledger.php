<?php
// ledger.php — where balance and purchase history actually live now.
// A plain JSON file, keyed by Firebase uid, read-modified-written
// under an exclusive lock so two requests can never race each other.
//
// This intentionally does NOT try to read your existing Firestore
// balances — new users here start at 0. If you have real balances
// sitting in Firestore you need carried over, that's a one-time
// migration (ask me and I'll write a small script for it); this file
// is only about what happens going forward.

define('LEDGER_PATH', __DIR__ . '/../data/ledger.json');

/**
 * Reads the ledger, lets you read/mutate it inside $fn, writes it
 * back — all under one exclusive lock, so a second request has to
 * wait for the first to finish rather than racing it.
 */
function with_ledger(callable $fn) {
  $fp = fopen(LEDGER_PATH, 'c+');
  if (!$fp) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Storage error']);
    exit;
  }
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $ledger = $raw === '' ? ['users' => []] : json_decode($raw, true);
  if (!is_array($ledger) || !isset($ledger['users'])) {
    $ledger = ['users' => []];
  }

  $result = $fn($ledger);

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($ledger, JSON_PRETTY_PRINT));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return $result;
}
