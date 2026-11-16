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
        // Таймаут — только через ATTR_TIMEOUT: pdo_pgsql дописывает в конец DSN свой
        // connect_timeout (по умолчанию 30 с), и libpq берёт последнее значение, так что
        // параметр в самом DSN молча игнорировался бы.
        $pdo = new \PDO($db->dsn(), $db->username, $db->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $this->connectTimeoutSeconds,
        ]);

        try {
            $this->lock($pdo, $db);

            try {
                $work();
            } finally {
                $this->unlock($pdo);
            }
        } finally {
            // Закрытие сессии отпускает лок, даже если unlock выше не дошёл.
            $pdo = null;
        }
    }

    private function lock(\PDO $pdo, DbConfig $db): void
    {
        // lock_timeout = 0 в PostgreSQL означает «без таймаута», а не «не ждать»:
        // нулевое (и отрицательное) ожидание — это попытка без ожидания, иначе
        // такой конфиг вешал бы CI навсегда.
        if ($this->waitSeconds <= 0) {
            if (! (bool) $pdo->query("SELECT pg_try_advisory_lock({$this->lockKey})")->fetchColumn()) {
                throw new LockTimeoutException("База {$db->describe()} занята другим прогоном миграций");
            }

            return;
        }

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
    }

    /**
     * Ошибка unlock не должна заменять исход миграции: если сессия блокировки
     * оборвалась (простой во время долгой миграции), лок уже отпущен вместе с ней,
     * а исключение отсюда выдало бы успешную миграцию за ошибку или скрыло настоящую.
     */
    private function unlock(\PDO $pdo): void
    {
        try {
            $pdo->query("SELECT pg_advisory_unlock({$this->lockKey})");
        } catch (\PDOException) {
        }
    }
}
