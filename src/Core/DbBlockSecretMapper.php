<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Блок db секрета Laravel-сервисов (cash, main-crm, community).
 *
 * sslmode — из секрета, иначе $defaultSslmode: так подключаются DatabaseConnector
 * кассы и community и BoxPgConnectionResolver main-crm (умолчание prefer).
 */
final class DbBlockSecretMapper implements SecretMapper
{
    private const REQUIRED = ['host', 'dbname', 'username'];

    public function __construct(private readonly string $defaultSslmode = 'prefer')
    {
    }

    public function toDbConfig(array $secret): DbConfig
    {
        $db = self::dbBlock($secret);
        $sslmode = isset($db['sslmode']) && is_string($db['sslmode']) && $db['sslmode'] !== ''
            ? $db['sslmode']
            : $this->defaultSslmode;

        return new DbConfig(
            (string) $db['host'],
            self::port($db),
            (string) $db['dbname'],
            (string) $db['username'],
            (string) ($db['password'] ?? ''),
            $sslmode,
        );
    }

    /**
     * Блок db с проверкой обязательных полей — общий для всех разборщиков.
     *
     * @internal
     *
     * @param array<string, mixed> $secret
     *
     * @return array<string, mixed>
     */
    public static function dbBlock(array $secret): array
    {
        $db = $secret['db'] ?? null;

        if (! is_array($db)) {
            throw new InvalidSecretException('в секрете нет блока db');
        }

        foreach (self::REQUIRED as $field) {
            if (! isset($db[$field]) || ! is_scalar($db[$field]) || trim((string) $db[$field]) === '') {
                throw new InvalidSecretException("в блоке db нет поля {$field}");
            }
        }

        return $db;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $db
     */
    public static function port(array $db): int
    {
        $port = $db['port'] ?? null;

        return is_numeric($port) && (int) $port > 0 ? (int) $port : 5432;
    }
}
