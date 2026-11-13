<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Support;

use Mcrm\BoxMigrator\Core\Output;

final class MemoryOutput implements Output
{
    /** @var list<string> */
    private array $lines = [];

    public function info(string $message): void
    {
        $this->lines[] = "info: {$message}";
    }

    public function warn(string $message): void
    {
        $this->lines[] = "warn: {$message}";
    }

    public function error(string $message): void
    {
        $this->lines[] = "error: {$message}";
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }
}
