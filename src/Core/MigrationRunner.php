<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Прогон миграций сервиса на базе одной коробки родным мигратором фреймворка.
 *
 * Реализация бросает исключение при неудаче и в любом исходе сама закрывает свои
 * соединения и возвращает фреймворк в состояние до вызова: следующая коробка и
 * код после команды не должны видеть подменённое подключение.
 */
interface MigrationRunner
{
    public function run(string $box, DbConfig $db, bool $pretend): void;
}
