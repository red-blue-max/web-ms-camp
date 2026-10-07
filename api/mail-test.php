<?php
/**
 * Временная проверка отправки почты. Удалите файл после настройки.
 *
 * Показывает, какие настройки нашёл скрипт (пароль — замаскирован)
 * и весь диалог с почтовым сервером, чтобы увидеть точную причину отказа.
 *
 * Открывать: https://ms-camp.ru/api/mail-test.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$config = [];
$found = '(не найден)';
$dirAbove = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
foreach ([
    $dirAbove . '/site-config.php',
    $dirAbove . '/telegram-config.php',
    __DIR__ . '/config.php',
] as $file) {
    if (is_file($file)) { $config = (array) require $file; $found = $file; break; }
}

echo "Файл настроек: $found", PHP_EOL, PHP_EOL;
echo "Что в нём лежит:", PHP_EOL;
foreach (['MAIL_TO', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'MAIL_FROM', 'TELEGRAM_RELAY_URL'] as $k) {
    printf("  %-20s %s\n", $k, isset($config[$k]) && $config[$k] !== '' ? (string) $config[$k] : '— пусто —');
}
$pass = (string) ($config['SMTP_PASS'] ?? '');
printf("  %-20s %s\n", 'SMTP_PASS', $pass === ''
    ? '— ПУСТО (письма уйдут ненадёжным способом) —'
    : 'задан, длина ' . strlen($pass) . ' символов' . (preg_match('/\s/', $pass) ? ' ⚠ содержит пробелы' : ''));

if ($pass === '' || ($config['SMTP_USER'] ?? '') === '') {
    echo PHP_EOL, 'Дальше проверять нечего: не заполнены SMTP_USER или SMTP_PASS.', PHP_EOL;
    exit;
}

/* ---- диалог с почтовым сервером ---- */
$host = (string) ($config['SMTP_HOST'] ?? 'smtp.mail.ru');
$port = (int) ($config['SMTP_PORT'] ?? 465);
$user = (string) $config['SMTP_USER'];
$from = (string) ($config['MAIL_FROM'] ?? $user);
$to   = (string) ($config['MAIL_TO'] ?? $user);

echo PHP_EOL, "Соединяюсь с $host:$port ...", PHP_EOL;
$fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 12);
if (!$fp) { echo "НЕ УДАЛОСЬ: $errstr", PHP_EOL; exit; }
stream_set_timeout($fp, 12);

$read = function () use ($fp): string {
    $out = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $out .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') break;
    }
    return rtrim($out);
};
$say = function (string $line, string $hide = '') use ($fp, $read): string {
    if ($line !== '') {
        echo '> ', ($hide !== '' ? $hide : $line), PHP_EOL;
        fwrite($fp, $line . "\r\n");
    }
    $res = $read();
    echo '< ', $res, PHP_EOL;
    return $res;
};

$say('');
$say('EHLO ms-camp.ru');
$say('AUTH LOGIN');
$say(base64_encode($user), 'AUTH LOGIN: логин');
$r = $say(base64_encode($pass), 'AUTH LOGIN: пароль (скрыт)');

if (strncmp($r, '235', 3) !== 0) {
    echo PHP_EOL, 'ИТОГ: почтовый сервер не принял логин или пароль.', PHP_EOL,
         'Создайте новый пароль для внешних приложений и впишите его без пробелов.', PHP_EOL;
    fwrite($fp, "QUIT\r\n"); fclose($fp); exit;
}

$say("MAIL FROM:<$from>");
$say("RCPT TO:<$to>");
$r = $say('DATA');
if (strncmp($r, '354', 3) === 0) {
    $body = "Проверка отправки писем с сайта ms-camp.ru.\nЕсли вы видите это письмо — почта настроена верно.";
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('Сайт ms-camp.ru') . "?= <$from>",
        "To: <$to>",
        'Subject: =?UTF-8?B?' . base64_encode('Проверка почты с сайта') . '?=',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . "\r\n.\r\n");
    echo '> (текст письма)', PHP_EOL;
    $r = $read();
    echo '< ', $r, PHP_EOL;
    echo PHP_EOL, strncmp($r, '250', 3) === 0
        ? "ИТОГ: письмо принято почтовым сервером. Проверьте ящик $to, включая «Спам»."
        : 'ИТОГ: сервер отказался принимать письмо, причина выше.', PHP_EOL;
}
fwrite($fp, "QUIT\r\n");
fclose($fp);
