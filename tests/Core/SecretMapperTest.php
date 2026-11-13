<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Core;

use Mcrm\BoxMigrator\Core\CertFlagSecretMapper;
use Mcrm\BoxMigrator\Core\DbBlockSecretMapper;
use Mcrm\BoxMigrator\Core\InvalidSecretException;
use PHPUnit\Framework\TestCase;

final class SecretMapperTest extends TestCase
{
    /** @return array<string, mixed> */
    private function db(array $override = []): array
    {
        return ['db' => array_merge(['host' => 'pg', 'dbname' => 'kz_cash', 'username' => 'cash', 'password' => 'p'], $override)];
    }

    public function test_db_block_defaults(): void
    {
        $c = (new DbBlockSecretMapper())->toDbConfig($this->db());

        self::assertSame(['pg', 'kz_cash', 'cash', 'p'], [$c->host, $c->database, $c->username, $c->password]);
        self::assertSame([5432, 'prefer', null], [$c->port, $c->sslmode, $c->sslrootcert]);
    }

    public function test_db_block_keeps_given_sslmode_and_string_port(): void
    {
        $c = (new DbBlockSecretMapper())->toDbConfig($this->db(['port' => '6432', 'sslmode' => 'require']));

        self::assertSame([6432, 'require'], [$c->port, $c->sslmode]);
    }

    public function test_missing_field_names_it(): void
    {
        $secret = $this->db();
        unset($secret['db']['dbname']);

        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('dbname');

        (new DbBlockSecretMapper())->toDbConfig($secret);
    }

    public function test_empty_field_counts_as_missing(): void
    {
        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('host');

        (new DbBlockSecretMapper())->toDbConfig($this->db(['host' => '']));
    }

    public function test_missing_db_block(): void
    {
        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('db');

        (new DbBlockSecretMapper())->toDbConfig([]);
    }

    public function test_password_may_be_absent(): void
    {
        $secret = $this->db();
        unset($secret['db']['password']);

        self::assertSame('', (new DbBlockSecretMapper())->toDbConfig($secret)->password);
    }

    public function test_cert_flag_on(): void
    {
        $c = (new CertFlagSecretMapper('/certs/ya.crt'))
            ->toDbConfig(['db' => ['host' => 'h', 'dbname' => 'kz', 'username' => 'u', 'isUseCert' => 1]]);

        self::assertSame(['verify-ca', '/certs/ya.crt'], [$c->sslmode, $c->sslrootcert]);
    }

    public function test_cert_flag_off_or_no_path(): void
    {
        $off = (new CertFlagSecretMapper('/certs/ya.crt'))
            ->toDbConfig(['db' => ['host' => 'h', 'dbname' => 'kz', 'username' => 'u', 'isUseCert' => 0]]);
        $noPath = (new CertFlagSecretMapper(null))
            ->toDbConfig(['db' => ['host' => 'h', 'dbname' => 'kz', 'username' => 'u', 'isUseCert' => 1]]);

        self::assertSame([null, null], [$off->sslmode, $off->sslrootcert]);
        self::assertSame([null, null], [$noPath->sslmode, $noPath->sslrootcert]);
    }

    public function test_cert_flag_mapper_requires_same_fields(): void
    {
        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('username');

        (new CertFlagSecretMapper(null))->toDbConfig(['db' => ['host' => 'h', 'dbname' => 'kz']]);
    }
}
