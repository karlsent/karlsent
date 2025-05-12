<?php

// ВНИМАНИЕ: Хранение API-ключей непосредственно в коде небезопасно.
// Рекомендуется использовать переменные окружения для производственной среды.

return [
    'telegram_bot_token' => 'YOUR_TELEGRAM_BOT_TOKEN_HERE',
    'telegram_bot_username' => 'your_telegram_bot_username_here', // Используется для определения, что сообщение адресовано боту
    'gemini_api_key' => 'YOUR_GEMINI_API_KEY_HERE',
    'gemini_api_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent', // Или актуальная модель, например gemini-1.5-flash

    // Настройки OpenAI
    'openai_api_key' => 'YOUR_OPENAI_API_KEY_HERE',
    'openai_api_url' => 'https://api.openai.com/v1/chat/completions',
    'openai_model' => 'gpt-4', // Или gpt-3.5-turbo, или другая доступная модель

    // Выбор AI провайдера ('gemini' или 'openai')
    'active_ai_provider' => 'openai', // ИЗМЕНИТЕ НА 'gemini', если хотите использовать Gemini

    // Настройки ролей AI
    'default_ai_role' => 'Ты — дружелюбный и очень полезный ИИ-ассистент.', // Роль по умолчанию
    'chat_roles_storage_path' => __DIR__ . '/../storage/chat_roles.json', // Файл для хранения кастомных ролей чатов

    // Ключевые слова для триггера AI (поиск без учета регистра)
    'monitored_keywords' => [
        "ключевое_слово1", "keyword2",
        // Добавьте сюда ваши ключевые слова
      ],

    'message_history_limit' => 15, // Количество сообщений для хранения в истории
    'message_history_storage_path' => __DIR__ . '/../history/', // Путь к директории для истории сообщений

    'webhook_url' => 'https://your_domain.com/path_to_your_bot/public/index.php', // ЗАМЕНИТЕ НА ВАШ РЕАЛЬНЫЙ URL
    'admin_telegram_id' => null, // ID вашего Telegram аккаунта для получения уведомлений об ошибках (опционально)

    // Настройки логирования
    'log_path' => __DIR__ . '/../logs/bot.log', // Путь к файлу логов относительно директории config
    'debug_mode' => true, // Включить DEBUG уровень логирования (false для продакшена)

    // Настройки проактивного вовлечения
    'enable_proactive_engagement' => true, // Включить/выключить проактивное вовлечение
    'proactive_engagement_message_threshold' => 10, // Количество сообщений пользователей без участия бота, после которого бот вмешается
    'proactive_engagement_system_role' => 'Ты AI-ассистент. Текущий диалог в группе продолжается без твоего активного участия уже некоторое время. Твоя задача - вежливо, кратко и по теме подключиться к обсуждению, сделав релевантный комментарий или задав уточняющий вопрос, основываясь на последних сообщениях. Не повторяй то, что уже было сказано. Старайся быть максимально естественным и полезным участником беседы.',
    'proactive_engagement_user_prompt_template' => "Контекст последних сообщений в чате:\n%s\n\nТвоя задача: Внимательно изучи последние сообщения. Вежливо, кратко и по существу подключись к текущему обсуждению в группе. Сделай релевантный комментарий или задай уточняющий вопрос. Не представляйся и не напоминай о своей роли без необходимости.",
    'bot_history_prefix' => 'Bot: ', // Префикс для сообщений бота в файлах истории

    // Статистика использования токенов
    'token_usage_storage_path' => __DIR__ . '/../storage/token_usage/', // Путь к директории для файлов статистики токенов
]; 