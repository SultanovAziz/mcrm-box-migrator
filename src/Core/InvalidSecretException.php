<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/** Секрет есть, но по нему не собрать подключение. Текст называет поле, не значение. */
final class InvalidSecretException extends \RuntimeException
{
}
