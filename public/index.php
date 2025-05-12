<?php

declare(strict_types=1);

// Загрузка автозагрузчика Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/../config/config.php';

// Простой роутинг (можно заменить на более продвинутый роутер при необходимости)
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Логика обработки веб-хука Telegram
// Telegram отправляет POST-запросы на webhook URL
if ($requestMethod === 'POST') {
    // Предполагается, что все POST запросы на index.php - это от Telegram
    // В реальном приложении здесь может быть более сложная логика роутинга
    $controller = new App\Controllers\TelegramController($config);
    $controller->handleUpdate();
} else {
    // Ответ для GET запросов (например, для проверки доступности или для страницы установки веб-хука)
    // Для простоты, если это не POST, можно просто отдать 404 или информационное сообщение
    // Если вы хотите иметь отдельный скрипт для установки вебхука,
    // то можно добавить сюда логику или вызывать App\Controllers\TelegramController->setWebhook();
    if (strpos($requestUri, '/set-webhook') !== false) {
        $controller = new App\Controllers\TelegramController($config);
        $controller->registerWebhook();
    } else {
        http_response_code(200);
        echo "Telegram Bot is ready. Use /set-webhook (GET-запрос) по этому же URL для регистрации веб-хука в Telegram (если еще не настроен).";
    }
}
 