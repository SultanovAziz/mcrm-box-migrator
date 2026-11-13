<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Support;

use Mcrm\BoxMigrator\Core\BoxGuard;
use Mcrm\BoxMigrator\Core\DbConfig;

/** Без лока и без своего соединения: ошибка подключения случается уже внутри мигратора фреймворка. */
final class PassThroughGuard implements BoxGuard
{
    public function hold(DbConfig $db, callable $work): void
    {
        $work();
    }
}
