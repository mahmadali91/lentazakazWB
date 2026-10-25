<?php
/**
 * Пример конфигурации. Веб-установщик (/install) создаёт config/config.php автоматически.
 * Можно создать вручную: скопируйте этот файл в config/config.php и заполните значения.
 */
return [
    'app' => [
        'name'          => 'Лента заказов WB',
        'url'           => 'https://wb.linkoz.ru',     // без слеша на конце
        'key'           => '',                            // base64 от 32 случайных байт; шифрует токены WB — не меняйте после запуска!
        'debug'         => false,
        'timezone'      => 'Europe/Moscow',
        'support_email' => 'admin@linkoz.ru',
        'support_phone' => '+79913004737',
        'trial_plan'    => 'pro',                         // какой тариф выдаётся на пробный период
        'trial_days'    => 7,
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'mahmadgv_wblenta',
        'user'    => 'mahmadgv_wblenta',   // на Beget имя пользователя БД = имя базы
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'mail' => [
        'from'      => 'noreply@linkoz.ru',
        'from_name' => 'Лента заказов WB',
    ],

    // Уведомления в Telegram: создайте бота у @BotFather и впишите токен и имя (без @)
    'telegram' => [
        'bot_token'    => '',
        'bot_username' => '',
    ],

    // Оплата через ЮKassa (https://yookassa.ru). Пусто — оплата отключена, тарифы выдаёт администратор.
    'yookassa' => [
        'shop_id'      => '',
        'secret_key'   => '',
        'send_receipt' => false,   // true — передавать чек (54-ФЗ), если подключена онлайн-касса ЮKassa
        'vat_code'     => 1,       // 1 — без НДС
    ],

    // Секрет для запуска синхронизации по URL (если нет cron): https://site/cron-run?key=SECRET
    'cron' => [
        'secret' => '',
    ],

    // Адреса WB API (менять не нужно; можно указать песочницу или мок для тестов)
    'wb' => [
        'timeout' => 30,
        'hosts'   => [
            'common'      => 'https://common-api.wildberries.ru',
            'analytics'   => 'https://seller-analytics-api.wildberries.ru',
            'statistics'  => 'https://statistics-api.wildberries.ru',
            'marketplace' => 'https://marketplace-api.wildberries.ru',
            'content'     => 'https://content-api.wildberries.ru',
            'finance'     => 'https://finance-api.wildberries.ru',
            'advert'      => 'https://advert-api.wildberries.ru',
        ],
    ],
];
