<?php

namespace App\Models;

use App\Interfaces\AIModelInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Services\LoggerService;

class OpenAIModel implements AIModelInterface
{
    private array $config;
    private Client $httpClient;
    private LoggerService $logger;
    private string $apiKey;
    private string $apiUrl;
    private string $modelName;

    public function __construct(array $config, Client $httpClient, LoggerService $logger)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->apiKey = $config['openai_api_key'];
        $this->apiUrl = $config['openai_api_url'];
        $this->modelName = $config['openai_model'];
    }

    public function generateResponse(string $userPrompt, ?string $systemRole = null): ?array
    {
        $messages = [];
        if ($systemRole !== null && !empty(trim($systemRole))) {
            $messages[] = ['role' => 'system', 'content' => $systemRole];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $body = [
            'model' => $this->modelName,
            'messages' => $messages,
            // 'max_tokens' => 1500,
            // 'temperature' => 0.7,
        ];

        $this->logger->debug("Запрос к OpenAI API", [
            'url' => $this->apiUrl, 
            'model' => $this->modelName, 
            'system_role_present' => ($systemRole !== null && !empty(trim($systemRole))),
            'user_prompt_length' => strlen($userPrompt)
        ]);

        try {
            $response = $this->httpClient->post($this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);

            $responseBody = (string)$response->getBody();
            $responseData = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Ошибка декодирования JSON ответа от OpenAI API", [
                    'url' => $this->apiUrl,
                    'response_body' => $responseBody,
                    'json_error' => json_last_error_msg()
                ]);
                return null;
            }

            $this->logger->debug("Ответ от OpenAI API получен", ['url' => $this->apiUrl, 'response_keys' => array_keys($responseData)]);

            $generatedText = null;
            $promptTokens = null;
            $completionTokens = null;
            $totalTokens = null;

            if (isset($responseData['choices'][0]['message']['content'])) {
                $generatedText = $responseData['choices'][0]['message']['content'];
                $this->logger->info("Текст успешно сгенерирован OpenAI API", ['model' => $this->modelName, 'response_text_length' => strlen($generatedText)]);
            }

            if (isset($responseData['usage'])) {
                $promptTokens = $responseData['usage']['prompt_tokens'] ?? null;
                $completionTokens = $responseData['usage']['completion_tokens'] ?? null;
                $totalTokens = $responseData['usage']['total_tokens'] ?? null;
                $this->logger->info("Информация о токенах OpenAI API", [
                    'prompt' => $promptTokens,
                    'completion' => $completionTokens,
                    'total' => $totalTokens
                ]);
            } else {
                $this->logger->info("Информация об использовании токенов отсутствует в ответе OpenAI API", ['url' => $this->apiUrl]);
            }

            if ($generatedText !== null) {
                return [
                    'text' => trim($generatedText),
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $totalTokens
                ];
            } elseif (isset($responseData['error']['message'])) {
                $errorMessage = $responseData['error']['message'];
                $this->logger->error("Ошибка от OpenAI API", ['url' => $this->apiUrl, 'error_message' => $errorMessage, 'response_data' => $responseData]);
                return null;
            }

            $this->logger->error("Неизвестный формат ответа от OpenAI API или отсутствует текст", ['url' => $this->apiUrl, 'response_data' => $responseData]);
            return null;

        } catch (GuzzleException $e) {
            $context = [
                'url' => $this->apiUrl,
                'model' => $this->modelName,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];
            if ($e->hasResponse()) {
                $context['response_body'] = (string) $e->getResponse()->getBody();
                $context['response_status_code'] = $e->getResponse()->getStatusCode();
            }
            $this->logger->error("Ошибка запроса к OpenAI API (GuzzleException)", $context);
            return null;
        } catch (\Exception $e) { 
             $this->logger->error("Непредвиденная ошибка при запросе к OpenAI API", [
                'url' => $this->apiUrl,
                'model' => $this->modelName,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace_small' => substr($e->getTraceAsString(), 0, 500) 
            ]);
            return null;
        }
    }
} 