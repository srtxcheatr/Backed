<?php
// telegram.php — admin notifications via a Telegram bot.
//
// SETUP:
// 1. Message @BotFather on Telegram → /newbot → follow the prompts.
//    You get a token that looks like 123456:ABC-DEF...
// 2. Add the bot to the group/channel you want alerts in (or just
//    message it directly for a personal chat).
// 3. Get the chat id: message the bot, then open
//    https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates in a browser
//    and read "chat":{"id": ...} from the response.
// 4. On Render: add environment variables TELEGRAM_BOT_TOKEN and
//    TELEGRAM_CHAT_ID with those two values.
//
// If those env vars aren't set, or the Telegram API call fails for any
// reason, this silently does nothing — a notification failing must
// never block or fail the actual purchase/top-up it's reporting on.

function telegram_notify(string $text): void {
  $token = getenv('TELEGRAM_BOT_TOKEN');
  $chatId = getenv('TELEGRAM_CHAT_ID');
  if (!$token || !$chatId) return;

  $payload = json_encode([
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
  ]);

  $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5, // a slow/unreachable Telegram API must never hang a real request
  ]);
  @curl_exec($ch);
  curl_close($ch);
}

function telegram_escape(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Builds one consistently-formatted message from the fields you asked
 * for: username, email, product, price, date, uid, status, others.
 */
function telegram_format(string $title, array $f): string {
  $status = strtolower((string)($f['status'] ?? ''));
  $emoji = [
    'success' => '✅', 'failed' => '❌', 'cancelled' => '🚫',
    'pending' => '⏳', 'attempt' => '🛒',
  ][$status] ?? 'ℹ️';

  $lines = [
    "{$emoji} <b>" . telegram_escape($title) . "</b>",
    "👤 " . telegram_escape($f['username'] ?? '—'),
    "✉️ " . telegram_escape($f['email'] ?? '—'),
    "📦 " . telegram_escape($f['product'] ?? '—'),
    "💰 Rs " . telegram_escape((string)($f['price'] ?? '0')),
    "📅 " . telegram_escape($f['date'] ?? date('Y-m-d H:i:s')),
    "🆔 <code>" . telegram_escape($f['uid'] ?? '—') . "</code>",
  ];
  if ($status !== '') {
    $lines[] = "📊 Status: <b>" . telegram_escape(ucfirst($status)) . "</b>";
  }
  if (!empty($f['others'])) {
    $lines[] = "📝 " . telegram_escape((string)$f['others']);
  }
  return implode("\n", $lines);
}
