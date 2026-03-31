<?php

class Database
{
    private static ?PDO $instance = null;

    // возвращает единственное подключение к базе данных
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = require __DIR__ . '/../config.php';
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['db_host'], $cfg['db_name'], $cfg['db_charset']);
            self::$instance = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$instance;
    }

    // возвращает все категории товаров отсортированные по имени
    public static function getCategories(): array
    {
        return self::getInstance()->query('SELECT id, name FROM category_items ORDER BY name')->fetchAll();
    }

    // возвращает список товаров в заданной категории
    public static function getItemsByCategory(int $categoryId): array
    {
        $s = self::getInstance()->prepare('SELECT id, name, price, count FROM items WHERE category_id = ? ORDER BY name');
        $s->execute([$categoryId]);
        return $s->fetchAll();
    }

    // возвращает один товар по id вместе с названием категории, или null если не найден
    public static function getItemById(int $id): ?array
    {
        $s = self::getInstance()->prepare(
            'SELECT i.*, c.name AS category_name FROM items i JOIN category_items c ON c.id = i.category_id WHERE i.id = ?'
        );
        $s->execute([$id]);
        $row = $s->fetch();
        return $row ?: null;
    }

    // ищет товары по подстроке в названии или описании, возвращает не более 20 штук
    public static function searchItems(string $q): array
    {
        $s = self::getInstance()->prepare(
            'SELECT id, name, price, count FROM items WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT 20'
        );
        $like = '%' . $q . '%';
        $s->execute([$like, $like]);
        return $s->fetchAll();
    }

    // возвращает первые 50 товаров для inline-режима когда запрос пустой
    public static function getAllItems(): array
    {
        return self::getInstance()->query('SELECT id, name, price FROM items ORDER BY name LIMIT 50')->fetchAll();
    }

    // сохраняет заказ и уменьшает остатки товаров в одной транзакции
    // если какого-то товара не хватает — откатывает транзакцию и бросает RuntimeException
    public static function saveOrderAndReduceStock(
        int    $telegramId,
        string $telegramName,
        array  $cartItems,
        float  $total,
        string $paymentMethod
    ): int {
        $pdo = self::getInstance();
        $pdo->beginTransaction();
        try {
            // проверяем и списываем остатки по каждой позиции корзины
            foreach ($cartItems as $itemId => $row) {
                $qty = (int) $row['qty'];
                // блокируем строку чтобы два одновременных заказа не списали лишнее
                $check = $pdo->prepare('SELECT count FROM items WHERE id = ? FOR UPDATE');
                $check->execute([$itemId]);
                $stock = $check->fetchColumn();
                if ($stock === false) {
                    throw new \RuntimeException("Товар «{$row['name']}» не найден в базе.");
                }
                if ((int) $stock < $qty) {
                    throw new \RuntimeException(
                        "Товара «{$row['name']}» недостаточно на складе. Запрошено: {$qty} шт., доступно: {$stock} шт."
                    );
                }
                // уменьшаем остаток на складе
                $upd = $pdo->prepare('UPDATE items SET count = count - ? WHERE id = ?');
                $upd->execute([$qty, $itemId]);
            }
            // сохраняем сам заказ
            $ins = $pdo->prepare(
                'INSERT INTO bot_orders (telegram_id, telegram_name, items_json, total, payment_method, status)
                 VALUES (?, ?, ?, ?, ?, "new")'
            );
            $ins->execute([
                $telegramId,
                $telegramName,
                json_encode(array_values($cartItems), JSON_UNESCAPED_UNICODE),
                $total,
                $paymentMethod,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $orderId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // возвращает последние 100 заказов для панели администратора
    public static function getAllOrders(): array
    {
        return self::getInstance()->query(
            'SELECT id, telegram_id, telegram_name, total, payment_method, status, created_at, items_json
             FROM bot_orders ORDER BY created_at DESC LIMIT 100'
        )->fetchAll();
    }

    // возвращает заказы конкретного пользователя по его telegram id
    public static function getOrdersByUser(int $telegramId): array
    {
        $s = self::getInstance()->prepare(
            'SELECT id, total, payment_method, status, created_at, items_json
             FROM bot_orders WHERE telegram_id = ? ORDER BY created_at DESC LIMIT 20'
        );
        $s->execute([$telegramId]);
        return $s->fetchAll();
    }

    // обновляет статус заказа по его id
    public static function updateOrderStatus(int $orderId, string $status): void
    {
        $s = self::getInstance()->prepare('UPDATE bot_orders SET status = ? WHERE id = ?');
        $s->execute([$status, $orderId]);
    }
}