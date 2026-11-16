<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Laravel;

use Illuminate\Console\Command;
use Mcrm\BoxMigrator\Core\BoxGuard;
use Mcrm\BoxMigrator\Core\BoxMigrator;
use Mcrm\BoxMigrator\Core\DbBlockSecretMapper;
use Mcrm\BoxMigrator\Core\VaultClient;

/**
 * Имя и флаги повторяют прежние postgres:migrate кассы, main-crm и community:
 * их уже вызывают скрипты стенда, и одна операция должна называться одинаково
 * во всех сервисах.
 */
final class PostgresMigrateCommand extends Command
{
    protected $signature = 'postgres:migrate
        {boxname?      : Имя коробки; без него нужен --from-vault}
        {--from-vault  : Взять список коробок из Vault}
        {--pretend     : Показать SQL, не применяя миграции}';

    protected $description = 'Применяет PostgreSQL-миграции по коробкам, список и подключения берёт из Vault (mcrm/box-migrator)';

    public function handle(): int
    {
        $box = trim((string) ($this->argument('boxname') ?? ''));

        if ($box === '' && ! $this->option('from-vault')) {
            $this->error('Укажите boxname или используйте флаг --from-vault');

            return self::FAILURE;
        }

        $config = $this->laravel->make('config');

        try {
            $serviceKey = Settings::serviceKey($config);
            $vault = $this->laravel->make(VaultClient::class);
            $guard = $this->laravel->make(BoxGuard::class);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $runner = new LaravelMigrationRunner(
            $this->laravel,
            (string) $config->get('box-migrator.connection', 'pgsql'),
            array_values((array) $config->get('box-migrator.paths', [])),
            $this->getOutput(),
        );

        $migrator = new BoxMigrator(
            $vault,
            $serviceKey,
            new DbBlockSecretMapper((string) $config->get('box-migrator.default_sslmode', 'prefer')),
            $guard,
            $runner,
            new CommandOutput($this),
        );

        return $migrator->migrate($box === '' ? null : $box, (bool) $this->option('pretend'))->exitCode();
    }
}
