<?php
// Временный файл диагностики. Удалите после настройки.
header('Content-Type: text/plain; charset=utf-8');

$keys = ['HTTPS', 'SERVER_PORT', 'REQUEST_SCHEME', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL',
         'SERVER_SOFTWARE', 'DOCUMENT_ROOT'];
foreach ($keys as $k) {
    echo str_pad($k, 26), ' = ', var_export($_SERVER[$k] ?? null, true), PHP_EOL;
}
echo str_pad('PHP', 26), ' = ', PHP_VERSION, PHP_EOL;
echo str_pad('mail()', 26), ' = ', function_exists('mail') ? 'есть' : 'нет', PHP_EOL;
echo PHP_EOL, '--- исходящие HTTPS-соединения ---', PHP_EOL;

foreach ([
    'https://api.telegram.org/'   => 'Telegram Bot API',
    'https://example.com/'        => 'обычный сайт за рубежом',
    'https://workers.dev/'        => 'Cloudflare Workers',
    'https://api.vk.com/'         => 'VK API (Россия)',
    'https://smtp.mail.ru/'       => 'mail.ru',
] as $url => $title) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    printf("%-26s %s\n", $title, $code ? "ОК, код $code" : "нет связи: $err");
}

echo PHP_EOL, '--- SMTP-порты ---', PHP_EOL;
foreach ([['smtp.mail.ru', 465], ['smtp.mail.ru', 587], ['localhost', 25]] as [$host, $port]) {
    $t0 = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, 6);
    printf("%-26s %s\n", "$host:$port", $fp ? 'открыт' : "закрыт ($errstr)");
    if ($fp) fclose($fp);
}
