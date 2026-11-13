<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Advisory lock на базе коробки на время миграций.
 *
 * Два пайплайна, запущенные одновременно, иначе гоняли бы миграции одной базы
 * параллельно. Лок берётся отдельной сессией: advisory lock принадлежит базе, а не
 * соединению фреймворка, поэтому одна реализация годится и Laravel, и Yii.
 * Ключ свой у каждого сервиса — их миграции разных баз друг друга не ждут.
 *
 * Своя сессия заодно проверяет связь с базой с connect_timeout: недоступная
 * коробка падает быстро и понятно, ещё до запуска фреймворка.
 */
final class PostgresAdvisoryGuard implements BoxGuard
{
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private readonly int $lockKey,
        private readonly int $waitSeconds = 60,
        private readonly int $connectTimeoutSeconds = 10,
    ) {
    }

    public static function keyFor(string $serviceKey): int
    {
        return crc32('box-migrator:'.$serviceKey);
    }

    public function hold(DbConfig $db, callable $work): void
    {
        $pdo = new \PDO(
            $db->dsn().';connect_timeout='.$this->connectTimeoutSeconds,
            $db->username,
            $db->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        try {
            $pdo->exec("SET lock_timeout = '{$this->waitSeconds}s'");

            try {
                $pdo->query("SELECT pg_advisory_lock({$this->lockKey})");
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === self::LOCK_NOT_AVAILABLE) {
                    throw new LockTimeoutException(
                        "База {$db->describe()} занята другим прогоном миграций дольше {$this->waitSeconds} с",
                        0,
                        $e,
                    );
                }

                throw $e;
            }

            try {
                $work();
            } finally {
                $pdo->query("SELECT pg_advisory_unlock({$this->lockKey})");
            }
        } finally {
            // Закрытие сессии отпускает лок, даже если unlock выше не дошёл.
            $pdo = null;
        }
    }
}
