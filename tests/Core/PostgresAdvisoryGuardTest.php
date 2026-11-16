<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Core;

use Mcrm\BoxMigrator\Core\DbConfig;
use Mcrm\BoxMigrator\Core\LockTimeoutException;
use Mcrm\BoxMigrator\Core\PostgresAdvisoryGuard;
use Mcrm\BoxMigrator\Tests\Support\TestPostgres;
use PHPUnit\Framework\TestCase;

final class PostgresAdvisoryGuardTest extends TestCase
{
    private const DB = 'guard_probe';

    private int $key;

    protected function setUp(): void
    {
        if (! TestPostgres::available()) {
            self::markTestSkipped('нет TEST_PG_HOST — запускать через scripts/test.sh');
        }

        TestPostgres::recreate(self::DB);
        $this->key = PostgresAdvisoryGuard::keyFor('cash_service');
    }

    private function tryLockFromOtherSession(): bool
    {
        $other = TestPostgres::pdo(self::DB);
        $got = (bool) $other->query("SELECT pg_try_advisory_lock({$this->key})")->fetchColumn();
        $other = null; // закрытие сессии отпускает её локи

        return $got;
    }

    public function test_key_is_stable_per_service(): void
    {
        self::assertSame(PostgresAdvisoryGuard::keyFor('cash_service'), PostgresAdvisoryGuard::keyFor('cash_service'));
        self::assertNotSame(PostgresAdvisoryGuard::keyFor('cash_service'), PostgresAdvisoryGuard::keyFor('community'));
    }

    public function test_runs_work_under_lock_and_releases(): void
    {
        $lockedDuringWork = null;

        (new PostgresAdvisoryGuard($this->key))->hold(TestPostgres::config(self::DB), function () use (&$lockedDuringWork): void {
            $lockedDuringWork = ! $this->tryLockFromOtherSession();
        });

        self::assertTrue($lockedDuringWork, 'во время работы лок должен быть занят');
        self::assertTrue($this->tryLockFromOtherSession(), 'после работы лок должен быть свободен');
    }

    public function test_waits_then_times_out(): void
    {
        $holder = TestPostgres::pdo(self::DB);
        $holder->query("SELECT pg_advisory_lock({$this->key})");
        $called = false;
        $started = microtime(true);

        try {
            (new PostgresAdvisoryGuard($this->key, 1))->hold(TestPostgres::config(self::DB), function () use (&$called): void {
                $called = true;
            });
            self::fail('Ожидалось LockTimeoutException');
        } catch (LockTimeoutException $e) {
            self::assertStringContainsString(TestPostgres::config(self::DB)->describe(), $e->getMessage());
        }

        self::assertFalse($called);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function test_releases_when_work_throws(): void
    {
        try {
            (new PostgresAdvisoryGuard($this->key))->hold(TestPostgres::config(self::DB), static function (): void {
                throw new \RuntimeException('миграция упала');
            });
            self::fail('Исключение работы должно пробрасываться');
        } catch (\RuntimeException $e) {
            self::assertSame('миграция упала', $e->getMessage());
        }

        self::assertTrue($this->tryLockFromOtherSession());
    }

    public function test_unreachable_host_fails_fast(): void
    {
        $c = TestPostgres::config(self::DB);
        $closedPort = new DbConfig($c->host, 1, $c->database, $c->username, $c->password, 'disable');
        $started = microtime(true);

        try {
            (new PostgresAdvisoryGuard($this->key, 60, 2))->hold($closedPort, static function (): void {
            });
            self::fail('Ожидалось PDOException');
        } catch (\PDOException) {
            self::assertLessThan(3.0, microtime(true) - $started);
        }
    }

    public function test_silent_host_times_out_at_connect_timeout(): void
    {
        // Слушает, но не отвечает: TCP-соединение принимает ядро, а ответа на старт
        // протокола нет — так выглядит коробка за файрволом. Закрытый порт отказывает
        // мгновенно и таймаут не проверяет.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
        $c = TestPostgres::config(self::DB);
        $silent = new DbConfig('127.0.0.1', $port, $c->database, $c->username, $c->password, 'disable');
        $started = microtime(true);

        try {
            (new PostgresAdvisoryGuard($this->key, 60, 2))->hold($silent, static function (): void {
            });
            self::fail('Ожидалось PDOException');
        } catch (\PDOException) {
            self::assertLessThan(5.0, microtime(true) - $started);
        } finally {
            fclose($server);
        }
    }

    private function dropGuardSession(): void
    {
        // Так выглядит обрыв простаивающей сессии блокировки во время долгой миграции.
        $admin = TestPostgres::pdo(self::DB);
        $admin->query(
            "SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype = 'advisory' AND objid::bigint = {$this->key} AND granted",
        );
    }

    public function test_dropped_session_does_not_fail_successful_work(): void
    {
        $done = false;

        (new PostgresAdvisoryGuard($this->key))->hold(TestPostgres::config(self::DB), function () use (&$done): void {
            $this->dropGuardSession();
            $done = true;
        });

        self::assertTrue($done);
        self::assertTrue($this->tryLockFromOtherSession());
    }

    public function test_dropped_session_keeps_the_work_error(): void
    {
        try {
            (new PostgresAdvisoryGuard($this->key))->hold(TestPostgres::config(self::DB), function (): void {
                $this->dropGuardSession();

                throw new \RuntimeException('миграция упала');
            });
            self::fail('Исключение работы должно пробрасываться');
        } catch (\RuntimeException $e) {
            self::assertSame('миграция упала', $e->getMessage());
        }
    }

    public function test_zero_wait_fails_at_once_instead_of_waiting_forever(): void
    {
        // lock_timeout = 0 в PostgreSQL значит «без таймаута»: ожидание 0 не должно вешать CI.
        $holder = TestPostgres::pdo(self::DB);
        $holder->query("SELECT pg_advisory_lock({$this->key})");
        $started = microtime(true);

        try {
            (new PostgresAdvisoryGuard($this->key, 0))->hold(TestPostgres::config(self::DB), static function (): void {
            });
            self::fail('Ожидалось LockTimeoutException');
        } catch (LockTimeoutException) {
            self::assertLessThan(2.0, microtime(true) - $started);
        }
    }
}
