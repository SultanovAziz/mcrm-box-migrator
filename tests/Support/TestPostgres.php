<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Support;

use Mcrm\BoxMigrator\Core\DbConfig;

/**
 * Настоящий PostgreSQL для интеграционных тестов (scripts/test.sh поднимает его
 * в docker и передаёт TEST_PG_*). Без TEST_PG_HOST такие тесты пропускаются.
 */
final class TestPostgres
{
    public static function available(): bool
    {
        return (string) getenv('TEST_PG_HOST') !== '';
    }

    public static function host(): string
    {
        return (string) getenv('TEST_PG_HOST');
    }

    public static function port(): int
    {
        return (int) (getenv('TEST_PG_PORT') ?: 5432);
    }

    public static function config(string $database, ?string $password = null): DbConfig
    {
        return new DbConfig(
            self::host(),
            self::port(),
            $database,
            (string) (getenv('TEST_PG_USER') ?: 'postgres'),
            $password ?? (string) getenv('TEST_PG_PASSWORD'),
            'disable',
        );
    }

    /** Секрет сервиса в формате Vault, ведущий в тестовую базу. @return array<string, mixed> */
    public static function secret(string $database): array
    {
        $c = self::config($database);

        return ['db' => [
            'host' => $c->host,
            'port' => $c->port,
            'dbname' => $c->database,
            'username' => $c->username,
            'password' => $c->password,
            'sslmode' => 'disable',
        ]];
    }

    public static function pdo(string $database): \PDO
    {
        $c = self::config($database);

        return new \PDO($c->dsn(), $c->username, $c->password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public static function recreate(string $database): void
    {
        $admin = self::pdo('postgres');
        $admin->exec("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
        $admin->exec("CREATE DATABASE \"{$database}\"");
    }

    public static function tableExists(string $database, string $table): bool
    {
        $stmt = self::pdo($database)->prepare('SELECT to_regclass(:t) IS NOT NULL');
        $stmt->execute(['t' => "public.{$table}"]);

        return (bool) $stmt->fetchColumn();
    }
}
