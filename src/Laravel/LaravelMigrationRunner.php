<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Laravel;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Mcrm\BoxMigrator\Core\DbConfig;
use Mcrm\BoxMigrator\Core\MigrationRunner;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Прогон `migrate` на базе коробки: соединение $connection на время прогона
 * указывает на коробку и становится соединением по умолчанию, затем всё
 * возвращается как было — и после успеха, и после ошибки.
 *
 * `migrate --force` в Laravel 11+ сам создаёт отсутствующую базу PostgreSQL.
 * До этого кода дело не доходит: PostgresAdvisoryGuard подключается к базе
 * коробки первым и на несуществующей базе падает раньше.
 */
final class LaravelMigrationRunner implements MigrationRunner
{
    /** @param list<string> $paths */
    public function __construct(
        private readonly Application $app,
        private readonly string $connection,
        private readonly array $paths,
        private readonly OutputInterface $output,
    ) {
    }

    public function run(string $box, DbConfig $db, bool $pretend): void
    {
        $config = $this->app->make('config');
        $manager = $this->app->make('db');
        $key = "database.connections.{$this->connection}";
        $previousDefault = $config->get('database.default');
        $previousConnection = $config->get($key);

        $config->set($key, $this->connectionConfig($db));
        $config->set('database.default', $this->connection);
        $manager->purge($this->connection);

        try {
            $code = $this->app->make(Kernel::class)->call('migrate', $this->parameters($pretend), $this->output);

            if ($code !== 0) {
                throw new \RuntimeException("migrate завершился с кодом {$code}");
            }
        } finally {
            $manager->purge($this->connection);
            $config->set($key, $previousConnection);
            $config->set('database.default', $previousDefault);
        }
    }

    /** @return array<string, mixed> */
    private function connectionConfig(DbConfig $db): array
    {
        $connection = [
            'driver' => 'pgsql',
            'host' => $db->host,
            'port' => $db->port,
            'database' => $db->database,
            'username' => $db->username,
            'password' => $db->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $db->sslmode ?? 'prefer',
        ];

        if ($db->sslrootcert !== null) {
            $connection['sslrootcert'] = $db->sslrootcert;
        }

        return $connection;
    }

    /** @return array<string, mixed> */
    private function parameters(bool $pretend): array
    {
        $parameters = ['--database' => $this->connection, '--force' => true];

        if ($pretend) {
            $parameters['--pretend'] = true;
        }

        if ($this->paths !== []) {
            $parameters['--path'] = array_map(
                fn (string $path): string => str_starts_with($path, '/') ? $path : $this->app->basePath($path),
                $this->paths,
            );
            $parameters['--realpath'] = true;
        }

        return $parameters;
    }
}
