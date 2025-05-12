<?php

namespace App\Controllers;

use App\Interfaces\AIModelInterface;
use App\Models\GeminiModel;
use App\Models\OpenAIModel;
use GuzzleHttp\Client;
use App\Services\TelegramAPIService;
use App\Services\MessageHistory;
use App\Services\LoggerService;
use App\Services\ChatRoleService;
use App\Services\TokenUsageService;

class TelegramController
{
    private array $config;
    private Client $httpClient;
    private TelegramAPIService $telegramService;
    private MessageHistory $messageHistory;
    private LoggerService $logger;
    private ?AIModelInterface $aiModel = null;
    private ChatRoleService $chatRoleService;
    private TokenUsageService $tokenUsageService;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = new LoggerService($config['log_path'], $config['debug_mode'] ?? false);
        $this->httpClient = new Client();

        $this->telegramService = new TelegramAPIService($config, $this->httpClient, $this->logger);
        $this->messageHistory = new MessageHistory(
            $config['message_history_limit'], 
            $config['message_history_storage_path'] ?? (__DIR__ . '/../../history/'), 
            $this->logger,
            $config['bot_history_prefix'] ?? 'Bot: '
        );
        
        $this->chatRoleService = new ChatRoleService(
            $config['chat_roles_storage_path'] ?? (__DIR__ . '/../../storage/chat_roles.json'),
            $config['default_ai_role'] ?? 'Ты полезный AI ассистент.',
            $this->logger
        );

        $this->tokenUsageService = new TokenUsageService(
            $config['token_usage_storage_path'] ?? (__DIR__ . '/../../storage/token_usage/'),
            $this->logger
        );

        $activeProvider = $this->config['active_ai_provider'] ?? 'openai';
        if ($activeProvider === 'gemini' && !empty($this->config['gemini_api_key'])) {
            $this->aiModel = new GeminiModel($this->config, $this->httpClient, $this->logger);
            $this->logger->info("AI Model: Gemini инициализирован");
        } elseif ($activeProvider === 'openai' && !empty($this->config['openai_api_key'])) {
            $this->aiModel = new OpenAIModel($this->config, $this->httpClient, $this->logger);
            $this->logger->info("AI Model: OpenAI инициализирован");
        } else {
            $this->logger->error("Ни один AI провайдер не сконфигурирован или отсутствуют ключи API. Бот не сможет генерировать ответы.");
        }

        $this->logger->info("TelegramController инициализирован");
    }

    /**
     * Устанавливает веб-хук для Telegram бота.
     */
    public function registerWebhook(): void
    {
        $this->logger->info("Запрос на установку веб-хука");
        $webhookUrl = $this->config['webhook_url'] ?? ''; 
        if (empty($webhookUrl)) {
            $this->logger->error("URL веб-хука не указан в конфигурации.");
            echo "Ошибка: URL веб-хука не сконфигурирован.";
            return;
        }
        $result = $this->telegramService->setWebhook($webhookUrl);
        if ($result && isset($result['ok']) && $result['ok']) {
            $this->logger->info("Веб-хук успешно установлен", ['response' => $result]);
            echo "Веб-хук успешно установлен: " . ($result['description'] ?? 'OK');
        } else {
            $this->logger->error("Ошибка установки веб-хука", ['response' => $result]);
            echo "Ошибка установки веб-хука: " . ($result['description'] ?? 'Неизвестная ошибка');
        }
    }

    /**
     * Обрабатывает входящее обновление от Telegram.
     */
    public function handleUpdate(): void
    {
        $updateJson = file_get_contents('php://input');
        if (!$updateJson) {
            $this->logger->error("Не удалось получить данные php://input");
            http_response_code(400);
            echo "Error: No data received";
            return;
        }

        $this->logger->debug("Получен raw update", ['json' => $updateJson]);
        $updateData = json_decode($updateJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("Ошибка декодирования JSON", ['error' => json_last_error_msg(), 'json' => mb_substr($updateJson, 0, 500)]);
            http_response_code(400);
            echo "Error: Invalid JSON";
            return;
        }
        
        $relevantUpdateData = $updateData['message'] ?? $updateData['channel_post'] ?? null;

        if (!$relevantUpdateData) {
            $this->logger->info("Получен апдейт без message или channel_post, пропускается.", ['update_keys' => array_keys($updateData)]);
            http_response_code(200); // Отвечаем OK, т.к. это может быть легитимный, но не обрабатываемый нами тип апдейта
            echo "OK: Update type not processed";
            return;
        }

        $text = $relevantUpdateData['text'] ?? null;
        $chatId = $relevantUpdateData['chat']['id'] ?? null;
        $messageId = $relevantUpdateData['message_id'] ?? null;
        $chatType = $relevantUpdateData['chat']['type'] ?? null;
        $fromData = $relevantUpdateData['from'] ?? ($relevantUpdateData['author_signature'] ?? null); // author_signature для каналов от имени канала
        
        $fromUser = 'UnknownUser';
        $isFromBot = false; // Флаг, что сообщение от бота (любого)
        $isFromOurBot = false; // Флаг, что сообщение от нашего бота

        if (isset($relevantUpdateData['from'])) { // Обычные сообщения от пользователей или ботов
            $fromUser = $relevantUpdateData['from']['username'] ?? ($relevantUpdateData['from']['first_name'] ?? 'UserWithoutUsername');
            $isFromBot = $relevantUpdateData['from']['is_bot'] ?? false;
            if ($isFromBot && isset($relevantUpdateData['from']['username'])) {
                $isFromOurBot = ($relevantUpdateData['from']['username'] === ($this->config['telegram_bot_username'] ?? ''));
            }
        } elseif (isset($relevantUpdateData['sender_chat'])) { // Сообщения от имени канала в группе или супергруппе
             $fromUser = $relevantUpdateData['sender_chat']['title'] ?? 'ChannelAsUser';
             // Сообщения от имени канала не считаются сообщениями от ботов в контексте is_bot
        } elseif ($chatType === 'channel') { // Посты в канале (channel_post)
            $fromUser = $relevantUpdateData['chat']['title'] ?? 'Channel'; // Имя канала как отправитель
            if(isset($relevantUpdateData['author_signature']) && !empty($relevantUpdateData['author_signature'])){
                 $fromUser = $relevantUpdateData['author_signature']; // Если есть подпись автора поста
            }
        }

        $isChannelPost = isset($updateData['channel_post']);

        if ($chatId && $text !== null) {
            $this->logger->info("Получено сообщение", ['chat_id' => $chatId, 'text_length' => strlen($text), 'from' => $fromUser, 'chat_type' => $chatType, 'is_channel_post' => $isChannelPost, 'is_from_bot' => $isFromBot, 'is_from_our_bot' => $isFromOurBot]);

            // Сохраняем сообщение пользователя/канала в историю, если это не сообщение от нашего же бота
            if (!$isFromOurBot) {
                 $historyMessagePrefix = ($isChannelPost && empty($relevantUpdateData['author_signature'])) ? "" : ($fromUser . ": ");
                 $this->messageHistory->addMessage((string)$chatId, $historyMessagePrefix . $text);
            }

            $botUsername = $this->config['telegram_bot_username'] ?? 'bot';
            $isMentioned = false;
            if (!$isChannelPost && isset($relevantUpdateData['entities'])) {
                foreach ($relevantUpdateData['entities'] as $entity) {
                    if ($entity['type'] === 'mention') {
                        $mentionedText = mb_substr(
                            $text,
                            $entity['offset'],
                            $entity['length']
                        );
                        if (ltrim($mentionedText, '@') === $botUsername) {
                            $isMentioned = true;
                            break;
                        }
                    }
                }
            }

            $keywordFound = false;
            $monitoredKeywords = $this->config['monitored_keywords'] ?? [];
            if (!$isChannelPost && !$isMentioned && !empty($monitoredKeywords) && !$isFromBot) { // Не реагируем на ключевые слова от других ботов
                foreach ($monitoredKeywords as $keyword) {
                    if (mb_stripos($text, $keyword) !== false) {
                        $keywordFound = true;
                        $this->logger->info("Найдено ключевое слово", ['chat_id' => $chatId, 'keyword' => $keyword]);
                        break;
                    }
                }
            }

            $respondedToCurrentUpdate = false; 

            // Проверяем, является ли сообщение ответом на сообщение нашего бота
            $isReplyToOurBot = false;
            if (isset($relevantUpdateData['reply_to_message']['from']['username']) &&
                $relevantUpdateData['reply_to_message']['from']['username'] === $botUsername &&
                ($relevantUpdateData['reply_to_message']['from']['is_bot'] ?? false) === true
            ) {
                $isReplyToOurBot = true;
                $this->logger->info("Сообщение является ответом на сообщение нашего бота", ['chat_id' => $chatId, 'message_id' => $messageId]);
            }

            // Основное условие для ответа AI: упоминание, ключевое слово, личный чат (не от бота), ИЛИ ответ нашему боту
            if ($this->aiModel && 
                (!$isChannelPost || $this->config['respond_to_channel_posts_if_mentioned'] ?? false) && 
                (!empty(trim($text))) && 
                ($isMentioned || $keywordFound || ($chatType === 'private' && !$isFromBot) || $isReplyToOurBot)
            ) {
                $respondedToCurrentUpdate = true; 

                $this->logger->info("Сообщение требует ответа AI", ['chat_id' => $chatId, 'is_mentioned' => $isMentioned, 'keyword_found' => $keywordFound, 'is_private' => $chatType === 'private', 'is_reply_to_our_bot' => $isReplyToOurBot]);

                $historyMessages = $this->messageHistory->getHistory((string)$chatId);
                $contextString = "";
                if (!empty($historyMessages)) {
                    $contextString = "Контекст предыдущих сообщений:\n" . implode("\n", $historyMessages) . "\n\n";
                }

                $aiRole = $this->chatRoleService->getRole((string)$chatId);
                $this->logger->debug("Используется AI роль для чата", ['chat_id' => $chatId, 'role_preview' => mb_substr($aiRole, 0, 100)]);

                $userPromptPart = $contextString . "Текущее сообщение от " . $fromUser . ": " . $text;

                $logContext = [
                    'chat_id' => $chatId,
                    'from_user' => $fromUser,
                    'is_mentioned' => $isMentioned,
                    'keyword_found' => $keywordFound,
                    'chat_type' => $chatType,
                    'is_reply_to_our_bot' => $isReplyToOurBot,
                    'ai_provider' => $this->config['active_ai_provider'],
                    'ai_model_name' => ($this->config['active_ai_provider'] === 'openai') ? $this->config['openai_model'] : ($this->config['gemini_model_name'] ?? 'gemini-pro (предположительно)'),
                    'history_included' => !empty($historyMessages),
                    'role_used' => !empty($aiRole)
                ];
                $this->logger->debug("Параметры для запроса к AI", $logContext);

                $aiResponseData = $this->aiModel->generateResponse($userPromptPart, $aiRole);

                if ($aiResponseData && isset($aiResponseData['text'])) {
                    $aiResponseText = $aiResponseData['text'];
                    $this->logger->info("Ответ от AI получен", array_merge($logContext, ['response_len' => strlen($aiResponseText)]));
                    $replyTo = ($chatType === 'private' || $isChannelPost) ? null : $messageId;
                    $this->telegramService->sendMessage((string)$chatId, $aiResponseText, $replyTo);
                    $this->messageHistory->addMessage((string)$chatId, ($this->config['bot_history_prefix'] ?? 'Bot: ') . $aiResponseText);

                    // Запись статистики токенов
                    $this->tokenUsageService->recordUsage(
                        (string)$chatId,
                        $this->config['active_ai_provider'],
                        ($this->config['active_ai_provider'] === 'openai') ? $this->config['openai_model'] : ($this->config['gemini_model_name'] ?? 'gemini-pro'), // Уточнить имя модели Gemini, если есть в конфиге
                        $aiResponseData['prompt_tokens'] ?? null,
                        $aiResponseData['completion_tokens'] ?? null,
                        $aiResponseData['total_tokens'] ?? null
                    );

                } else {
                    $this->logger->error("Не удалось получить ответ от AI или ответ не содержит текст", array_merge($logContext, ['prompt_len' => strlen($userPromptPart), 'response_data' => $aiResponseData]));
                    $fallbackMessage = $this->config['ai_error_fallback_message'] ?? "Извините, в данный момент я не могу сгенерировать ответ.";
                    $this->telegramService->sendMessage((string)$chatId, $fallbackMessage, $messageId);
                }
            } else {
                 if (!$this->aiModel) {
                     $this->logger->error("AI модель не инициализирована, ответ невозможен.", ['chat_id' => $chatId]);
                 } else {
                    $this->logger->debug("Сообщение не требует прямого ответа AI", ['chat_id' => $chatId, 'text_preview' => mb_substr($text, 0, 50)]);
                 }
            }

            // Логика проактивного вовлечения
            if ($this->aiModel && !$respondedToCurrentUpdate &&
                ($this->config['enable_proactive_engagement'] ?? false) &&
                ($chatType === 'group' || $chatType === 'supergroup') &&
                !$isChannelPost && !$isFromOurBot && !$isFromBot // Только для сообщений от людей в группах, не от нашего и не от других ботов
            ) {
                $messagesSinceLastBotTurn = $this->messageHistory->countMessagesSinceLastBotTurn((string)$chatId);
                $threshold = $this->config['proactive_engagement_message_threshold'] ?? 10;

                $this->logger->debug("Проверка проактивного вмешательства", [
                    'chat_id' => $chatId,
                    'messages_since_bot' => $messagesSinceLastBotTurn,
                    'threshold' => $threshold
                ]);

                if ($messagesSinceLastBotTurn >= $threshold) {
                    $this->logger->info("Порог для проактивного ответа достигнут", ['chat_id' => $chatId, 'count' => $messagesSinceLastBotTurn]);

                    $historyMessages = $this->messageHistory->getHistory((string)$chatId);
                    $historyStringForPrompt = "";
                    if (!empty($historyMessages)) {
                        $historyStringForPrompt = implode("\n", $historyMessages);
                    }

                    $proactiveSystemRole = $this->config['proactive_engagement_system_role'] ?? null;
                    $proactiveUserPromptTemplate = $this->config['proactive_engagement_user_prompt_template'] ?? "Вот последние сообщения в чате:\n%s\n\nЗадача: Вежливо и по теме подключиться к обсуждению.";
                    $proactiveUserPrompt = sprintf($proactiveUserPromptTemplate, rtrim($historyStringForPrompt));
                    
                    $this->logger->debug("Генерация проактивного ответа AI", ['chat_id' => $chatId, 'prompt_len' => strlen($proactiveUserPrompt), 'system_role_present' => !is_null($proactiveSystemRole)]);
                    $proactiveAiResponseData = $this->aiModel->generateResponse($proactiveUserPrompt, $proactiveSystemRole);

                    if ($proactiveAiResponseData && isset($proactiveAiResponseData['text'])) {
                        $proactiveAiResponseText = $proactiveAiResponseData['text'];
                        $this->logger->info("Проактивный ответ от AI получен", ['chat_id' => $chatId, 'response_len' => strlen($proactiveAiResponseText)]);
                        $this->telegramService->sendMessage((string)$chatId, $proactiveAiResponseText, null); 
                        $this->messageHistory->addMessage((string)$chatId, ($this->config['bot_history_prefix'] ?? 'Bot: ') . $proactiveAiResponseText);

                        // Запись статистики токенов для проактивного ответа
                        $this->tokenUsageService->recordUsage(
                            (string)$chatId,
                            $this->config['active_ai_provider'],
                            ($this->config['active_ai_provider'] === 'openai') ? $this->config['openai_model'] : ($this->config['gemini_model_name'] ?? 'gemini-pro'), // Уточнить
                            $proactiveAiResponseData['prompt_tokens'] ?? null,
                            $proactiveAiResponseData['completion_tokens'] ?? null,
                            $proactiveAiResponseData['total_tokens'] ?? null
                        );
                    } else {
                        $this->logger->error("Не удалось получить проактивный ответ от AI или ответ не содержит текст", ['chat_id' => $chatId, 'prompt_len' => strlen($proactiveUserPrompt), 'response_data' => $proactiveAiResponseData]);
                    }
                }
            }
        }

        http_response_code(200);
        echo "OK";
    }

    // Пример функции логирования (можно расширить для записи в файл или другую систему)
    /*
    private function logMessage(string $message): void
    {
        // file_put_contents(__DIR__ . '/../../telegram_bot.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
        // Для отладки можно просто выводить, если веб-сервер это позволяет
        // error_log($message);
    }
    */
} 