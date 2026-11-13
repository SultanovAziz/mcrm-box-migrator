<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Laravel;

use Mcrm\BoxMigrator\Core\BoxGuard;
use Mcrm\BoxMigrator\Core\VaultClient;
use Mcrm\BoxMigrator\Core\VaultConfig;
use Mcrm\BoxMigrator\Laravel\BoxMigratorServiceProvider;
use Mcrm\BoxMigrator\Tests\Support\FakeVault;
use Mcrm\BoxMigrator\Tests\Support\PassThroughGuard;
use Mcrm\BoxMigrator\Tests\Support\TestPostgres;
use Orchestra\Testbench\TestCase;

final class PostgresMigrateCommandTest extends TestCase
{
    private const KEY = 'cash_service';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [BoxMigratorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('box-migrator.service_key', self::KEY);
        $app['config']->set('box-migrator.paths', [dirname(__DIR__).'/Fixtures/laravel-migrations']);
        $app['config']->set('box-migrator.vault.url', 'http://vault.test');
        $app['config']->set('box-migrator.vault.login', 'mcrm');
        $app['config']->set('box-migrator.vault.password', 'pa55-vault');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! TestPostgres::available()) {
            self::markTestSkipped('нет TEST_PG_HOST — запускать через scripts/test.sh');
        }

        TestPostgres::recreate('box_a');
        TestPostgres::recreate('box_b');

        $this->useVault((new FakeVault())
            ->withBoxes(['box_a/', 'box_b/', 'card/'])
            ->withSecret('box_a', self::KEY, TestPostgres::secret('box_a'))
            ->withSecret('box_b', self::KEY, TestPostgres::secret('box_b')));
    }

    private function useVault(FakeVault $fake): void
    {
        $this->app->instance(VaultClient::class, $fake->client(new VaultConfig('http://vault.test', 'mcrm', 'pa55-vault')));
    }

    /** @return array{0: mixed, 1: mixed} */
    private function frameworkState(): array
    {
        return [config('database.default'), config('database.connections.pgsql')];
    }

    private function migrationRows(string $db): int
    {
        return (int) TestPostgres::pdo($db)->query('SELECT count(*) FROM migrations')->fetchColumn();
    }

    public function test_without_box_and_flag_fails(): void
    {
        $this->artisan('postgres:migrate')->assertExitCode(1);
    }

    public function test_migrates_every_box_from_vault(): void
    {
        $before = $this->frameworkState();

        $this->artisan('postgres:migrate --from-vault')->assertExitCode(0);

        foreach (['box_a', 'box_b'] as $db) {
            self::assertTrue(TestPostgres::tableExists($db, 'box_probe'), $db);
            self::assertSame(1, $this->migrationRows($db), $db);
        }
        self::assertSame($before, $this->frameworkState());
    }

    public function test_second_run_is_noop(): void
    {
        $this->artisan('postgres:migrate --from-vault')->assertExitCode(0);
        $this->artisan('postgres:migrate --from-vault')->assertExitCode(0);

        self::assertSame(1, $this->migrationRows('box_a'));
    }

    public function test_single_box(): void
    {
        $this->artisan('postgres:migrate box_b')->assertExitCode(0);

        self::assertTrue(TestPostgres::tableExists('box_b', 'box_probe'));
        self::assertFalse(TestPostgres::tableExists('box_a', 'box_probe'));
    }

    public function test_pretend_creates_nothing(): void
    {
        $this->artisan('postgres:migrate --from-vault --pretend')->assertExitCode(0);

        self::assertFalse(TestPostgres::tableExists('box_a', 'box_probe'));
        self::assertFalse(TestPostgres::tableExists('box_b', 'box_probe'));
    }

    public function test_failed_box_restores_framework_state(): void
    {
        $secret = TestPostgres::secret('box_a');
        $secret['db']['password'] = 'wrong-password';
        $this->useVault((new FakeVault())
            ->withBoxes(['box_a/', 'box_b/'])
            ->withSecret('box_a', self::KEY, $secret)
            ->withSecret('box_b', self::KEY, TestPostgres::secret('box_b')));
        // Без лока: ошибка подключения должна случиться внутри мигратора Laravel.
        $this->app->instance(BoxGuard::class, new PassThroughGuard());
        $before = $this->frameworkState();

        $this->artisan('postgres:migrate --from-vault')->assertExitCode(1);

        self::assertSame($before, $this->frameworkState());
        self::assertTrue(TestPostgres::tableExists('box_b', 'box_probe'));
    }

    public function test_falls_back_to_connection_storage_config(): void
    {
        config(['box-migrator.service_key' => null, 'connection-storage.service.key' => self::KEY]);

        $this->artisan('postgres:migrate --from-vault')->assertExitCode(0);

        self::assertTrue(TestPostgres::tableExists('box_a', 'box_probe'));
    }

    public function test_missing_vault_password_is_clear_error(): void
    {
        $this->app->forgetInstance(VaultClient::class);
        config(['box-migrator.vault.password' => null, 'connection-storage.vault.password' => '']);

        $this->artisan('postgres:migrate --from-vault')
            ->expectsOutputToContain('password')
            ->assertExitCode(1);
    }

    public function test_missing_box_database_is_not_created(): void
    {
        TestPostgres::pdo('postgres')->exec('DROP DATABASE IF EXISTS box_missing WITH (FORCE)');
        $this->useVault((new FakeVault())
            ->withBoxes(['box_missing/'])
            ->withSecret('box_missing', self::KEY, TestPostgres::secret('box_missing')));

        // migrate --force в Laravel 11+ создаёт отсутствующую базу сам; создавать базы
        // коробок — не дело мигратора, поэтому коробка должна упасть, а база — не появиться.
        $this->artisan('postgres:migrate --from-vault')->assertExitCode(1);

        $exists = TestPostgres::pdo('postgres')->query("SELECT 1 FROM pg_database WHERE datname = 'box_missing'")->fetchColumn();
        self::assertFalse($exists);
    }
}
