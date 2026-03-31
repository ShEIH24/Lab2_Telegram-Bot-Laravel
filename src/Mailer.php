<?php

class Mailer
{
    // отправляет письмо администратору о новом заказе
    // если smtp_host задан в конфиге — использует phpmailer, иначе встроенный mail()
    // при любой ошибке дублирует письмо в лог-файл storage/mail_log/orders.log
    public static function sendOrderNotification(
        int    $orderId,
        int    $telegramId,
        string $telegramName,
        array  $cartItems,
        float  $total,
        string $paymentMethod
    ): bool {
        $cfg     = require __DIR__ . '/../config.php';
        $subject = "Новый заказ #{$orderId} в магазине";
        $body    = self::buildBody($orderId, $telegramId, $telegramName, $cartItems, $total, $paymentMethod);

        // если в конфиге есть smtp_host — отправляем через phpmailer
        if (!empty($cfg['smtp_host'])) {
            return self::sendSmtp($cfg, $subject, $body);
        }

        // иначе используем встроенную функцию mail()
        return self::sendNative($cfg, $subject, $body);
    }

    // отправляет письмо через smtp с помощью библиотеки phpmailer
    private static function sendSmtp(array $cfg, string $subject, string $body): bool
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            echo "PHPMailer не установлен. Запустите: composer require phpmailer/phpmailer\n";
            self::writeLog($subject, $body, $cfg['admin_email']);
            return false;
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $cfg['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['smtp_user'];
            $mail->Password   = $cfg['smtp_pass'];
            $mail->SMTPSecure = $cfg['smtp_secure'] ?? 'tls';
            $mail->Port       = $cfg['smtp_port']   ?? 587;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($cfg['mail_from'], $cfg['mail_from_name']);
            $mail->addAddress($cfg['admin_email']);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            echo "Ошибка отправки smtp: {$e->getMessage()}\n";
            self::writeLog($subject, $body, $cfg['admin_email']);
            return false;
        }
    }

    // отправляет письмо через встроенную функцию mail() php
    private static function sendNative(array $cfg, string $subject, string $body): bool
    {
        $headers = implode("\r\n", [
            "From: {$cfg['mail_from_name']} <{$cfg['mail_from']}>",
            "Reply-To: {$cfg['mail_from']}",
            "Content-Type: text/plain; charset=UTF-8",
            "X-Mailer: PHP/" . PHP_VERSION,
        ]);
        $result = @mail($cfg['admin_email'], $subject, $body, $headers);
        // всегда дублируем в лог для отладки
        self::writeLog($subject, $body, $cfg['admin_email']);
        return $result;
    }

    // формирует текст письма из данных заказа
    private static function buildBody(
        int    $orderId,
        int    $telegramId,
        string $telegramName,
        array  $cartItems,
        float  $total,
        string $paymentMethod
    ): string {
        $lines = [
            "Новый заказ #{$orderId}",
            str_repeat('-', 40),
            "Покупатель : {$telegramName}",
            "Telegram ID: {$telegramId}",
            "Способ оплаты: {$paymentMethod}",
            "",
            "Состав заказа:",
        ];
        foreach ($cartItems as $it) {
            $lines[] = sprintf("  - %s x%d = %.2f руб.", $it['name'], $it['qty'], $it['price'] * $it['qty']);
        }
        $lines[] = "";
        $lines[] = sprintf("Итого: %.2f руб.", $total);
        $lines[] = str_repeat('-', 40);
        $lines[] = "Дата: " . date('d.m.Y H:i:s');
        return implode("\r\n", $lines);
    }

    // пишет письмо в лог-файл — используется как запасной вариант если mail() не работает
    private static function writeLog(string $subject, string $body, string $to): void
    {
        $dir = __DIR__ . '/../storage/mail_log';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $entry = sprintf("[%s] TO: %s | %s\n%s\n%s\n\n", date('Y-m-d H:i:s'), $to, $subject, str_repeat('-', 40), $body);
        file_put_contents($dir . '/orders.log', $entry, FILE_APPEND);
    }
}