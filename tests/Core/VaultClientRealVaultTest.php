<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Core;

use GuzzleHttp\Client;
use Mcrm\BoxMigrator\Core\VaultClient;
use Mcrm\BoxMigrator\Core\VaultConfig;
use Mcrm\BoxMigrator\Core\VaultException;
use PHPUnit\Framework\TestCase;

/**
 * Клиент против настоящего Vault (scripts/test.sh поднимает его в dev-режиме).
 *
 * FakeVault повторяет наши представления об API Vault; этот тест проверяет сами
 * представления: глагол LIST, форму KV v2, вход userpass, коды 400/403/404 и
 * истечение токена. Политика bm-stand — та же, что стенд выдаёт сервисам.
 */
final class VaultClientRealVaultTest extends TestCase
{
    private static string $addr = '';

    public static function setUpBeforeClass(): void
    {
        self::$addr = rtrim((string) getenv('TEST_VAULT_ADDR'), '/');

        if (self::$addr === '') {
            return;
        }

        $root = new Client([
            'base_uri' => self::$addr.'/v1/',
            'headers' => ['X-Vault-Token' => (string) getenv('TEST_VAULT_ROOT_TOKEN')],
            'http_errors' => false,
            'timeout' => 5,
        ]);

        $root->post('sys/auth/userpass', ['json' => ['type' => 'userpass']]); // 400, если уже включён
        $root->put('sys/policies/acl/bm-stand', ['json' => ['policy' => <<<'HCL'
            path "secret/data/*" { capabilities = ["read"] }
            path "secret/metadata/*" { capabilities = ["read", "list"] }
            path "secret/metadata" { capabilities = ["list"] }
            HCL]]);
        $root->put('sys/policies/acl/bm-perbox', ['json' => ['policy' => <<<'HCL'
            path "secret/data/kz/*" { capabilities = ["read"] }
            path "secret/metadata/*" { capabilities = ["list"] }
            HCL]]);
        $root->post('auth/userpass/users/bm-stand', ['json' => ['password' => 'pw-stand', 'policies' => 'bm-stand']]);
        $root->post('auth/userpass/users/bm-perbox', ['json' => ['password' => 'pw-perbox', 'policies' => 'bm-perbox']]);
        $root->post('auth/userpass/users/bm-short', ['json' => [
            'password' => 'pw-short', 'policies' => 'bm-stand', 'token_ttl' => '1s', 'token_max_ttl' => '1s',
        ]]);

        $root->post('secret/data/kz/cash_service', ['json' => ['data' => ['boxname' => 'kz', 'db' => ['host' => 'pg', 'dbname' => 'kz_cash', 'username' => 'cash']]]]);
        $root->post('secret/data/card/audience/sync', ['json' => ['data' => ['x' => 1]]]);
        $root->post('secret/data/note', ['json' => ['data' => ['x' => 1]]]);
    }

    protected function setUp(): void
    {
        if (self::$addr === '') {
            self::markTestSkipped('нет TEST_VAULT_ADDR — запускать через scripts/test.sh');
        }
    }

    private function client(string $login, string $password): VaultClient
    {
        return new VaultClient(new VaultConfig(self::$addr, $login, $password, 'secret', '', 5));
    }

    public function test_lists_directories_only(): void
    {
        self::assertSame(['card', 'kz'], $this->client('bm-stand', 'pw-stand')->listBoxes());
    }

    public function test_reads_kv2_secret_and_missing_is_null(): void
    {
        $vault = $this->client('bm-stand', 'pw-stand');

        self::assertSame('kz_cash', $vault->readSecret('kz', 'cash_service')['db']['dbname'] ?? null);
        self::assertNull($vault->readSecret('card', 'cash_service'));
    }

    public function test_path_denied_by_policy_is_an_error_not_a_skip(): void
    {
        // Политика по коробкам: на каталог-некоробку Vault отвечает 403, а не 404.
        $vault = $this->client('bm-perbox', 'pw-perbox');
        self::assertIsArray($vault->readSecret('kz', 'cash_service'));

        $this->expectException(VaultException::class);
        $this->expectExceptionMessage('403');

        $vault->readSecret('card', 'cash_service');
    }

    public function test_expired_token_is_renewed_by_relogin(): void
    {
        $vault = $this->client('bm-short', 'pw-short');
        self::assertIsArray($vault->readSecret('kz', 'cash_service'));

        sleep(2); // токен жил 1 с

        self::assertIsArray($vault->readSecret('kz', 'cash_service'));
    }

    public function test_wrong_password_is_a_clear_error(): void
    {
        try {
            $this->client('bm-stand', 'wrong-password')->listBoxes();
            self::fail('Ожидалось VaultException');
        } catch (VaultException $e) {
            self::assertStringContainsString('400', $e->getMessage());
            self::assertStringContainsString('auth/userpass/login/bm-stand', $e->getMessage());
            self::assertStringNotContainsString('wrong-password', $e->getMessage());
        }
    }
}
