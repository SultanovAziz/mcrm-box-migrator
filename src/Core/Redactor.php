<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Вычищает известные секреты из текста ошибок: драйвер БД или фреймворк могут
 * вернуть сообщение с паролем, а оно уходит в лог CI.
 */
final class Redactor
{
    /** Короче — заменять опасно: «***» посреди обычных слов сделает текст нечитаемым. */
    private const MIN_LENGTH = 4;

    /** @var array<string, true> */
    private array $secrets = [];

    public function add(string $secret): void
    {
        if (strlen($secret) >= self::MIN_LENGTH) {
            $this->secrets[$secret] = true;
        }
    }

    public function clean(string $text): string
    {
        $secrets = array_keys($this->secrets);
        // Длинные первыми: иначе секрет, содержащий другой, остался бы недочищенным.
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets === [] ? $text : str_replace($secrets, '***', $text);
    }
}
