<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Laravel;

use Illuminate\Support\ServiceProvider;
use Mcrm\BoxMigrator\Core\BoxGuard;
use Mcrm\BoxMigrator\Core\PostgresAdvisoryGuard;
use Mcrm\BoxMigrator\Core\VaultClient;

final class BoxMigratorServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../../config/box-migrator.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'box-migrator');

        $this->app->singleton(
            VaultClient::class,
            static fn ($app): VaultClient => new VaultClient(Settings::vault($app->make('config'))),
        );

        $this->app->bind(BoxGuard::class, static function ($app): BoxGuard {
            $config = $app->make('config');

            return new PostgresAdvisoryGuard(
                PostgresAdvisoryGuard::keyFor(Settings::serviceKey($config)),
                (int) $config->get('box-migrator.lock_wait_seconds', 60),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG => $this->app->configPath('box-migrator.php')], 'box-migrator-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PostgresMigrateCommand::class]);
        }
    }
}
