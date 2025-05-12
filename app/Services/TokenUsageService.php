<?php

namespace App\Services;

class TokenUsageService
{
    private string $storagePath;
    private LoggerService $logger;

    public function __construct(string $storagePath, LoggerService $logger)
    {
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->logger = $logger;

        if (!is_dir($this->storagePath)) {
            if (!@mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
                $this->logger->error('Не удалось создать директорию для статистики токенов', [
                    'path' => $this->storagePath
                ]);
                // Можно рассмотреть вариант выбрасывания исключения
            } else {
                $this->logger->info('Создана директория для статистики токенов', ['path' => $this->storagePath]);
            }
        }
    }

    private function getFilePath(string $chatId): string
    {
        return $this->storagePath . 'chat_' . preg_replace("/[^a-zA-Z0-9_-]/", "", $chatId) . '_tokens.json';
    }

    public function recordUsage(
        string $chatId,
        string $aiProvider,
        string $modelName,
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $totalTokens
    ): void {
        $filePath = $this->getFilePath($chatId);
        $records = [];

        if (file_exists($filePath) && is_readable($filePath)) {
            $fileContent = @file_get_contents($filePath);
            if ($fileContent !== false) {
                $decodedRecords = json_decode($fileContent, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedRecords)) {
                    $records = $decodedRecords;
                } else {
                    $this->logger->error('Ошибка декодирования JSON из файла статистики токенов или не массив', [
                        'file' => $filePath,
                        'json_error' => json_last_error_msg()
                    ]);
                }
            } else {
                $this->logger->error('Не удалось прочитать файл статистики токенов', ['file' => $filePath]);
            }
        }

        $records[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'provider' => $aiProvider,
            'model' => $modelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ];

        if (!is_dir(dirname($filePath))) {
             $this->logger->error('Директория для записи файла статистики токенов не существует', ['path' => dirname($filePath)]);
             return;
        }
        
        $writeResult = @file_put_contents($filePath, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($writeResult === false) {
            $this->logger->error('Не удалось записать в файл статистики токенов', ['file' => $filePath]);
        } else {
            $this->logger->debug('Статистика токенов записана', ['file' => $filePath, 'chat_id' => $chatId]);
        }
    }
} 