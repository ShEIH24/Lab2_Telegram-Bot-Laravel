<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Cart.php';
require_once __DIR__ . '/src/Mailer.php';
require_once __DIR__ . '/src/Bot.php';

// Отключаем проверку SSL (только для локальной разработки)
$guzzle = new \GuzzleHttp\Client(['verify' => false]);

$bot = new Bot($guzzle);
$bot->run();