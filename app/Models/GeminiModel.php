<?php

namespace App\Models;

use App\Interfaces\AIModelInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Services\LoggerService;

class GeminiModel implements AIModelInterface
{
    private array $config;
    private Client $httpClient;
    private LoggerService $logger;

    public function __construct(array $config, Client $httpClient, LoggerService $logger)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Генерирует ответ с помощью Gemini API.
     *
     * @param string $userPrompt Запрос к модели
     * @param ?string $systemRole Системная роль, если она есть
     * @return array|null Ответ от модели или null в случае ошибки (массив с ключами text, prompt_tokens, completion_tokens, total_tokens)
     */
    public function generateResponse(string $userPrompt, ?string $systemRole = null): ?array
    {
        $apiKey = $this->config['gemini_api_key'];
        $apiUrl = $this->config['gemini_api_url'];

        // Формируем финальный промпт, добавляя системную роль, если она есть
        $finalPrompt = $userPrompt;
        if ($systemRole !== null && !empty(trim($systemRole))) {
            // Добавляем роль в начало, отделяя двумя переносами строки для ясности
            $finalPrompt = trim($systemRole) . "\n\n---\n\n" . $userPrompt;
        }

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $finalPrompt] // Используем финальный промпт
                    ]
                ]
            ],
        ];

        $this->logger->debug("Запрос к Gemini API", [
            'url' => $apiUrl, 
            'system_role_used' => ($systemRole !== null && !empty(trim($systemRole))),
            'final_prompt_length' => strlen($finalPrompt)
        ]);

        try {
            $response = $this->httpClient->post($apiUrl . '?key=' . $apiKey, [
                'json' => $body,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            $responseBody = (string)$response->getBody();
            $responseData = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Ошибка декодирования JSON ответа от Gemini API", [
                    'url' => $apiUrl,
                    'response_body' => $responseBody,
                    'json_error' => json_last_error_msg()
                ]);
                return null;
            }

            $this->logger->debug("Ответ от Gemini API получен", ['url' => $apiUrl, 'response_keys' => array_keys($responseData)]);

            $generatedText = null;
            $promptTokens = null;
            $completionTokens = null;
            $totalTokens = null;

            if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $generatedText = $responseData['candidates'][0]['content']['parts'][0]['text'];
                $this->logger->info("Текст успешно сгенерирован Gemini API", ['response_text_length' => strlen($generatedText)]);
            }

            // Пытаемся извлечь информацию о токенах из usageMetadata
            if (isset($responseData['usageMetadata'])) {
                $promptTokens = $responseData['usageMetadata']['promptTokenCount'] ?? null;
                // Gemini API может возвращать candidatesTokenCount как сумму токенов для всех кандидатов.
                // Если есть только один кандидат (стандартный случай), это будет токенами ответа.
                $completionTokens = $responseData['usageMetadata']['candidatesTokenCount'] ?? null;
                $totalTokens = $responseData['usageMetadata']['totalTokenCount'] ?? null;
                $this->logger->info("Информация о токенах Gemini API", [
                    'prompt' => $promptTokens,
                    'completion' => $completionTokens,
                    'total' => $totalTokens
                ]);
            } else {
                $this->logger->info("Информация об использовании токенов отсутствует в ответе Gemini API", ['url' => $apiUrl]);
            }

            if ($generatedText !== null) {
                return [
                    'text' => $generatedText,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $totalTokens
                ];
            } elseif (isset($responseData['error']['message'])) {
                $errorMessage = $responseData['error']['message'];
                $this->logger->error("Ошибка от Gemini API", ['url' => $apiUrl, 'error_message' => $errorMessage, 'response_data' => $responseData]);
                return null; // Возвращаем null в случае ошибки API
            }
            
            $this->logger->error("Неизвестный формат ответа от Gemini API или отсутствует текст", ['url' => $apiUrl, 'response_data' => $responseData]);
            return null;

        } catch (GuzzleException $e) {
            $context = [
                'url' => $apiUrl,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];
            if ($e->hasResponse()) {
                $context['response_body'] = (string) $e->getResponse()->getBody();
                $context['response_status_code'] = $e->getResponse()->getStatusCode();
            }
            $this->logger->error("Ошибка запроса к Gemini API (GuzzleException)", $context);
            return null;
        } catch (\Exception $e) { 
             $this->logger->error("Непредвиденная ошибка при запросе к Gemini API", [
                'url' => $apiUrl,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace_small' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return null;
        }
    }
} 