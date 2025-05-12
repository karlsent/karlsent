<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Services\LoggerService;

class TelegramAPIService
{
    private string $botToken;
    private Client $httpClient;
    private string $apiUrl = 'https://api.telegram.org/bot';
    private ?int $adminTelegramId;
    private LoggerService $logger;

    public function __construct(array $config, Client $httpClient, LoggerService $logger)
    {
        $this->botToken = $config['telegram_bot_token'];
        $this->adminTelegramId = $config['admin_telegram_id'] ?? null;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Отправляет запрос к Telegram Bot API.
     *
     * @param string $method Метод API
     * @param array $params Параметры запроса
     * @return array|null Ответ от API или null в случае ошибки
     */
    private function request(string $method, array $params = []): ?array
    {
        $url = $this->apiUrl . $this->botToken . '/' . $method;
        $this->logger->debug("Запрос к Telegram API", ['method' => $method, 'url' => $url, 'params' => $params]);

        try {
            $response = $this->httpClient->post($url, [
                'json' => $params
            ]);
            $responseBody = (string)$response->getBody();
            $decodedResponse = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Ошибка декодирования JSON ответа от Telegram API", [
                    'method' => $method,
                    'url' => $url,
                    'response_body' => $responseBody,
                    'json_error' => json_last_error_msg()
                ]);
                return null;
            }
            $this->logger->debug("Ответ от Telegram API получен успешно", ['method' => $method, 'response' => $decodedResponse]);
            return $decodedResponse;

        } catch (GuzzleException $e) {
            $context = [
                'method' => $method,
                'url' => $url,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];
            if ($e->hasResponse()) {
                $context['response_body'] = (string) $e->getResponse()->getBody();
                $context['response_status_code'] = $e->getResponse()->getStatusCode();
            }
            $this->logger->error("Ошибка запроса к Telegram API (GuzzleException)", $context);
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Непредвиденная ошибка при запросе к Telegram API", [
                'method' => $method,
                'url' => $url,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Устанавливает веб-хук.
     *
     * @param string $webhookUrl URL веб-хука
     * @return array|null Результат операции
     */
    public function setWebhook(string $webhookUrl): ?array
    {
        $this->logger->info("Установка веб-хука через TelegramAPIService", ['url' => $webhookUrl]);
        return $this->request('setWebhook', ['url' => $webhookUrl, 'drop_pending_updates' => true]);
    }

    /**
     * Отправляет сообщение в чат.
     *
     * @param int|string $chatId ID чата
     * @param string $text Текст сообщения
     * @param int|null $replyToMessageId ID сообщения, на которое нужно ответить (опционально)
     * @return array|null Результат операции
     */
    public function sendMessage(int|string $chatId, string $text, ?int $replyToMessageId = null): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        $this->logger->info("Отправка сообщения через TelegramAPIService", ['chat_id' => $chatId, 'reply_to' => $replyToMessageId, 'text_length' => strlen($text)]);
        return $this->request('sendMessage', $params);
    }

    /**
     * Отправляет сообщение об ошибке администратору, если он указан в конфиге.
     *
     * @param string $errorMessage Текст ошибки
     */
    private function sendErrorMessageToAdmin(string $errorMessage): void
    {
        if ($this->adminTelegramId) {
            $this->logger->info("Отправка сообщения об ошибке администратору", ['admin_id' => $this->adminTelegramId]);
            $url = $this->apiUrl . $this->botToken . '/sendMessage';
            try {
                $this->httpClient->post($url, [
                    'json' => [
                        'chat_id' => $this->adminTelegramId,
                        'text' => "[BOT ERROR]\n" . $errorMessage,
                        'parse_mode' => 'HTML'
                    ]
                ]);
            } catch (GuzzleException $e) {
                $this->logger->error("Не удалось отправить сообщение об ошибке администратору", [
                    'admin_id' => $this->adminTelegramId, 
                    'original_error' => $errorMessage,
                    'guzzle_error' => $e->getMessage()
                ]);
            }
        }
    }

    // Можно добавить другие методы API по мере необходимости, например, getChat, getChatMember и т.д.
} 