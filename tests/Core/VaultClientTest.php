<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Core;

use Mcrm\BoxMigrator\Core\VaultConfig;
use Mcrm\BoxMigrator\Core\VaultException;
use Mcrm\BoxMigrator\Tests\Support\FakeVault;
use PHPUnit\Framework\TestCase;

final class VaultClientTest extends TestCase
{
    private function cfg(string $prefix = ''): VaultConfig
    {
        return new VaultConfig('http://vault.test', 'mcrm', 'pa55-vault', 'secret', $prefix, 5);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function byMethod(FakeVault $fake, string $method): array
    {
        return array_values(array_filter($fake->requests(), static fn (array $r): bool => $r[0] === $method));
    }

    public function test_lists_only_directories_sorted(): void
    {
        $v = (new FakeVault())->withBoxes(['krsk/', 'kz/', 'note', 'card/'])->client($this->cfg());

        self::assertSame(['card', 'krsk', 'kz'], $v->listBoxes());
    }

    public function test_list_uses_prefix(): void
    {
        $fake = (new FakeVault())->withBoxes(['kz/']);
        $fake->client($this->cfg('boxes'))->listBoxes();

        self::assertSame('/v1/secret/metadata/boxes', $this->byMethod($fake, 'LIST')[0][1]);
    }

    public function test_reads_secret_data(): void
    {
        $v = (new FakeVault())->withSecret('kz', 'cash_service', ['db' => ['host' => 'pg']])->client($this->cfg());

        self::assertSame(['db' => ['host' => 'pg']], $v->readSecret('kz', 'cash_service'));
    }

    public function test_missing_secret_is_null(): void
    {
        $v = (new FakeVault())->client($this->cfg());

        self::assertNull($v->readSecret('card', 'cash_service'));
    }

    public function test_logs_in_once_and_sends_token(): void
    {
        $fake = (new FakeVault())->withSecret('kz', 'cash_service', ['db' => []])->withSecret('krsk', 'cash_service', ['db' => []]);
        $v = $fake->client($this->cfg());

        $v->readSecret('kz', 'cash_service');
        $v->readSecret('krsk', 'cash_service');

        self::assertCount(1, $this->byMethod($fake, 'POST'));
        self::assertSame('/v1/auth/userpass/login/mcrm', $this->byMethod($fake, 'POST')[0][1]);
        self::assertSame(['t-1', 't-1'], array_column($this->byMethod($fake, 'GET'), 2));
    }

    public function test_relogins_once_on_403(): void
    {
        $fake = (new FakeVault())
            ->withSecret('kz', 'cash_service', ['db' => []])
            ->failNext('GET', 'kz/cash_service', 403);

        $secret = $fake->client($this->cfg())->readSecret('kz', 'cash_service');

        self::assertSame(['db' => []], $secret);
        self::assertCount(2, $this->byMethod($fake, 'POST'));
    }

    public function test_second_403_throws_without_secrets(): void
    {
        $fake = (new FakeVault())
            ->withSecret('kz', 'cash_service', ['db' => []])
            ->failNext('GET', 'kz/cash_service', 403)
            ->failNext('GET', 'kz/cash_service', 403);

        try {
            $fake->client($this->cfg())->readSecret('kz', 'cash_service');
            self::fail('Ожидалось VaultException');
        } catch (VaultException $e) {
            self::assertStringContainsString('403', $e->getMessage());
            self::assertStringContainsString('secret/data/kz/cash_service', $e->getMessage());
            self::assertStringNotContainsString('pa55-vault', $e->getMessage());
            self::assertStringNotContainsString('t-1', $e->getMessage());
        }
    }

    public function test_list_failure_throws(): void
    {
        $fake = (new FakeVault())->withBoxes(['kz/'])->failNext('LIST', '/metadata', 500);

        $this->expectException(VaultException::class);
        $this->expectExceptionMessage('500');

        $fake->client($this->cfg())->listBoxes();
    }

    public function test_box_name_is_url_encoded(): void
    {
        $fake = new FakeVault();
        $fake->client($this->cfg())->readSecret('a b', 'x');

        self::assertSame('/v1/secret/data/a%20b/x', $this->byMethod($fake, 'GET')[0][1]);
    }

    public function test_secret_path_for_messages(): void
    {
        self::assertSame('secret/data/kz/cash_service', (new FakeVault())->client($this->cfg())->secretPath('kz', 'cash_service'));
    }

    public function test_config_rejects_empty_password_without_echoing_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('password');

        new VaultConfig('http://vault.test', 'mcrm', '');
    }
}
