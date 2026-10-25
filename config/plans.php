<?php
/**
 * Тарифы сервиса. Меняйте цены и лимиты здесь — изменения подхватываются сразу.
 *
 * price          — цена за 1 месяц, ₽ (0 — бесплатный)
 * stores         — сколько магазинов WB можно подключить
 * history_days   — за сколько дней показывать и загружать заказы (WB отдаёт ленту максимум за 31 день)
 * sync_minutes   — как часто опрашивать WB (минимум 1 — ограничение WB для ленты заказов)
 * telegram       — уведомления о заказах в Telegram
 * export         — выгрузка в CSV/Excel
 * public         — показывать на странице тарифов
 */
return [
    'free' => [
        'name'         => 'Бесплатный',
        'price'        => 0,
        'stores'       => 1,
        'history_days' => 3,
        'sync_minutes' => 10,
        'telegram'     => false,
        'export'       => false,
        'public'       => true,
        'features'     => ['1 магазин', 'История за 3 дня', 'Обновление раз в 10 минут'],
    ],
    'start' => [
        'name'         => 'Старт',
        'price'        => 490,
        'stores'       => 1,
        'history_days' => 14,
        'sync_minutes' => 1,
        'telegram'     => true,
        'export'       => false,
        'public'       => true,
        'features'     => ['1 магазин', 'История за 14 дней', 'Обновление каждую минуту', 'Уведомления в Telegram'],
    ],
    'pro' => [
        'name'         => 'Про',
        'price'        => 990,
        'stores'       => 3,
        'history_days' => 31,
        'sync_minutes' => 1,
        'telegram'     => true,
        'export'       => true,
        'public'       => true,
        'popular'      => true,
        'features'     => ['До 3 магазинов', 'История за 31 день', 'Обновление каждую минуту', 'Уведомления в Telegram', 'Выгрузка в Excel'],
    ],
    'business' => [
        'name'         => 'Бизнес',
        'price'        => 2490,
        'stores'       => 10,
        'history_days' => 31,
        'sync_minutes' => 1,
        'telegram'     => true,
        'export'       => true,
        'public'       => true,
        'features'     => ['До 10 магазинов', 'История за 31 день', 'Обновление каждую минуту', 'Уведомления в Telegram', 'Выгрузка в Excel', 'Приоритетная поддержка'],
    ],
];
