<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Support;

use Mcrm\BoxMigrator\Core\DbConfig;
use Mcrm\BoxMigrator\Core\MigrationRunner;

/** Мигратор фреймворка-заглушка: помнит вызовы, падает на заданных коробках. */
final class FakeRunner implements MigrationRunner
{
    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @var list<array{0: string, 1: bool, 2: DbConfig}> */
    private array $calls = [];

    public function failOn(string $box, \Throwable $e): self
    {
        $this->failures[$box] = $e;

        return $this;
    }

    public function run(string $box, DbConfig $db, bool $pretend): void
    {
        $this->calls[] = [$box, $pretend, $db];

        if (isset($this->failures[$box])) {
            throw $this->failures[$box];
        }
    }

    /** @return list<array{0: string, 1: bool, 2: DbConfig}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
