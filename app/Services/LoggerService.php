<?php

namespace App\Services;

class LoggerService
{
    public const LEVEL_ERROR = 'ERROR';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_DEBUG = 'DEBUG'; // Добавим уровень DEBUG для подробного логирования

    private string $logFilePath;
    private bool $isDebugMode;

    public function __construct(string $logFilePath, bool $isDebugMode = false)
    {
        $this->logFilePath = $logFilePath;
        $this->isDebugMode = $isDebugMode;

        // Попытаемся создать директорию для логов, если она не существует
        $logDir = dirname($this->logFilePath);
        if (!is_dir($logDir)) {
            // Подавляем ошибку, если директория уже существует (на случай гонки потоков)
            // или если нет прав. Запись лога потом не удастся, но приложение не упадет здесь.
            @mkdir($logDir, 0775, true);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($level === self::LEVEL_DEBUG && !$this->isDebugMode) {
            return; // Не логируем DEBUG сообщения, если режим отладки выключен
        }

        $date = date('Y-m-d H:i:s');
        $formattedMessage = sprintf("[%s] [%s]: %s", $date, $level, $message);

        if (!empty($context)) {
            // Добавляем контекст в виде JSON, если он есть
            // Убираем переносы строк из контекста для однострочного лога
            $contextString = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($contextString !== false) {
                 $formattedMessage .= " | Context: " . str_replace(["\n", "\r"], ' ', $contextString);
            }
        }

        $formattedMessage .= PHP_EOL;

        // FILE_APPEND для добавления в конец файла, LOCK_EX для предотвращения одновременной записи
        @file_put_contents($this->logFilePath, $formattedMessage, FILE_APPEND | LOCK_EX);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
} 