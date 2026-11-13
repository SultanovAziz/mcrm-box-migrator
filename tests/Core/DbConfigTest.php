<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Core;

use Mcrm\BoxMigrator\Core\DbConfig;
use PHPUnit\Framework\TestCase;

final class DbConfigTest extends TestCase
{
    public function test_dsn_without_tls(): void
    {
        $c = new DbConfig('pg', 5432, 'kz_cash', 'cash', 's3cret');

        self::assertSame('pgsql:host=pg;port=5432;dbname=kz_cash', $c->dsn());
    }

    public function test_dsn_with_tls(): void
    {
        $c = new DbConfig('db.local', 6432, 'kz', 'u', 'p', 'verify-ca', '/certs/ya.crt');

        self::assertSame(
            'pgsql:host=db.local;port=6432;dbname=kz;sslmode=verify-ca;sslrootcert=/certs/ya.crt',
            $c->dsn(),
        );
    }

    public function test_describe_and_dump_hide_password(): void
    {
        $c = new DbConfig('pg', 5432, 'kz_cash', 'cash', 's3cret');

        self::assertSame('pg:5432/kz_cash', $c->describe());
        self::assertStringNotContainsString('s3cret', print_r($c, true));
        self::assertStringNotContainsString('s3cret', var_export($c->__debugInfo(), true));
    }
}
