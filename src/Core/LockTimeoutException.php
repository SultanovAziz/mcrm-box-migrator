<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/** База коробки дольше отведённого занята другим прогоном миграций. */
final class LockTimeoutException extends \RuntimeException
{
}
