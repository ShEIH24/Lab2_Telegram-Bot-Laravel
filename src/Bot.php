<?php

use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\Update;

class Bot
{
    private Api   $telegram;
    private array $cfg;

    // массив состояний пользователей, ключ — chat_id, значение — название состояния
    private array  $states = [];
    private string $stateFile;

    // метки кнопок главного меню клиента
    private const M_CATALOG = '🛒 Каталог';
    private const M_SEARCH  = '🔍 Поиск';
    private const M_CART    = '🧺 Корзина';
    private const M_ORDERS  = '📦 Мои заказы';
    private const M_FAQ     = '❓ FAQ';

    // метки кнопок меню администратора
    private const A_ORDERS = '📋 Все заказы';
    private const A_SEARCH = '🔍 Поиск заказа';
    private const A_EXIT   = '🔙 Выйти из админки';

    // создаёт бота, настраивает апи и загружает состояния пользователей
    public function __construct(?\GuzzleHttp\Client $guzzle = null)
    {
        $this->cfg      = require __DIR__ . '/../config.php';
        $this->telegram = new Api($this->cfg['bot_token']);
        if ($guzzle) {
            $this->telegram->setHttpClientHandler(
                new \Telegram\Bot\HttpClients\GuzzleHttpClient($guzzle)
            );
        }
        $this->stateFile = __DIR__ . '/../storage/states.json';
        $this->loadStates();
    }

    // запускает бесконечный цикл получения обновлений от телеграма
    public function run(): void
    {
        echo "Бот запущен...\n";
        $offset = 0;
        while (true) {
            try {
                $updates = $this->telegram->getUpdates(['offset' => $offset, 'timeout' => 30, 'limit' => 100]);
                foreach ($updates as $upd) {
                    $offset = $upd->updateId + 1;
                    try {
                        $this->dispatch($upd);
                    } catch (\Throwable $e) {
                        echo "ошибка обработки апдейта: {$e->getMessage()}\n";
                    }
                }
            } catch (\Throwable $e) {
                echo "ошибка getUpdates: {$e->getMessage()}\n";
                sleep(3);
            }
        }
    }

    // определяет тип апдейта и передаёт его нужному обработчику
    private function dispatch(Update $upd): void
    {
        if ($upd->inlineQuery)   { $this->onInline($upd);   return; }
        if ($upd->callbackQuery) { $this->onCallback($upd); return; }
        if ($upd->message)       { $this->onMessage($upd);  return; }
    }

    // проверяет является ли пользователь администратором
    private function isAdmin(int $chatId): bool
    {
        return in_array($chatId, $this->cfg['admin_ids'], true);
    }

    // проверяет находится ли пользователь в режиме администратора
    private function inAdminMode(int $chatId): bool
    {
        return $this->isAdmin($chatId) && $this->getState($chatId) === 'admin';
    }

    
    // обработка текстовых сообщений
    

    private function onMessage(Update $upd): void
    {
        $msg  = $upd->message;
        $chat = $msg->chat->id;
        $text = trim($msg->text ?? '');

        // обрабатываем команды начинающиеся со слеша
        if (isset($text[0]) && $text[0] === '/') {
            $cmd = strtolower(explode(' ', explode('@', $text)[0])[0]);
            match ($cmd) {
                '/start', '/menu' => $this->sendMainMenu($chat, $text === '/start'),
                '/admin'          => $this->handleAdminCommand($chat),
                '/help'           => $this->sendHelp($chat),
                default           => $this->send($chat, 'Неизвестная команда. /menu — меню.'),
            };
            return;
        }

        $state = $this->getState($chat);

        // ожидаем текст поискового запроса от клиента
        if ($state === 'search') {
            $this->clearState($chat);
            $this->sendSearchResults($chat, $text);
            return;
        }

        // ожидаем строку для поиска заказа от администратора
        if ($state === 'admin_search_order') {
            $this->clearState($chat);
            $this->sendAdminOrderSearch($chat, $text);
            return;
        }

        // обрабатываем кнопки меню администратора если он в режиме админки
        if ($this->inAdminMode($chat)) {
            match ($text) {
                self::A_ORDERS => $this->sendAdminOrders($chat),
                self::A_SEARCH => $this->startAdminOrderSearch($chat),
                self::A_EXIT   => $this->exitAdmin($chat),
                default        => $this->sendAdminMenu($chat),
            };
            return;
        }

        // обрабатываем кнопки главного меню клиента
        match ($text) {
            self::M_CATALOG => $this->sendCatalog($chat),
            self::M_SEARCH  => $this->startSearch($chat),
            self::M_CART    => $this->sendCartMsg($chat),
            self::M_ORDERS  => $this->sendMyOrders($chat),
            self::M_FAQ     => $this->sendFaq($chat),
            default         => $this->sendMainMenu($chat),
        };
    }

    // обрабатывает команду /admin — проверяет права и включает режим администратора
    private function handleAdminCommand(int $chat): void
    {
        if (!$this->isAdmin($chat)) {
            $this->send($chat, 'Доступ запрещён.');
            return;
        }
        $this->setState($chat, 'admin');
        $this->sendAdminMenu($chat);
    }

    // выключает режим администратора и показывает клиентское меню
    private function exitAdmin(int $chat): void
    {
        $this->clearState($chat);
        $this->sendMainMenu($chat);
    }

    
    // обработка нажатий inline-кнопок — всегда редактирует сообщение
    

    private function onCallback(Update $upd): void
    {
        $cb   = $upd->callbackQuery;
        $chat = $cb->message->chat->id;
        $mid  = $cb->message->messageId;
        $data = $cb->data ?? '';

        // сразу отвечаем на callback чтобы убрать часики на кнопке
        $this->answerCb($cb->id);

        // навигация по каталогу
        if ($data === 'catalog')             { $this->editCatalog($chat, $mid);                          return; }
        if (str_starts_with($data, 'cat_'))  { $this->editItems($chat, $mid, (int) substr($data, 4));    return; }
        if (str_starts_with($data, 'item_')) { $this->editItem($chat, $mid, (int) substr($data, 5));     return; }

        // добавление товара в корзину
        if (str_starts_with($data, 'add_')) {
            $iid  = (int) substr($data, 4);
            $item = $this->dbItem($iid);
            if ($item) {
                Cart::add($chat, $iid, $item['name'], (float) $item['price']);
                $this->answerCb($cb->id, 'Добавлено в корзину!');
            }
            // остаёмся на карточке товара после добавления
            $this->editItem($chat, $mid, $iid);
            return;
        }

        // навигация по корзине
        if ($data === 'cart')                   { $this->editCart($chat, $mid);                                     return; }
        if (str_starts_with($data, 'rm_'))      { Cart::remove($chat, (int) substr($data, 3)); $this->editCart($chat, $mid); return; }
        if ($data === 'cart_clear')             { Cart::clear($chat); $this->editCart($chat, $mid);                 return; }

        // переход к выбору способа оплаты
        if ($data === 'cart_order') {
            if (Cart::count($chat) === 0) {
                $this->answerCb($cb->id, 'Корзина пуста!');
                return;
            }
            $this->editOrderPayment($chat, $mid);
            return;
        }

        // обработка выбранного способа оплаты
        if (str_starts_with($data, 'pay_')) {
            $this->processOrder($chat, $mid, $upd->callbackQuery, substr($data, 4));
            return;
        }

        // навигация по заказам клиента
        if ($data === 'my_orders')                   { $this->editMyOrders($chat, $mid);                          return; }
        if (str_starts_with($data, 'cord_'))         { $this->editClientOrder($chat, $mid, (int) substr($data, 5)); return; }

        // навигация по faq
        if ($data === 'faq')                         { $this->editFaq($chat, $mid);                                return; }
        if (str_starts_with($data, 'faq_'))          { $this->editFaqTopic($chat, $mid, substr($data, 4));         return; }

        // навигация по заказам администратора
        if ($data === 'admin_orders')                { $this->editAdminOrders($chat, $mid);                        return; }
        if (str_starts_with($data, 'aord_'))         { $this->editAdminOrder($chat, $mid, (int) substr($data, 5)); return; }

        // смена статуса заказа администратором — формат: ast_{orderId}_{status}
        if (str_starts_with($data, 'ast_')) {
            $parts = explode('_', substr($data, 4), 2);
            if (count($parts) === 2) {
                Database::updateOrderStatus((int) $parts[0], $parts[1]);
                $this->editAdminOrder($chat, $mid, (int) $parts[0]);
            }
            return;
        }

        // поиск через inline-режим
        if ($data === 'search') {
            $this->setState($chat, 'search');
            $this->editMsg($chat, $mid, 'Введите название товара для поиска:');
            return;
        }

        // закрываем inline-сообщение удаляя его
        if ($data === 'close') {
            try {
                $this->telegram->deleteMessage(['chat_id' => $chat, 'message_id' => $mid]);
            } catch (\Throwable $e) {}
        }
    }

    
    // inline-режим — поделиться карточкой товара в другом чате
    

    private function onInline(Update $upd): void
    {
        $q  = trim($upd->inlineQuery->query ?? '');
        $id = $upd->inlineQuery->id;
        try {
            // если запрос пустой показываем все товары, иначе ищем по запросу
            $rows = $q !== '' ? Database::searchItems($q) : Database::getAllItems();
        } catch (\Throwable $e) {
            $this->telegram->answerInlineQuery(['inline_query_id' => $id, 'results' => '[]']);
            return;
        }
        $results = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $item = $this->dbItem((int) $row['id']);
            if (!$item) {
                continue;
            }
            $desc      = trim($item['description'] ?? '');
            $results[] = [
                'type'        => 'article',
                'id'          => (string) $item['id'],
                'title'       => $item['name'],
                'description' => sprintf('%.2f руб. | %d шт.', $item['price'], $item['count']),
                'input_message_content' => [
                    'message_text' => sprintf(
                        "Товар: %s\n%s\nЦена: %.2f руб.  |  В наличии: %d шт.\nПроизводитель: %s",
                        $item['name'],
                        $desc ?: 'Описание отсутствует',
                        (float) $item['price'],
                        (int)   $item['count'],
                        $item['manufacturer']
                    ),
                ],
            ];
        }
        $this->telegram->answerInlineQuery([
            'inline_query_id' => $id,
            'results'         => json_encode($results, JSON_UNESCAPED_UNICODE),
            'cache_time'      => 10,
        ]);
    }

    
    // отправка новых сообщений клиенту
    

    // отправляет главное меню с reply-клавиатурой внизу экрана
    private function sendMainMenu(int $chat, bool $greet = false): void
    {
        $cnt       = Cart::count($chat);
        $cartLabel = self::M_CART . ($cnt > 0 ? " ($cnt)" : '');
        $kb = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false)
            ->row([Keyboard::button(self::M_CATALOG), Keyboard::button(self::M_SEARCH)])
            ->row([Keyboard::button($cartLabel),      Keyboard::button(self::M_ORDERS)])
            ->row([Keyboard::button(self::M_FAQ)]);
        $text = ($greet ? "Добро пожаловать в наш магазин!\n\n" : '') . "Главное меню:";
        $this->telegram->sendMessage(['chat_id' => $chat, 'text' => $text, 'reply_markup' => $kb]);
    }

    // отправляет список категорий каталога
    private function sendCatalog(int $chat): void
    {
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "Каталог товаров\n\nВыберите категорию:",
            'reply_markup' => $this->kbCatalog(),
        ]);
    }

    // отправляет меню faq
    private function sendFaq(int $chat): void
    {
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "FAQ — часто задаваемые вопросы\n\nВыберите тему:",
            'reply_markup' => $this->kbFaq(),
        ]);
    }

    // отправляет содержимое корзины с кнопками управления
    private function sendCartMsg(int $chat): void
    {
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => Cart::summaryText($chat),
            'reply_markup' => $this->kbCart($chat),
        ]);
    }

    // отправляет список заказов текущего пользователя
    private function sendMyOrders(int $chat): void
    {
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "Мои заказы\n\nВыберите заказ для просмотра:",
            'reply_markup' => $this->kbMyOrders($chat),
        ]);
    }

    // переводит пользователя в режим ожидания поискового запроса
    private function startSearch(int $chat): void
    {
        $this->setState($chat, 'search');
        $this->send($chat, 'Введите название товара для поиска:');
    }

    // ищет товары и отправляет результаты в виде inline-кнопок
    private function sendSearchResults(int $chat, string $q): void
    {
        try {
            $rows = Database::searchItems($q);
        } catch (\Throwable $e) {
            $this->send($chat, 'Ошибка БД при поиске.');
            return;
        }
        if (empty($rows)) {
            $this->send($chat, 'Ничего не найдено.');
            return;
        }
        $btns = [];
        foreach ($rows as $r) {
            $btns[] = [Keyboard::inlineButton([
                'text'          => sprintf('%s — %.2f руб.', $r['name'], $r['price']),
                'callback_data' => 'item_' . $r['id'],
            ])];
        }
        $btns[] = [Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close'])];
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "Результаты поиска «{$q}»:",
            'reply_markup' => Keyboard::make(['inline_keyboard' => $btns]),
        ]);
    }

    // отправляет справку по командам бота
    private function sendHelp(int $chat): void
    {
        $text = "/start, /menu — главное меню\n/help — справка";
        if ($this->isAdmin($chat)) {
            $text .= "\n/admin — панель администратора";
        }
        $text .= "\n\nInline-режим: наберите @ваш_бот название в любом чате чтобы поделиться карточкой товара.";
        $this->send($chat, $text);
    }

    
    // редактирование существующих сообщений — навигация без новых сообщений
    

    // показывает список категорий каталога редактируя текущее сообщение
    private function editCatalog(int $chat, int $mid): void
    {
        $this->edit($chat, $mid, "Каталог товаров\n\nВыберите категорию:", $this->kbCatalog());
    }

    // показывает товары выбранной категории редактируя текущее сообщение
    private function editItems(int $chat, int $mid, int $catId): void
    {
        try {
            $rows = Database::getItemsByCategory($catId);
        } catch (\Throwable $e) {
            $this->edit($chat, $mid, 'Ошибка БД.', Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => '◀️ Назад', 'callback_data' => 'catalog']),
            ]]]));
            return;
        }
        if (empty($rows)) {
            $this->edit($chat, $mid, 'В этой категории нет товаров.', Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => '◀️ К категориям', 'callback_data' => 'catalog']),
            ]]]));
            return;
        }
        $btns = [];
        foreach ($rows as $r) {
            $btns[] = [Keyboard::inlineButton([
                'text'          => sprintf('%s — %.2f руб.', $r['name'], $r['price']),
                'callback_data' => 'item_' . $r['id'],
            ])];
        }
        $btns[] = [
            Keyboard::inlineButton(['text' => '◀️ К категориям', 'callback_data' => 'catalog']),
            Keyboard::inlineButton(['text' => '❌ Закрыть',       'callback_data' => 'close']),
        ];
        $this->edit($chat, $mid, "Товары\n\nВыберите товар:", Keyboard::make(['inline_keyboard' => $btns]));
    }

    // показывает карточку товара с ценой, наличием и кнопкой добавления в корзину
    private function editItem(int $chat, int $mid, int $itemId): void
    {
        $item = $this->dbItem($itemId);
        if (!$item) {
            $this->editMsg($chat, $mid, 'Товар не найден.');
            return;
        }
        $avail = (int) $item['count'] > 0 ? "В наличии: {$item['count']} шт." : "Нет в наличии";
        $desc  = trim($item['description'] ?? '');
        $text  = implode("\n", [
            $item['name'], '',
            $desc ?: 'Описание отсутствует', '',
            "Цена: " . number_format((float) $item['price'], 2, '.', ' ') . " руб.",
            $avail,
            "Производитель: " . $item['manufacturer'],
            "Категория: "     . $item['category_name'],
        ]);
        // показываем сколько уже в корзине если есть
        $qty      = Cart::get($chat)[(string) $itemId]['qty'] ?? 0;
        $addLabel = $qty > 0 ? "🧺 В корзине: {$qty} шт. (+ ещё)" : "🧺 Добавить в корзину";
        $kb = Keyboard::make(['inline_keyboard' => [
            [Keyboard::inlineButton(['text' => $addLabel, 'callback_data' => 'add_' . $itemId])],
            [Keyboard::inlineButton(['text' => '📤 Поделиться товаром', 'switch_inline_query' => $item['name']])],
            [
                Keyboard::inlineButton(['text' => '◀️ Назад',    'callback_data' => 'cat_' . $item['category_id']]),
                Keyboard::inlineButton(['text' => '🧺 Корзина',  'callback_data' => 'cart']),
                Keyboard::inlineButton(['text' => '❌ Закрыть',  'callback_data' => 'close']),
            ],
        ]]);
        $this->edit($chat, $mid, $text, $kb);
    }

    // обновляет сообщение с корзиной
    private function editCart(int $chat, int $mid): void
    {
        $this->edit($chat, $mid, Cart::summaryText($chat), $this->kbCart($chat));
    }

    // показывает экран выбора способа оплаты редактируя сообщение с корзиной
    private function editOrderPayment(int $chat, int $mid): void
    {
        $text = Cart::summaryText($chat) . "\n\nВыберите способ оплаты:";
        $kb   = Keyboard::make(['inline_keyboard' => [
            [
                Keyboard::inlineButton(['text' => '💵 Наличные', 'callback_data' => 'pay_Наличные']),
                Keyboard::inlineButton(['text' => '💳 Карта',    'callback_data' => 'pay_Карта']),
                Keyboard::inlineButton(['text' => '📱 СБП',      'callback_data' => 'pay_СБП']),
            ],
            [Keyboard::inlineButton(['text' => '◀️ Назад в корзину', 'callback_data' => 'cart'])],
        ]]);
        $this->edit($chat, $mid, $text, $kb);
    }

    // обновляет сообщение со списком заказов клиента
    private function editMyOrders(int $chat, int $mid): void
    {
        $this->edit($chat, $mid, "Мои заказы\n\nВыберите заказ:", $this->kbMyOrders($chat));
    }

    // показывает детальную информацию об одном заказе клиента
    // клиент видит только свои заказы — проверяем что telegram_id совпадает
    private function editClientOrder(int $chat, int $mid, int $orderId): void
    {
        try {
            $orders = Database::getOrdersByUser($chat);
        } catch (\Throwable $e) {
            $this->editMsg($chat, $mid, 'Ошибка БД.');
            return;
        }
        // ищем заказ среди заказов этого пользователя
        $order = null;
        foreach ($orders as $o) {
            if ((int) $o['id'] === $orderId) {
                $order = $o;
                break;
            }
        }
        // если заказ не найден или принадлежит другому — отказываем
        if (!$order) {
            $this->editMsg($chat, $mid, 'Заказ не найден.');
            return;
        }
        $items = json_decode($order['items_json'], true) ?? [];
        // иконка статуса для наглядности
        $statusIcon = match ($order['status']) {
            'confirmed'  => '✅',
            'shipped'    => '🚚',
            'cancelled'  => '❌',
            default      => '🆕',
        };
        $statusLabel = match ($order['status']) {
            'new'        => 'Новый',
            'confirmed'  => 'Подтверждён',
            'shipped'    => 'Отправлен',
            'cancelled'  => 'Отменён',
            default      => $order['status'],
        };
        $lines = [
            "Заказ #{$order['id']} {$statusIcon}",
            "Дата: {$order['created_at']}",
            "Статус: {$statusLabel}",
            "Оплата: {$order['payment_method']}",
            "",
            "Состав:",
        ];
        foreach ($items as $it) {
            $lines[] = sprintf("  • %s x%d = %.2f руб.", $it['name'], $it['qty'], $it['price'] * $it['qty']);
        }
        $lines[] = sprintf("\nИтого: %.2f руб.", $order['total']);
        $kb = Keyboard::make(['inline_keyboard' => [[
            Keyboard::inlineButton(['text' => '◀️ К моим заказам', 'callback_data' => 'my_orders']),
            Keyboard::inlineButton(['text' => '❌ Закрыть',         'callback_data' => 'close']),
        ]]]);
        $this->edit($chat, $mid, implode("\n", $lines), $kb);
    }

    // показывает список тем faq редактируя сообщение
    private function editFaq(int $chat, int $mid): void
    {
        $this->edit($chat, $mid, "FAQ — часто задаваемые вопросы\n\nВыберите тему:", $this->kbFaq());
    }

    // показывает ответ на выбранную тему faq редактируя сообщение
    private function editFaqTopic(int $chat, int $mid, string $topic): void
    {
        $ans = $this->faqAnswers();
        $kb  = Keyboard::make(['inline_keyboard' => [[
            Keyboard::inlineButton(['text' => '◀️ Назад к FAQ', 'callback_data' => 'faq']),
            Keyboard::inlineButton(['text' => '❌ Закрыть',      'callback_data' => 'close']),
        ]]]);
        $this->edit($chat, $mid, $ans[$topic] ?? 'Ответ не найден.', $kb);
    }

    
    // оформление заказа
    

    private function processOrder(int $chat, int $mid, $cbObj, string $paymentMethod): void
    {
        // корзина хранит item_id как ключ: ['42' => ['name'=>..,'price'=>..,'qty'=>..], ...]
        $cart = Cart::get($chat);
        if (empty($cart)) {
            $this->answerCb($cbObj->id, 'Корзина пуста!');
            return;
        }
        $total    = Cart::total($chat);
        $from     = $cbObj->message->chat;
        $userName = trim(($from->firstName ?? '') . ' ' . ($from->lastName ?? ''));
        if (empty($userName)) {
            $userName = $from->username ?? 'Пользователь';
        }
        // сохраняем заказ и списываем остатки в одной транзакции
        $orderId = 0;
        try {
            $orderId = Database::saveOrderAndReduceStock($chat, $userName, $cart, $total, $paymentMethod);
        } catch (\RuntimeException $e) {
            // товара не хватает — сообщаем и оставляем корзину нетронутой
            $this->editMsg($chat, $mid,
                "Не удалось оформить заказ:\n" . $e->getMessage() .
                "\n\nПожалуйста, уменьшите количество или удалите товар из корзины."
            );
            $this->telegram->sendMessage([
                'chat_id'      => $chat,
                'text'         => Cart::summaryText($chat),
                'reply_markup' => $this->kbCart($chat),
            ]);
            return;
        } catch (\Throwable $e) {
            $this->editMsg($chat, $mid, 'Ошибка базы данных при оформлении заказа. Попробуйте позже.');
            echo "ошибка сохранения заказа: {$e->getMessage()}\n";
            return;
        }
        // отправляем письмо администратору
        try {
            Mailer::sendOrderNotification($orderId, $chat, $userName, array_values($cart), $total, $paymentMethod);
        } catch (\Throwable $e) {
            echo "ошибка отправки письма: {$e->getMessage()}\n";
        }
        // очищаем корзину только после успешного сохранения
        Cart::clear($chat);
        // формируем подтверждение для клиента
        $lines = ["Заказ #{$orderId} оформлен!\n"];
        foreach ($cart as $item) {
            $lines[] = sprintf("• %s x%d = %.2f руб.", $item['name'], $item['qty'], $item['price'] * $item['qty']);
        }
        $lines[] = sprintf("\nИтого: %.2f руб.", $total);
        $lines[] = "Оплата: {$paymentMethod}";
        $lines[] = "\nСпасибо за покупку! Мы свяжемся с вами.";
        $this->editMsg($chat, $mid, implode("\n", $lines));
        $this->sendMainMenu($chat);
    }

    
    // панель администратора
    

    // отправляет меню администратора с reply-клавиатурой
    private function sendAdminMenu(int $chat): void
    {
        $kb = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false)
            ->row([Keyboard::button(self::A_ORDERS), Keyboard::button(self::A_SEARCH)])
            ->row([Keyboard::button(self::A_EXIT)]);
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "Панель администратора\n\nВыберите действие:",
            'reply_markup' => $kb,
        ]);
    }

    // отправляет список всех заказов магазина
    private function sendAdminOrders(int $chat): void
    {
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "Все заказы:",
            'reply_markup' => $this->kbAdminOrders(),
        ]);
    }

    // переводит администратора в режим ожидания поискового запроса по заказам
    private function startAdminOrderSearch(int $chat): void
    {
        $this->setState($chat, 'admin_search_order');
        $this->send($chat, 'Введите Telegram ID или номер заказа:');
    }

    // ищет заказы по номеру или telegram id и показывает результат
    private function sendAdminOrderSearch(int $chat, string $query): void
    {
        $rows = [];
        try {
            if (is_numeric($query)) {
                $all = Database::getAllOrders();
                foreach ($all as $o) {
                    if ($o['telegram_id'] == (int) $query || $o['id'] == (int) $query) {
                        $rows[] = $o;
                    }
                }
            }
        } catch (\Throwable $e) {}
        if (empty($rows)) {
            $this->send($chat, "Заказы по запросу «{$query}» не найдены.");
            return;
        }
        $btns = [];
        foreach (array_slice($rows, 0, 20) as $o) {
            $btns[] = [Keyboard::inlineButton([
                'text'          => sprintf('#%d | %s | %s | %.2f руб.', $o['id'], $o['created_at'], $o['status'], $o['total']),
                'callback_data' => 'aord_' . $o['id'],
            ])];
        }
        $btns[] = [Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close'])];
        $this->telegram->sendMessage([
            'chat_id'      => $chat,
            'text'         => "Результаты поиска:",
            'reply_markup' => Keyboard::make(['inline_keyboard' => $btns]),
        ]);
    }

    // обновляет сообщение со списком всех заказов
    private function editAdminOrders(int $chat, int $mid): void
    {
        $this->edit($chat, $mid, "Все заказы:", $this->kbAdminOrders());
    }

    // показывает детальную информацию о заказе с кнопками смены статуса
    private function editAdminOrder(int $chat, int $mid, int $orderId): void
    {
        try {
            $all = Database::getAllOrders();
        } catch (\Throwable $e) {
            $this->editMsg($chat, $mid, 'Ошибка БД.');
            return;
        }
        $order = null;
        foreach ($all as $o) {
            if ((int) $o['id'] === $orderId) {
                $order = $o;
                break;
            }
        }
        if (!$order) {
            $this->editMsg($chat, $mid, 'Заказ не найден.');
            return;
        }
        $items = json_decode($order['items_json'], true) ?? [];
        $lines = [
            "Заказ #{$order['id']}",
            "Дата: {$order['created_at']}",
            "Покупатель: {$order['telegram_name']} (ID: {$order['telegram_id']})",
            "Статус: {$order['status']}",
            "Оплата: {$order['payment_method']}",
            "",
            "Состав:",
        ];
        foreach ($items as $it) {
            $lines[] = sprintf("  • %s x%d = %.2f руб.", $it['name'], $it['qty'], $it['price'] * $it['qty']);
        }
        $lines[] = sprintf("\nИтого: %.2f руб.", $order['total']);
        $kb = Keyboard::make(['inline_keyboard' => [
            [
                Keyboard::inlineButton(['text' => '✅ Подтвердить', 'callback_data' => "ast_{$orderId}_confirmed"]),
                Keyboard::inlineButton(['text' => '🚚 Отправлен',   'callback_data' => "ast_{$orderId}_shipped"]),
                Keyboard::inlineButton(['text' => '❌ Отменить',     'callback_data' => "ast_{$orderId}_cancelled"]),
            ],
            [
                Keyboard::inlineButton(['text' => '◀️ К заказам', 'callback_data' => 'admin_orders']),
                Keyboard::inlineButton(['text' => '❌ Закрыть',    'callback_data' => 'close']),
            ],
        ]]);
        $this->edit($chat, $mid, implode("\n", $lines), $kb);
    }

    
    // строители клавиатур
    

    // строит inline-клавиатуру из категорий каталога
    private function kbCatalog(): Keyboard
    {
        try {
            $cats = Database::getCategories();
        } catch (\Throwable $e) {
            return Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => 'Ошибка БД', 'callback_data' => 'close']),
            ]]]);
        }
        $btns = [];
        foreach ($cats as $c) {
            $btns[] = [Keyboard::inlineButton(['text' => $c['name'], 'callback_data' => 'cat_' . $c['id']])];
        }
        $btns[] = [Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close'])];
        return Keyboard::make(['inline_keyboard' => $btns]);
    }

    // строит inline-клавиатуру корзины с кнопками удаления и оформления заказа
    private function kbCart(int $chat): Keyboard
    {
        $cart = Cart::get($chat);
        $btns = [];
        if (!empty($cart)) {
            foreach ($cart as $id => $row) {
                $btns[] = [Keyboard::inlineButton([
                    'text'          => "❌ Удалить: {$row['name']}",
                    'callback_data' => 'rm_' . $id,
                ])];
            }
            $btns[] = [
                Keyboard::inlineButton(['text' => '🗑 Очистить',       'callback_data' => 'cart_clear']),
                Keyboard::inlineButton(['text' => '✅ Оформить заказ', 'callback_data' => 'cart_order']),
            ];
        }
        $btns[] = [
            Keyboard::inlineButton(['text' => '🛒 Каталог', 'callback_data' => 'catalog']),
            Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close']),
        ];
        return Keyboard::make(['inline_keyboard' => $btns]);
    }

    // строит inline-клавиатуру со списком заказов текущего клиента
    private function kbMyOrders(int $chat): Keyboard
    {
        try {
            $orders = Database::getOrdersByUser($chat);
        } catch (\Throwable $e) {
            return Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => 'Ошибка БД', 'callback_data' => 'close']),
            ]]]);
        }
        if (empty($orders)) {
            return Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => 'У вас пока нет заказов', 'callback_data' => 'close']),
            ]]]);
        }
        $btns = [];
        foreach ($orders as $o) {
            // иконка статуса для наглядности в списке
            $icon = match ($o['status']) {
                'confirmed' => '✅',
                'shipped'   => '🚚',
                'cancelled' => '❌',
                default     => '🆕',
            };
            $btns[] = [Keyboard::inlineButton([
                'text'          => sprintf('%s #%d | %s | %.2f руб.', $icon, $o['id'], substr($o['created_at'], 0, 16), $o['total']),
                'callback_data' => 'cord_' . $o['id'],
            ])];
        }
        $btns[] = [Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close'])];
        return Keyboard::make(['inline_keyboard' => $btns]);
    }

    // строит inline-клавиатуру со списком всех заказов для администратора
    private function kbAdminOrders(): Keyboard
    {
        try {
            $orders = Database::getAllOrders();
        } catch (\Throwable $e) {
            return Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => 'Ошибка БД', 'callback_data' => 'close']),
            ]]]);
        }
        if (empty($orders)) {
            return Keyboard::make(['inline_keyboard' => [[
                Keyboard::inlineButton(['text' => 'Заказов нет', 'callback_data' => 'close']),
            ]]]);
        }
        $btns = [];
        foreach (array_slice($orders, 0, 20) as $o) {
            $icon = match ($o['status']) {
                'confirmed' => '✅',
                'shipped'   => '🚚',
                'cancelled' => '❌',
                default     => '🆕',
            };
            $btns[] = [Keyboard::inlineButton([
                'text'          => sprintf('%s #%d | %s | %s | %.2f руб.', $icon, $o['id'], substr($o['created_at'], 0, 16), $o['telegram_name'], $o['total']),
                'callback_data' => 'aord_' . $o['id'],
            ])];
        }
        $btns[] = [Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close'])];
        return Keyboard::make(['inline_keyboard' => $btns]);
    }

    // строит inline-клавиатуру тем faq
    private function kbFaq(): Keyboard
    {
        $topics = [
            'delivery' => '🚚 Доставка',
            'payment'  => '💳 Оплата',
            'return'   => '↩️ Возврат',
            'warranty' => '🛡 Гарантия',
            'howto'    => '📦 Как выбрать',
            'contacts' => '📞 Контакты',
        ];
        $btns = [];
        foreach ($topics as $k => $v) {
            $btns[] = [Keyboard::inlineButton(['text' => $v, 'callback_data' => 'faq_' . $k])];
        }
        $btns[] = [Keyboard::inlineButton(['text' => '❌ Закрыть', 'callback_data' => 'close'])];
        return Keyboard::make(['inline_keyboard' => $btns]);
    }

    
    // тексты ответов faq

    private function faqAnswers(): array
    {
        return [
            'delivery' => "Доставка\n\n• Курьер: 1–3 рабочих дня\n• Самовывоз: бесплатно\n• Регионы: 3–7 дней",
            'payment'  => "Оплата\n\n• Наличные при получении\n• Банковская карта\n• Онлайн-платёж (СБП)\n• Безналичный расчёт",
            'return'   => "Возврат\n\nВозврат в течение 14 дней.\n• Оригинальная упаковка\n• Товар не использовался\n• Наличие чека",
            'warranty' => "Гарантия\n\n• Техника: 12–24 месяца\n• Прочие товары: уточняйте\nГарантийный ремонт — бесплатно.",
            'howto'    => "Как выбрать товар\n\nИспользуйте Каталог по категориям или Поиск.\n\nНа карточке: описание, цена, наличие, производитель.",
            'contacts' => "Контакты\n\nАдрес: г. Донецк, пр. Театральный, д. 13\nТелефон: +7 (949) 123-45-67\nEmail: badron.korol@gmail.com\nРежим работы: Пн–Вс 9:00–18:00",
        ];
    }
    
    // низкоуровневые хелперы для работы с telegram api

    // загружает товар из бд, при ошибке возвращает null
    private function dbItem(int $id): ?array
    {
        try {
            return Database::getItemById($id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // отправляет простое текстовое сообщение
    private function send(int $chat, string $text): void
    {
        $this->telegram->sendMessage(['chat_id' => $chat, 'text' => $text]);
    }

    // редактирует только текст существующего сообщения без клавиатуры
    private function editMsg(int $chat, int $mid, string $text): void
    {
        try {
            $this->telegram->editMessageText(['chat_id' => $chat, 'message_id' => $mid, 'text' => $text]);
        } catch (\Throwable $e) {}
    }

    // редактирует текст и inline-клавиатуру существующего сообщения
    private function edit(int $chat, int $mid, string $text, Keyboard $kb): void
    {
        try {
            $this->telegram->editMessageText([
                'chat_id'      => $chat,
                'message_id'   => $mid,
                'text'         => $text,
                'reply_markup' => $kb,
            ]);
        } catch (\Throwable $e) {}
    }

    // отвечает на callback-запрос чтобы убрать часики, опционально показывает всплывающий текст
    private function answerCb(string $id, string $text = ''): void
    {
        $p = ['callback_query_id' => $id];
        if ($text !== '') {
            $p['text']       = $text;
            $p['show_alert'] = false;
        }
        try {
            $this->telegram->answerCallbackQuery($p);
        } catch (\Throwable $e) {}
    }

    // хранение состояний пользователей в json-файле

    // читает состояния из файла при старте бота
    private function loadStates(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_exists($this->stateFile)) {
            $this->states = json_decode(file_get_contents($this->stateFile), true) ?? [];
        }
    }

    // сохраняет все состояния в файл
    private function saveStates(): void
    {
        file_put_contents($this->stateFile, json_encode($this->states));
    }

    // возвращает текущее состояние пользователя или null если его нет
    private function getState(int $chat): ?string
    {
        return $this->states[(string) $chat] ?? null;
    }

    // устанавливает состояние пользователя и сохраняет в файл
    private function setState(int $chat, string $state): void
    {
        $this->states[(string) $chat] = $state;
        $this->saveStates();
    }

    // удаляет состояние пользователя и сохраняет изменения
    private function clearState(int $chat): void
    {
        unset($this->states[(string) $chat]);
        $this->saveStates();
    }
}