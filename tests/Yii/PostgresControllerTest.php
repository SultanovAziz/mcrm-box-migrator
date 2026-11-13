<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Yii;

use Mcrm\BoxMigrator\Core\VaultConfig;
use Mcrm\BoxMigrator\Tests\Support\FakeVault;
use Mcrm\BoxMigrator\Tests\Support\PassThroughGuard;
use Mcrm\BoxMigrator\Tests\Support\TestPostgres;
use Mcrm\BoxMigrator\Yii\PostgresController;
use PHPUnit\Framework\TestCase;
use yii\console\Application;
use yii\console\controllers\MigrateController;
use yii\db\Connection;

final class PostgresControllerTest extends TestCase
{
    private const KEY = 'messengers';

    public static function setUpBeforeClass(): void
    {
        if (! class_exists('Yii', false)) {
            // Обработчик ошибок Yii перехватил бы ошибки PHPUnit.
            defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
            require_once dirname(__DIR__, 2).'/vendor/yiisoft/yii2/Yii.php';
        }
    }

    protected function setUp(): void
    {
        if (! TestPostgres::available()) {
            self::markTestSkipped('нет TEST_PG_HOST — запускать через scripts/test.sh');
        }

        TestPostgres::recreate('box_a');
        TestPostgres::recreate('box_b');
    }

    protected function tearDown(): void
    {
        \Yii::$app = null;
    }

    private function fakeVault(?array $boxA = null): FakeVault
    {
        return (new FakeVault())
            ->withBoxes(['box_a/', 'box_b/', 'card/'])
            ->withSecret('box_a', self::KEY, $boxA ?? TestPostgres::secret('box_a'))
            ->withSecret('box_b', self::KEY, TestPostgres::secret('box_b'));
    }

    /** @param array<string, mixed> $controller */
    private function app(?FakeVault $vault, array $controller = []): Application
    {
        $default = TestPostgres::config('postgres');

        return new Application([
            'id' => 'box-migrator-test',
            'basePath' => dirname(__DIR__).'/Fixtures/yii',
            'components' => [
                'db' => ['class' => Connection::class, 'dsn' => $default->dsn(), 'username' => $default->username, 'password' => $default->password],
            ],
            'controllerMap' => [
                'migrate' => ['class' => MigrateController::class, 'migrationPath' => '@app/migrations'],
                'postgres' => array_merge([
                    'class' => PostgresController::class,
                    'migrationPaths' => ['@app/migrations'],
                    'vaultClient' => $vault?->client(new VaultConfig('http://vault.test', 'mcrm', 'pa55-vault')),
                ], $controller),
            ],
        ]);
    }

    private function probe(string $db): string|false
    {
        return TestPostgres::pdo($db)->query('SELECT box FROM box_probe')->fetchColumn();
    }

    public function test_kebab_flag_is_accepted(): void
    {
        $app = $this->app($this->fakeVault());

        self::assertSame(0, $app->runAction('postgres/migrate', ['from-vault' => true]));
        self::assertTrue(TestPostgres::tableExists('box_a', 'box_probe'));
    }

    public function test_migrates_boxes_and_sets_boxname(): void
    {
        $this->app($this->fakeVault())->runAction('postgres/migrate', ['fromVault' => true]);

        self::assertSame('box_a', $this->probe('box_a'));
        self::assertSame('box_b', $this->probe('box_b'));
    }

    public function test_single_box(): void
    {
        self::assertSame(0, $this->app($this->fakeVault())->runAction('postgres/migrate', ['box_b']));

        self::assertTrue(TestPostgres::tableExists('box_b', 'box_probe'));
        self::assertFalse(TestPostgres::tableExists('box_a', 'box_probe'));
    }

    public function test_pretend_lists_without_applying(): void
    {
        self::assertSame(0, $this->app($this->fakeVault())->runAction('postgres/migrate', ['from-vault' => true, 'pretend' => true]));

        self::assertFalse(TestPostgres::tableExists('box_a', 'box_probe'));
        self::assertFalse(TestPostgres::tableExists('box_b', 'box_probe'));
    }

    public function test_failure_restores_db_and_params(): void
    {
        $secret = TestPostgres::secret('box_a');
        $secret['db']['password'] = 'wrong-password';
        // Без лока: ошибка подключения должна случиться внутри мигратора Yii.
        $app = $this->app($this->fakeVault($secret), ['guard' => new PassThroughGuard()]);
        $db = $app->get('db');

        self::assertSame(1, $app->runAction('postgres/migrate', ['from-vault' => true]));

        self::assertSame($db, $app->get('db'));
        self::assertArrayNotHasKey('currentBoxname', $app->params);
        self::assertSame('box_b', $this->probe('box_b'));
    }

    public function test_without_box_and_flag_fails(): void
    {
        self::assertSame(1, $this->app($this->fakeVault())->runAction('postgres/migrate'));
    }

    public function test_missing_vault_password_fails(): void
    {
        $app = $this->app(null, ['vaultUrl' => 'http://vault.test', 'vaultPassword' => null]);

        self::assertSame(1, $app->runAction('postgres/migrate', ['from-vault' => true]));
    }
}
