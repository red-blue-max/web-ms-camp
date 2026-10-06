<?php
// Временный файл диагностики. Показывает, как хостинг помечает защищённые запросы.
// Удалите его после настройки — он ничего секретного не выводит, но и не нужен.
header('Content-Type: text/plain; charset=utf-8');
$keys = ['HTTPS', 'SERVER_PORT', 'REQUEST_SCHEME', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL',
         'HTTP_X_FORWARDED_PORT', 'HTTP_X_REAL_IP', 'SERVER_SOFTWARE', 'DOCUMENT_ROOT'];
foreach ($keys as $k) {
    echo str_pad($k, 24), ' = ', var_export($_SERVER[$k] ?? null, true), PHP_EOL;
}
echo str_pad('PHP', 24), ' = ', PHP_VERSION, PHP_EOL;
echo str_pad('curl', 24), ' = ', function_exists('curl_init') ? 'есть' : 'нет', PHP_EOL;
// Доступен ли Telegram с хостинга — главный вопрос для формы заявки
$t0 = microtime(true);
$ch = curl_init('https://api.telegram.org/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo str_pad('api.telegram.org', 24), ' = ', $code ? "ответил, код $code" : "НЕ доступен: $err", PHP_EOL;
echo str_pad('время запроса', 24), ' = ', round((microtime(true) - $t0) * 1000), ' мс', PHP_EOL;
