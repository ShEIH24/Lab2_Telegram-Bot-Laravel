<?php

// корзина пользователя хранится в json-файле в папке storage/carts/{chatId}.json
// структура файла: { "item_id": { "name": "...", "price": 0.0, "qty": 1 }, ... }
class Cart
{
    // возвращает путь к папке с корзинами и создаёт её если нет
    private static function dir(): string
    {
        $d = __DIR__ . '/../storage/carts';
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        return $d;
    }

    // возвращает путь к файлу корзины конкретного пользователя
    private static function path(int $chatId): string
    {
        return self::dir() . '/' . $chatId . '.json';
    }

    // читает корзину из файла и возвращает массив, при отсутствии файла — пустой массив
    public static function get(int $chatId): array
    {
        $p = self::path($chatId);
        if (!file_exists($p)) {
            return [];
        }
        return json_decode(file_get_contents($p), true) ?? [];
    }

    // добавляет товар в корзину или увеличивает количество если он уже там есть
    public static function add(int $chatId, int $itemId, string $name, float $price): void
    {
        $cart = self::get($chatId);
        $key  = (string) $itemId;
        if (isset($cart[$key])) {
            $cart[$key]['qty'] += 1;
        } else {
            $cart[$key] = ['name' => $name, 'price' => $price, 'qty' => 1];
        }
        file_put_contents(self::path($chatId), json_encode($cart, JSON_UNESCAPED_UNICODE));
    }

    // удаляет товар из корзины по его id
    public static function remove(int $chatId, int $itemId): void
    {
        $cart = self::get($chatId);
        unset($cart[(string) $itemId]);
        file_put_contents(self::path($chatId), json_encode($cart, JSON_UNESCAPED_UNICODE));
    }

    // полностью очищает корзину пользователя
    public static function clear(int $chatId): void
    {
        file_put_contents(self::path($chatId), '{}');
    }

    // считает итоговую сумму всех товаров в корзине
    public static function total(int $chatId): float
    {
        return array_sum(array_map(fn($r) => $r['price'] * $r['qty'], self::get($chatId)));
    }

    // считает общее количество единиц товара в корзине
    public static function count(int $chatId): int
    {
        return (int) array_sum(array_column(self::get($chatId), 'qty'));
    }

    // возвращает текстовое описание корзины для вывода пользователю
    public static function summaryText(int $chatId): string
    {
        $cart = self::get($chatId);
        if (empty($cart)) {
            return "Корзина пуста.";
        }
        $lines = ["Ваша корзина:\n"];
        foreach ($cart as $row) {
            $lines[] = sprintf("• %s x%d = %.2f руб.", $row['name'], $row['qty'], $row['price'] * $row['qty']);
        }
        $lines[] = sprintf("\nИтого: %.2f руб.", self::total($chatId));
        return implode("\n", $lines);
    }
}