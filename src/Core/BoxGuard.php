<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/** Обёртка вокруг работы с базой коробки (лок, проверка связи). */
interface BoxGuard
{
    /** @param callable(): void $work */
    public function hold(DbConfig $db, callable $work): void;
}
