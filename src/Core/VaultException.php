<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Сбой обращения к Vault. Текст собирается из метода, пути и кода ответа —
 * тело ответа, токен и пароль в него не попадают никогда.
 */
final class VaultException extends \RuntimeException
{
    public static function status(string $method, string $path, int $status): self
    {
        return new self("Vault ответил {$status} на {$method} {$path}");
    }

    public static function unreachable(string $method, string $path): self
    {
        return new self("Vault недоступен: {$method} {$path}");
    }
}
