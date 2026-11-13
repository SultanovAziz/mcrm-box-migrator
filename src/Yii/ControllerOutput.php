<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Yii;

use Mcrm\BoxMigrator\Core\Output;
use yii\console\Controller;
use yii\helpers\Console;

final class ControllerOutput implements Output
{
    public function __construct(private readonly Controller $controller)
    {
    }

    public function info(string $message): void
    {
        $this->controller->stdout($message."\n");
    }

    public function warn(string $message): void
    {
        $this->controller->stdout($message."\n", Console::FG_YELLOW);
    }

    public function error(string $message): void
    {
        $this->controller->stderr($message."\n", Console::FG_RED);
    }
}
