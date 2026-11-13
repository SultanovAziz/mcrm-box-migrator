<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Куда и как ходить в Vault. Пустые обязательные поля — ошибка сразу при сборке
 * конфига: иначе CI узнал бы о забытой переменной только по 400 от Vault.
 * В текст ошибки попадает имя поля, но никогда не его значение.
 */
final class VaultConfig
{
    public readonly string $url;

    public readonly string $mount;

    public readonly string $boxesPrefix;

    public function __construct(
        string $url,
        public readonly string $login,
        public readonly string $password,
        string $mount = 'secret',
        string $boxesPrefix = '',
        public readonly int $timeoutSeconds = 10,
    ) {
        foreach (['url' => $url, 'login' => $login, 'password' => $password] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("Vault: не задан {$field}");
            }
        }

        $this->url = rtrim($url, '/');
        $this->mount = trim($mount, '/') === '' ? 'secret' : trim($mount, '/');
        $this->boxesPrefix = trim($boxesPrefix, '/');
    }
}
