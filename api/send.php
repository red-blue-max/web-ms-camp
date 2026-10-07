<?php
/**
 * Приём заявки с формы и доставка её вам.
 *
 * Хостинг Timeweb не пропускает соединения к api.telegram.org, поэтому есть два канала:
 *   1) Telegram через ретранслятор (Cloudflare Worker) — TELEGRAM_RELAY_URL + RELAY_SECRET;
 *   2) письмо на почту по SMTP — SMTP_HOST / SMTP_USER / SMTP_PASS / MAIL_TO.
 * Можно включить оба: заявка уйдёт по обоим каналам, и достаточно успеха любого.
 *
 * Настройки берутся (в порядке приоритета):
 *   1) из переменных окружения;
 *   2) из файла telegram-config.php УРОВНЕМ ВЫШЕ корня сайта
 *      (для этого хостинга: /home/c/ct044139/ms-camp.ru/telegram-config.php);
 *   3) из api/config.php рядом с этим скриптом (папка закрыта .htaccess).
 * Шаблон — api/config.example.php. Подробно — README.md.
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
$dirAbove = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
foreach ([
    $dirAbove . '/site-config.php',       // основное имя: настройки почты и Telegram
    $dirAbove . '/telegram-config.php',   // старое имя, тоже понимаем
    __DIR__ . '/config.php',              // запасной вариант, если выше положить нельзя
] as $file) {
    if (is_file($file)) { $config = (array) require $file; break; }
}
$cfg = function (string $key, string $default = '') use ($config): string {
    $env = getenv($key);
    if (is_string($env) && $env !== '') return $env;
    return isset($config[$key]) && is_scalar($config[$key]) ? (string) $config[$key] : $default;
};

$relayUrl    = $cfg('TELEGRAM_RELAY_URL');
$relaySecret = $cfg('RELAY_SECRET');
$token       = $cfg('TELEGRAM_BOT_TOKEN');
$chatId      = $cfg('TELEGRAM_CHAT_ID');
$smtpHost    = $cfg('SMTP_HOST');
$smtpPort    = (int) $cfg('SMTP_PORT', '465');
$smtpUser    = $cfg('SMTP_USER');
$smtpPass    = $cfg('SMTP_PASS');
$mailTo      = $cfg('MAIL_TO');
$allowedOrigins = $config['ALLOWED_ORIGINS'] ?? [];

$hasTelegram = ($relayUrl !== '' && $relaySecret !== '') || ($token !== '' && $chatId !== '');
$hasMail     = $mailTo !== '';
if (!$hasTelegram && !$hasMail) respond(500, ['ok' => false, 'error' => 'not_configured']);

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

$levels = ['Начинающий', 'Средний', 'От 2-го взрослого до МС'];
$errors = [];
if (mb_strlen($parent) < 2) $errors[] = 'parent_name';
if (strlen(preg_replace('/\D/', '', $phone)) !== 11) $errors[] = 'phone';
if (mb_strlen($child) < 2) $errors[] = 'child_name';
if (!ctype_digit($age) || (int) $age < 8 || (int) $age > 18) $errors[] = 'child_age';
if (!in_array($level, $levels, true)) $errors[] = 'level';
if (!$consent) $errors[] = 'consent';
if ($errors) respond(422, ['ok' => false, 'error' => 'validation', 'fields' => $errors]);

/* ---------- Тексты сообщения ---------- */
$tel = '+' . preg_replace('/\D/', '', $phone);
$when = date('d.m.Y H:i');

$plain = [
    'Заявка на зимние сборы 2–10 января 2027',
    '',
    'Родитель: ' . $parent,
    'Телефон: ' . $phone . ' (' . $tel . ')',
    'Ребёнок: ' . $child . ', ' . $age . ' лет',
    'Уровень: ' . $level,
];
if ($comment !== '') $plain[] = 'Комментарий: ' . $comment;
$plain[] = '';
$plain[] = 'Согласие на обработку персональных данных: да · ' . $when;
$plainText = implode("\n", $plain);

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tgLines = [
    '<b>🏊 Заявка: зимние сборы 2–10.01.2027</b>',
    '',
    '<b>Родитель:</b> ' . $h($parent),
    '<b>Телефон:</b> ' . $h($phone) . ' (' . $h($tel) . ')',
    '<b>Ребёнок:</b> ' . $h($child) . ', ' . $h($age) . ' лет',
    '<b>Уровень:</b> ' . $h($level),
];
if ($comment !== '') $tgLines[] = '<b>Комментарий:</b> ' . $h($comment);
$tgLines[] = '';
$tgLines[] = '<i>Согласие на обработку ПДн: да · ' . $when . '</i>';
$tgText = implode("\n", $tgLines);

/* ---------- Канал 1: Telegram ---------- */
function httpPostJson(string $url, array $payload, array $headers = []): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers[] = 'Content-Type: application/json';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, is_string($res) ? $res : '', $err];
    }
    $res = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 12,
        'ignore_errors' => true,
    ]]));
    return [is_string($res) ? 200 : 0, is_string($res) ? $res : '', 'stream error'];
}

$tgOk = false;
$tgError = '';
if ($relayUrl !== '' && $relaySecret !== '') {
    [$code, $res, $err] = httpPostJson($relayUrl, ['secret' => $relaySecret, 'text' => $tgText]);
    $tgOk = $code === 200;
    if (!$tgOk) $tgError = "relay http $code $err " . substr($res, 0, 200);
} elseif ($token !== '' && $chatId !== '') {
    [$code, $res, $err] = httpPostJson(
        'https://api.telegram.org/bot' . $token . '/sendMessage',
        ['chat_id' => $chatId, 'text' => $tgText, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]
    );
    $tgOk = $code === 200;
    if (!$tgOk) $tgError = "telegram http $code $err " . substr($res, 0, 200);
}

/* ---------- Канал 2: письмо ---------- */
function smtpSend(string $host, int $port, string $user, string $pass, string $from, string $to, string $subject, string $body, string &$error): bool {
    $transport = $port === 465 ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 12);
    if (!$fp) { $error = "connect: $errstr"; return false; }
    stream_set_timeout($fp, 12);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $out;
    };
    $cmd = function (string $line, string $expect) use ($fp, $read, &$error): bool {
        if ($line !== '') fwrite($fp, $line . "\r\n");
        $res = $read();
        if (strncmp($res, $expect, strlen($expect)) !== 0) {
            $error = trim($line === '' ? 'greeting' : explode(' ', $line)[0]) . ': ' . trim(substr($res, 0, 120));
            return false;
        }
        return true;
    };

    $ok = $cmd('', '220')
        && $cmd('EHLO ms-camp.ru', '250');
    if ($ok && $port !== 465) {
        $ok = $cmd('STARTTLS', '220')
            && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            && $cmd('EHLO ms-camp.ru', '250');
    }
    $ok = $ok
        && $cmd('AUTH LOGIN', '334')
        && $cmd(base64_encode($user), '334')
        && $cmd(base64_encode($pass), '235')
        && $cmd('MAIL FROM:<' . $from . '>', '250')
        && $cmd('RCPT TO:<' . $to . '>', '250')
        && $cmd('DATA', '354');

    if ($ok) {
        $headers = [
            'From: =?UTF-8?B?' . base64_encode('Сайт ms-camp.ru') . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $data . "\r\n.\r\n");
        $ok = $cmd('', '250');
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}

$mailOk = false;
$mailError = '';
$subject = 'Заявка с сайта: ' . $child . ', ' . $age . ' лет';
if ($mailTo !== '') {
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        $from = $cfg('MAIL_FROM', $smtpUser);
        $mailOk = smtpSend($smtpHost, $smtpPort, $smtpUser, $smtpPass, $from, $mailTo, $subject, $plainText, $mailError);
    } elseif (function_exists('mail')) {
        $headers = "From: site@ms-camp.ru\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $mailOk = @mail($mailTo, '=?UTF-8?B?' . base64_encode($subject) . '?=', $plainText, $headers);
        if (!$mailOk) $mailError = 'mail() вернул false';
    }
}

/* ---------- Ответ ---------- */
if (!$tgOk && !$mailOk) {
    error_log('MosSwim form: доставка не удалась. TG: ' . ($tgError ?: 'не настроен') . ' | MAIL: ' . ($mailError ?: 'не настроена'));
    respond(502, ['ok' => false, 'error' => 'delivery']);
}
if (!$tgOk && $tgError !== '') error_log('MosSwim form: Telegram не отправлен: ' . $tgError);
if (!$mailOk && $mailError !== '') error_log('MosSwim form: письмо не отправлено: ' . $mailError);

respond(200, ['ok' => true]);
