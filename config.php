<?php

return [
    // ── Telegram ─────────────────────────────────────────────────────────────
    'bot_token' => '8316877062:AAEiKj3pflEDAns6F_8vk6MFFmS-l6A__Nw',

    // Telegram ID администратора (получить можно через @userinfobot)
    'admin_ids'  => [719455291],

    // ── Email администратора ──────────────────────────────────────────────────
    'admin_email' => 'badron.korol@gmail.com',

    // От кого отправляем письма (должен совпадать с доменом сервера или SMTP)
    'mail_from'        => 'bot@example.com',
    'mail_from_name'   => 'Магазин — Telegram Bot',

    // ── База данных ───────────────────────────────────────────────────────────
    'db_host'    => '127.0.0.1',
    'db_name'    => 'magazin',
    'db_user'    => 'root',
    'db_pass'    => 'Korol2212!',
    'db_charset' => 'utf8mb4',
];