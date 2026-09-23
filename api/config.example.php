<?php
/**
 * Шаблон настроек Telegram-бота.
 *
 * 1. Скопируйте файл под именем telegram-config.php и положите его НА УРОВЕНЬ ВЫШЕ
 *    папки public_html (так он гарантированно недоступен из интернета).
 *    Если так нельзя — назовите api/config.php (папка api закрыта .htaccess).
 * 2. Впишите токен и chat_id. НЕ коммитьте заполненный файл в git.
 */
return [
    'TELEGRAM_BOT_TOKEN' => '[[TELEGRAM_BOT_TOKEN]]',   // выдаёт @BotFather, вида 1234567890:AA...
    'TELEGRAM_CHAT_ID'   => '[[TELEGRAM_CHAT_ID]]',     // ваш личный id или id группы (начинается с -100)

    // Если форма и send.php на разных доменах — перечислите домены сайта:
    // 'ALLOWED_ORIGINS' => ['https://camp.mosswim.ru'],
    'ALLOWED_ORIGINS' => [],
];
