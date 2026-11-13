<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Laravel;

use Illuminate\Console\Command;
use Mcrm\BoxMigrator\Core\Output;

final class CommandOutput implements Output
{
    public function __construct(private readonly Command $command)
    {
    }

    public function info(string $message): void
    {
        $this->command->line($message);
    }

    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    public function error(string $message): void
    {
        $this->command->error($message);
    }
}
