<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Yii;

use Mcrm\BoxMigrator\Core\BoxGuard;
use Mcrm\BoxMigrator\Core\BoxMigrator;
use Mcrm\BoxMigrator\Core\CertFlagSecretMapper;
use Mcrm\BoxMigrator\Core\PostgresAdvisoryGuard;
use Mcrm\BoxMigrator\Core\VaultClient;
use Mcrm\BoxMigrator\Core\VaultConfig;
use yii\console\Controller;
use yii\helpers\Console;

/**
 * `php yii postgres/migrate [boxname] --from-vault --pretend` — те же имя и флаги,
 * что у artisan-команды Laravel-сервисов.
 *
 * Подключение в сервисе: 'controllerMap' => ['postgres' => ['class' => PostgresController::class]].
 * Умолчания повторяют рантайм messenger (VaultConnectionStorage): ключ messengers,
 * VAULT_ADDR (по умолчанию http://vault:8200), VAULT_AUTH_LOGIN (по умолчанию mcrm),
 * VAULT_AUTH_PASSWORD и YANDEX_CRT_PATH. Пароля по умолчанию нет — без него
 * команда отказывает.
 */
class PostgresController extends Controller
{
    public bool $fromVault = false;

    public bool $pretend = false;

    public string $serviceKey = 'messengers';

    /** @var list<string> */
    public array $migrationPaths = ['@app/migrations-client'];

    public string $migrateControllerId = 'migrate';

    public ?string $vaultUrl = null;

    public ?string $vaultLogin = null;

    public ?string $vaultPassword = null;

    public string $vaultMount = 'secret';

    public string $vaultBoxesPrefix = '';

    public int $lockWaitSeconds = 60;

    public ?string $certPath = null;

    /** Точка подмены для тестов; в сервисе не задаётся. */
    public ?VaultClient $vaultClient = null;

    /** Точка подмены для тестов; в сервисе не задаётся. */
    public ?BoxGuard $guard = null;

    public function init(): void
    {
        parent::init();

        $this->vaultUrl ??= self::env('VAULT_ADDR') ?? 'http://vault:8200';
        $this->vaultLogin ??= self::env('VAULT_AUTH_LOGIN') ?? 'mcrm';
        $this->vaultPassword ??= self::env('VAULT_AUTH_PASSWORD');
        $this->certPath ??= self::env('YANDEX_CRT_PATH');
    }

    /** @return list<string> */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['fromVault', 'pretend']);
    }

    /**
     * Применяет PostgreSQL-миграции по коробкам, список и подключения берёт из Vault.
     *
     * @param string|null $boxname имя коробки; без него нужен --from-vault
     */
    public function actionMigrate(?string $boxname = null): int
    {
        $box = trim((string) $boxname);

        if ($box === '' && ! $this->fromVault) {
            $this->stderr("Укажите boxname или используйте флаг --from-vault\n", Console::FG_RED);

            return 1;
        }

        try {
            $vault = $this->vaultClient ?? new VaultClient(new VaultConfig(
                (string) $this->vaultUrl,
                (string) $this->vaultLogin,
                (string) $this->vaultPassword,
                $this->vaultMount,
                $this->vaultBoxesPrefix,
            ));
        } catch (\InvalidArgumentException $e) {
            $this->stderr($e->getMessage()."\n", Console::FG_RED);

            return 1;
        }

        $migrator = new BoxMigrator(
            $vault,
            $this->serviceKey,
            new CertFlagSecretMapper($this->certPath),
            $this->guard ?? new PostgresAdvisoryGuard(PostgresAdvisoryGuard::keyFor($this->serviceKey), $this->lockWaitSeconds),
            new YiiMigrationRunner($this->module instanceof \yii\base\Application ? $this->module : \Yii::$app, $this->migrateControllerId, $this->migrationPaths),
            new ControllerOutput($this),
        );

        return $migrator->migrate($box === '' ? null : $box, $this->pretend)->exitCode();
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
