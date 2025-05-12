<?php

namespace App\Services;

// Подразумевается, что LoggerService будет передан в конструктор
// use App\Services\LoggerService;

class MessageHistory
{
    private int $historyLimit;
    private string $storagePath;
    private LoggerService $logger; // Добавляем свойство для логгера
    private string $botHistoryPrefix; // Добавлено

    /**
     * @param int $historyLimit
     * @param string $storagePath Путь к директории для хранения файлов истории.
     * По умолчанию используется директория 'history' в корне проекта.
     */
    public function __construct(int $historyLimit, string $storagePath = __DIR__ . '/../../history/', LoggerService $logger, string $botHistoryPrefix)
    {
        $this->historyLimit = $historyLimit;
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->logger = $logger; // Сохраняем логгер
        $this->botHistoryPrefix = $botHistoryPrefix; // Добавлено

        if (!is_dir($this->storagePath)) {
            if (!@mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
                $this->logger->error('Не удалось создать директорию для истории сообщений', [
                    'path' => $this->storagePath
                ]);
                // В реальном приложении здесь лучше выбрасывать исключение:
                // throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->storagePath));
            } else {
                $this->logger->info('Создана директория для истории сообщений', ['path' => $this->storagePath]);
            }
        } else {
             $this->logger->debug('Директория для истории сообщений уже существует', ['path' => $this->storagePath]);
        }
    }

    private function getFilePath(int|string $chatId): string
    {
        // Используем DIRECTORY_SEPARATOR для кроссплатформенности
        return $this->storagePath . 'chat_' . preg_replace("/[^a-zA-Z0-9_-]/", "", (string)$chatId) . '.json';
    }

    /**
     * Добавляет сообщение в историю чата.
     *
     * @param int|string $chatId ID чата
     * @param string $text Текст сообщения
     */
    public function addMessage(int|string $chatId, string $text): void
    {
        $filePath = $this->getFilePath($chatId);
        $history = [];
        if (file_exists($filePath)) {
            if (is_readable($filePath)){
                $fileContent = @file_get_contents($filePath);
                if ($fileContent !== false) {
                    $decodedHistory = json_decode($fileContent, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $history = $decodedHistory;
                    } else {
                        $this->logger->error('Ошибка декодирования JSON из файла истории', [
                            'file' => $filePath, 
                            'json_error' => json_last_error_msg()
                        ]);
                        // Можно решить удалить поврежденный файл или переименовать его
                    }
                } else {
                    $this->logger->error('Не удалось прочитать файл истории', ['file' => $filePath]);
                }
            } else {
                 $this->logger->error('Нет прав на чтение файла истории', ['file' => $filePath]);
            }
        }

        $history[] = $text;

        if (count($history) > $this->historyLimit) {
            $history = array_slice($history, -$this->historyLimit);
        }
        
        if (!is_dir($this->storagePath)) {
             $this->logger->error('Директория для записи истории не существует или не является директорией', ['path' => $this->storagePath]);
             return; // Прерываем, если нет директории
        }

        if (is_writable($this->storagePath)) { // Проверяем права на запись в директорию
            if (file_exists($filePath) && !is_writable($filePath)) {
                $this->logger->error('Нет прав на запись в файл истории', ['file' => $filePath]);
                return; // Прерываем, если нет прав на запись в существующий файл
            }
            $writeResult = @file_put_contents($filePath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($writeResult === false) {
                $this->logger->error('Не удалось записать в файл истории', ['file' => $filePath]);
            }
        } else {
            $this->logger->error('Директория для истории не доступна для записи', ['path' => $this->storagePath]);
        }
    }

    /**
     * Получает историю сообщений для чата.
     *
     * @param int|string $chatId ID чата
     * @return array История сообщений (массив строк)
     */
    public function getHistory(int|string $chatId): array
    {
        $filePath = $this->getFilePath($chatId);
        if (file_exists($filePath) && is_readable($filePath)) {
            $fileContent = @file_get_contents($filePath);
            if ($fileContent !== false) {
                $decodedHistory = json_decode($fileContent, true);
                 if (json_last_error() === JSON_ERROR_NONE) {
                    return $decodedHistory;
                } else {
                    $this->logger->error('Ошибка декодирования JSON при получении истории', [
                        'file' => $filePath, 
                        'json_error' => json_last_error_msg()
                    ]);
                    return []; // Возвращаем пустой массив в случае ошибки
                }
            } else {
                 $this->logger->error('Не удалось прочитать файл при получении истории', ['file' => $filePath]);
            }
        }
        return [];
    }

    /**
     * Очищает историю для конкретного чата.
     *
     * @param int|string $chatId ID чата
     */
    public function clearHistory(int|string $chatId): void
    {
        $filePath = $this->getFilePath($chatId);
        if (file_exists($filePath)) {
            if (@unlink($filePath)) {
                $this->logger->info('Файл истории успешно удален', ['file' => $filePath]);
            } else {
                $this->logger->error('Не удалось удалить файл истории', ['file' => $filePath]);
            }
        }
    }

    /**
     * Считает количество сообщений в истории чата с момента последнего сообщения от бота.
     *
     * @param string $chatId ID чата.
     * @return int Количество сообщений. Если бот еще не писал или история пуста, вернет количество всех сообщений.
     */
    public function countMessagesSinceLastBotTurn(string $chatId): int
    {
        $history = $this->getHistory($chatId); // Используем существующий getHistory, который возвращает массив строк
        if (empty($history)) {
            return 0;
        }

        $count = 0;
        foreach (array_reverse($history) as $message) {
            // Проверяем, начинается ли сообщение с префикса бота
            if (str_starts_with($message, $this->botHistoryPrefix)) { // Используем PHP 8 str_starts_with
                return $count;
            }
            $count++;
        }
        // Если бот не найден в истории, возвращаем общее количество сообщений (или $count, что будет равно count($history))
        return $count;
    }
} 