<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Yii;

use Mcrm\BoxMigrator\Core\DbConfig;
use Mcrm\BoxMigrator\Core\MigrationRunner;
use yii\base\Application;
use yii\db\Connection;

/**
 * Прогон `migrate/up` на базе коробки: компонент db на время прогона указывает
 * на коробку, params['currentBoxname'] — её имя (миграции messenger его читают).
 * После прогона — успешного или нет — оба возвращаются как были.
 *
 * Своего --pretend у Yii нет; вместо него `migrate/new all` — список того, что
 * было бы применено.
 */
final class YiiMigrationRunner implements MigrationRunner
{
    /** @param list<string> $migrationPaths */
    public function __construct(
        private readonly Application $app,
        private readonly string $migrateControllerId,
        private readonly array $migrationPaths,
    ) {
    }

    public function run(string $box, DbConfig $db, bool $pretend): void
    {
        $previousDb = $this->app->get('db', false);
        $hadBoxname = array_key_exists('currentBoxname', $this->app->params);
        $previousBoxname = $this->app->params['currentBoxname'] ?? null;

        $connection = new Connection([
            'dsn' => $db->dsn(),
            'username' => $db->username,
            'password' => $db->password,
            'charset' => 'utf8',
        ]);

        try {
            $connection->open();
            $this->app->set('db', $connection);
            $this->app->params['currentBoxname'] = $box;

            foreach ($this->migrationPaths as $path) {
                $route = $this->migrateControllerId.($pretend ? '/new' : '/up');
                $params = ['interactive' => '0', 'migrationPath' => $path];
                $code = $this->app->runAction($route, $pretend ? array_merge(['all'], $params) : $params);

                if ((int) $code !== 0) {
                    throw new \RuntimeException("{$route} ({$path}) завершился с кодом {$code}");
                }
            }
        } finally {
            $connection->close();

            if ($previousDb !== null) {
                $this->app->set('db', $previousDb);
            } else {
                $this->app->clear('db');
            }

            if ($hadBoxname) {
                $this->app->params['currentBoxname'] = $previousBoxname;
            } else {
                unset($this->app->params['currentBoxname']);
            }
        }
    }
}
