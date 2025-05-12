<?php

namespace App\Interfaces;

interface AIModelInterface
{
    /**
     * Генерирует ответ на основе предоставленного промпта и системной роли.
     *
     * @param string $userPrompt Основной запрос (промпт) пользователя, включая историю.
     * @param string|null $systemRole Системный промпт или роль для AI. Может быть null.
     * @return array|null Массив с ответом и информацией о токенах, или null в случае ошибки.
     *                    Пример массива: 
     *                    [\n     *                        \'text\' => ?string, // Текст ответа\n     *                        \'prompt_tokens\' => ?int, // Токены, использованные для промпта\n     *                        \'completion_tokens\' => ?int, // Токены, использованные для генерации ответа\n     *                        \'total_tokens\' => ?int // Общее количество токенов\n     *                    ]
     */
    public function generateResponse(string $userPrompt, ?string $systemRole = null): ?array;
} 