<?php

namespace App\Services;

class ChatRoleService
{
    private string $storagePath;
    private string $defaultRole;
    private LoggerService $logger;
    private array $chatRoles = [];

    public function __construct(string $storagePath, string $defaultRole, LoggerService $logger)
    {
        $this->storagePath = $storagePath;
        $this->defaultRole = $defaultRole;
        $this->logger = $logger;
        $this->loadRoles();
    }

    private function loadRoles(): void
    {
        if (file_exists($this->storagePath) && is_readable($this->storagePath)) {
            $jsonContent = @file_get_contents($this->storagePath);
            if ($jsonContent === false) {
                $this->logger->error("Не удалось прочитать файл настроек ролей чатов", ['path' => $this->storagePath]);
                return;
            }
            $this->chatRoles = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Ошибка декодирования JSON из файла настроек ролей чатов", [
                    'path' => $this->storagePath,
                    'json_error' => json_last_error_msg()
                ]);
                $this->chatRoles = []; // Сбрасываем, если JSON невалидный
            } else {
                $this->logger->info("Настройки ролей чатов успешно загружены", ['path' => $this->storagePath, 'count' => count($this->chatRoles)]);
            }
        } else {
            $this->logger->info("Файл настроек ролей чатов не найден или не доступен для чтения. Будет использована роль по умолчанию.", ['path' => $this->storagePath]);
            // Файл может быть создан при первом сохранении роли, если такая функция будет добавлена
        }
    }

    /**
     * Получает роль для указанного ID чата.
     * Если для чата не задана специфическая роль, возвращает роль по умолчанию.
     *
     * @param int|string $chatId ID чата.
     * @return string Роль AI.
     */
    public function getRole(int|string $chatId): string
    {
        $chatIdStr = (string)$chatId;
        if (isset($this->chatRoles[$chatIdStr]) && is_string($this->chatRoles[$chatIdStr]) && !empty($this->chatRoles[$chatIdStr])) {
            $this->logger->debug("Найдена специфическая роль для чата", ['chat_id' => $chatIdStr]);
            return $this->chatRoles[$chatIdStr];
        }
        $this->logger->debug("Специфическая роль для чата не найдена, используется роль по умолчанию", ['chat_id' => $chatIdStr]);
        return $this->defaultRole;
    }

    /**
     * Устанавливает специфическую роль для чата и сохраняет настройки.
     * (Эта функция может быть использована для административных команд в будущем)
     *
     * @param int|string $chatId ID чата.
     * @param string $role Новая роль. Если пустая строка, роль для чата будет удалена (и будет использоваться дефолтная).
     */
    public function setRole(int|string $chatId, string $role): bool
    {
        $chatIdStr = (string)$chatId;
        if (empty($role)) {
            unset($this->chatRoles[$chatIdStr]);
            $this->logger->info("Специфическая роль для чата удалена", ['chat_id' => $chatIdStr]);
        } else {
            $this->chatRoles[$chatIdStr] = $role;
            $this->logger->info("Установлена специфическая роль для чата", ['chat_id' => $chatIdStr, 'role' => $role]);
        }
        return $this->saveRoles();
    }

    private function saveRoles(): bool
    {
        $jsonContent = json_encode($this->chatRoles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonContent === false) {
            $this->logger->error("Ошибка кодирования JSON для сохранения настроек ролей чатов", ['json_error' => json_last_error_msg()]);
            return false;
        }

        $storageDir = dirname($this->storagePath);
        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                $this->logger->error("Не удалось создать директорию для хранения файла ролей", ['path' => $storageDir]);
                return false;
            }
        }

        if (@file_put_contents($this->storagePath, $jsonContent) === false) {
            $this->logger->error("Не удалось записать файл настроек ролей чатов", ['path' => $this->storagePath]);
            return false;
        }
        $this->logger->info("Настройки ролей чатов успешно сохранены", ['path' => $this->storagePath]);
        return true;
    }
} 