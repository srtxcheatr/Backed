<?php
// firebase.php — verifies WHO is calling, nothing else. This version
// deliberately does NOT touch Firestore at all — balance and
// purchases now live in this server's own ledger (see ledger.php)
// instead. That removes the entire class of bug that came from the
// Firestore Admin bridge (credentials, library version, memory,
// transaction syntax) — this file only has to get one thing right:
// "is this a real Firebase login, and whose is it."
//
// SETUP — do this before deploying:
// 1. Firebase Console → Project Settings (gear icon) → Service
//    Accounts tab → "Generate new private key". Downloads a JSON file.
// 2. On Render: your service → Environment → add an environment
//    variable named FIREBASE_SERVICE_ACCOUNT_JSON, and paste the
//    ENTIRE content of that JSON file as its value.
//    Locally: save the same file as serviceAccountKey.json right next
//    to this one (already in .gitignore — never commit the real key).

// ------------------------------------------------------------------
// HARDENING — must run before anything else. Guarantees this API
// never returns anything except deliberate JSON: PHP warnings/notices
// get logged instead of printed (which is what caused "Unexpected
// token '<'" earlier), and even a hard fatal still comes back as
// clean JSON instead of raw PHP output.
// ------------------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  error_log("[srtx-backend] $errstr in $errfile:$errline");
  return true;
});

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    error_log('[srtx-backend] FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Internal server error. Please try again.']);
  }
});

require __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;

function firebase(): Factory {
  static $factory = null;
  if ($factory === null) {
    $json = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if ($json) {
      $creds = json_decode($json, true);
      if (!$creds) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON']);
        exit;
      }
      $factory = (new Factory())->withServiceAccount($creds);
    } else {
      $keyPath = __DIR__ . '/../serviceAccountKey.json';
      if (!file_exists($keyPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: no service account credentials found']);
        exit;
      }
      $factory = (new Factory())->withServiceAccount($keyPath);
    }
  }
  return $factory;
}

/**
 * Reads the Authorization header from wherever it actually lands.
 * Apache (and some proxies in front of it) strip this header from
 * $_SERVER by default — this checks every place it might have ended
 * up instead of assuming just one.
 */
function get_bearer_token(): ?string {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if ($header === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  }
  if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
      if (strcasecmp($name, 'Authorization') === 0) {
        $header = $value;
        break;
      }
    }
  }
  if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    return $m[1];
  }
  return null;
}

$__verifiedClaims = null;

/**
 * Verifies the Firebase ID token. Exits with 401 if it's missing,
 * expired, or doesn't check out. This is what makes the returned uid
 * trustworthy for everything else — a hacker can send any uid they
 * want in a request body, but they cannot forge a token that verifies
 * as someone else's.
 */
function require_firebase_uid(): string {
  global $__verifiedClaims;
  $token = get_bearer_token();
  if ($token === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing Authorization header']);
    exit;
  }
  try {
    $verified = firebase()->createAuth()->verifyIdToken($token);
    $__verifiedClaims = $verified->claims();
    return (string)$__verifiedClaims->get('sub');
  } catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired login. Please refresh and try again.']);
    exit;
  }
}

/** The verified caller's email. Call after require_firebase_uid(). */
function firebase_email(): string {
  global $__verifiedClaims;
  return $__verifiedClaims ? (string)($__verifiedClaims->get('email') ?? '') : '';
}

/**
 * Your frontend and this backend are on different domains, so every
 * request is cross-origin — the browser blocks it unless this backend
 * explicitly allows the frontend's origin.
 */
function apply_cors(): void {
  $allowed = [
    'https://bronzx.web.app',
    'https://bronzx.firebaseapp.com',
    'https://reselle.onrender.com',
    // add any other real domain your frontend is served from here
  ];
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
  }
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}
