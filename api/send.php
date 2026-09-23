<?php
/**
 * Прокси формы заявки → Telegram Bot API (sendMessage).
 *
 * Токен бота НЕ хранится в коде сайта и не попадает в браузер.
 * Откуда берутся настройки (в порядке приоритета):
 *   1) переменные окружения TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID;
 *   2) файл telegram-config.php УРОВНЕМ ВЫШЕ корня сайта (рекомендуется для Timeweb,
 *      например /home/c/cXXXXX/telegram-config.php при корне /home/c/cXXXXX/site.ru/public_html);
 *   3) файл api/config.php рядом с этим скриптом (закрыт .htaccess).
 * Шаблон файла — api/config.example.php. Подробно — README.md.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Конфигурация ---------- */
$config = [];
$candidates = [
    dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/telegram-config.php',
    __DIR__ . '/config.php',
];
foreach ($candidates as $file) {
    if (is_file($file)) { $config = (array) require $file; break; }
}
$token  = getenv('TELEGRAM_BOT_TOKEN') ?: ($config['TELEGRAM_BOT_TOKEN'] ?? '');
$chatId = getenv('TELEGRAM_CHAT_ID')   ?: ($config['TELEGRAM_CHAT_ID'] ?? '');
// Домены, с которых разрешены заявки (пусто = только тот же домен, что и скрипт)
$allowedOrigins = $config['ALLOWED_ORIGINS'] ?? [];

/* ---------- CORS / метод ---------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$sameOrigin = $origin === '' || parse_url($origin, PHP_URL_HOST) === $host;
if (!$sameOrigin && !in_array($origin, $allowedOrigins, true)) {
    respond(403, ['ok' => false, 'error' => 'origin']);
}
if ($origin !== '' && !$sameOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'error' => 'method']);

if ($token === '' || $chatId === '') respond(500, ['ok' => false, 'error' => 'not_configured']);

/* ---------- Ограничение частоты: 5 заявок / 10 минут с одного IP ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rlFile = sys_get_temp_dir() . '/mosswim_rl_' . md5($ip);
$now = time();
$hits = is_file($rlFile) ? array_filter(
    array_map('intval', explode(',', (string) @file_get_contents($rlFile))),
    fn($t) => $t > $now - 600
) : [];
if (count($hits) >= 5) respond(429, ['ok' => false, 'error' => 'rate_limit']);
$hits[] = $now;
@file_put_contents($rlFile, implode(',', $hits), LOCK_EX);

/* ---------- Данные ---------- */
$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode((string) $raw, true);
if (!is_array($in)) $in = $_POST;

$field = function (string $k, int $max) use ($in): string {
    $v = isset($in[$k]) && is_scalar($in[$k]) ? trim((string) $in[$k]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
};

// Honeypot и «слишком быстро» — молча отвечаем «ок», ничего не отправляя
if ($field('website', 200) !== '' || (int) ($in['elapsed'] ?? 0) < 2500) {
    respond(200, ['ok' => true]);
}

$parent  = $field('parent_name', 80);
$phone   = $field('phone', 30);
$child   = $field('child_name', 80);
$age     = $field('child_age', 3);
$level   = $field('level', 40);
$comment = $field('comment', 1000);
$consent = !empty($in['consent']);

$levels = ['Начинающий', 'Средний', 'Разряд / КМС / МС'];
$errors = [];
if (mb_strlen($parent) < 2) $errors[] = 'parent_name';
if (strlen(preg_replace('/\D/', '', $phone)) !== 11) $errors[] = 'phone';
if (mb_strlen($child) < 2) $errors[] = 'child_name';
if (!ctype_digit($age) || (int) $age < 4 || (int) $age > 25) $errors[] = 'child_age';
if (!in_array($level, $levels, true)) $errors[] = 'level';
if (!$consent) $errors[] = 'consent';
if ($errors) respond(422, ['ok' => false, 'error' => 'validation', 'fields' => $errors]);

/* ---------- Сообщение ---------- */
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tel = '+' . preg_replace('/\D/', '', $phone);
$lines = [
    '<b>🏊 Заявка: зимние сборы 2–10.01.2027</b>',
    '',
    '<b>Родитель:</b> ' . $h($parent),
    '<b>Телефон:</b> ' . $h($phone) . ' (' . $h($tel) . ')',
    '<b>Ребёнок:</b> ' . $h($child) . ', ' . $h($age) . ' лет',
    '<b>Уровень:</b> ' . $h($level),
];
if ($comment !== '') $lines[] = '<b>Комментарий:</b> ' . $h($comment);
$lines[] = '';
$lines[] = '<i>Согласие на обработку ПДн: да · ' . date('d.m.Y H:i') . '</i>';

$body = json_encode([
    'chat_id' => $chatId,
    'text' => implode("\n", $lines),
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
], JSON_UNESCAPED_UNICODE);

$url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
$ok = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $code === 200;
} else {
    $res = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]));
    $ok = is_string($res) && !empty(json_decode($res, true)['ok']);
}

if (!$ok) {
    error_log('MosSwim form: Telegram error: ' . (is_string($res) ? $res : 'no response'));
    respond(502, ['ok' => false, 'error' => 'telegram']);
}
respond(200, ['ok' => true]);
