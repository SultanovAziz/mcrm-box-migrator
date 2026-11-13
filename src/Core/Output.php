<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/** Вывод команды; адаптер фреймворка направляет его в консоль. */
interface Output
{
    public function info(string $message): void;

    public function warn(string $message): void;

    public function error(string $message): void;
}
