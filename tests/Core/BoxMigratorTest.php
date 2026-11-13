<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Core;

use Mcrm\BoxMigrator\Core\BoxMigrator;
use Mcrm\BoxMigrator\Core\DbBlockSecretMapper;
use Mcrm\BoxMigrator\Core\VaultConfig;
use Mcrm\BoxMigrator\Tests\Support\FakeRunner;
use Mcrm\BoxMigrator\Tests\Support\FakeVault;
use Mcrm\BoxMigrator\Tests\Support\MemoryOutput;
use Mcrm\BoxMigrator\Tests\Support\PassThroughGuard;
use PHPUnit\Framework\TestCase;

final class BoxMigratorTest extends TestCase
{
    private const KEY = 'cash_service';

    private MemoryOutput $out;

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
    }

    private function migrator(FakeVault $fake, FakeRunner $runner): BoxMigrator
    {
        $vault = $fake->client(new VaultConfig('http://vault.test', 'mcrm', 'pa55-vault'));

        return new BoxMigrator($vault, self::KEY, new DbBlockSecretMapper(), new PassThroughGuard(), $runner, $this->out);
    }

    /** @return array<string, mixed> */
    private function secret(string $db, string $password = 'p'): array
    {
        return ['db' => ['host' => 'pg', 'dbname' => $db, 'username' => 'cash', 'password' => $password]];
    }

    private function twoBoxes(): FakeVault
    {
        return (new FakeVault())
            ->withBoxes(['kz/', 'krsk/', 'card/'])
            ->withSecret('kz', self::KEY, $this->secret('kz_cash'))
            ->withSecret('krsk', self::KEY, $this->secret('krsk_cash'));
    }

    private function printed(): string
    {
        return implode("\n", $this->out->lines());
    }

    public function test_migrates_all_boxes_and_skips_non_boxes(): void
    {
        $r = $this->migrator($this->twoBoxes(), new FakeRunner())->migrate(null, false);

        self::assertSame(['krsk', 'kz'], $r->migrated);
        self::assertArrayHasKey('card', $r->skipped);
        self::assertStringContainsString('secret/data/card/cash_service', $r->skipped['card']);
        self::assertSame([], $r->failed);
        self::assertSame(0, $r->exitCode());
        self::assertStringContainsString('Итог: мигрировано 2, с ошибкой 0, пропущено 1', $this->printed());
    }

    public function test_one_failure_does_not_stop_others(): void
    {
        $runner = (new FakeRunner())->failOn('kz', new \RuntimeException('relation already exists'));

        $r = $this->migrator($this->twoBoxes(), $runner)->migrate(null, false);

        self::assertSame(['krsk'], $r->migrated);
        self::assertSame(['kz' => 'relation already exists'], $r->failed);
        self::assertSame(['krsk', 'kz'], array_column($runner->calls(), 0));
        self::assertSame(1, $r->exitCode());
    }

    public function test_vault_error_on_one_secret_fails_only_that_box(): void
    {
        $fake = $this->twoBoxes()
            ->failNext('GET', 'krsk/cash_service', 403)
            ->failNext('GET', 'krsk/cash_service', 403);

        $r = $this->migrator($fake, new FakeRunner())->migrate(null, false);

        self::assertSame(['kz'], $r->migrated);
        self::assertStringContainsString('403', $r->failed['krsk']);
    }

    public function test_nothing_migrated_is_failure(): void
    {
        $fake = (new FakeVault())->withBoxes(['card/', 'transactions/']);

        $r = $this->migrator($fake, new FakeRunner())->migrate(null, false);

        self::assertSame([], $r->migrated);
        self::assertSame([], $r->failed);
        self::assertFalse($r->isSuccess());
        self::assertSame(1, $r->exitCode());
        self::assertStringContainsString(self::KEY, $this->printed());
    }

    public function test_empty_list_is_fatal(): void
    {
        $runner = new FakeRunner();

        $r = $this->migrator((new FakeVault())->withBoxes([]), $runner)->migrate(null, false);

        self::assertNotNull($r->fatal);
        self::assertSame(1, $r->exitCode());
        self::assertSame([], $runner->calls());
    }

    public function test_vault_list_error_is_fatal(): void
    {
        $fake = $this->twoBoxes()->failNext('LIST', '/metadata', 500);

        $r = $this->migrator($fake, new FakeRunner())->migrate(null, false);

        self::assertStringContainsString('500', (string) $r->fatal);
        self::assertSame(1, $r->exitCode());
    }

    public function test_explicit_box_without_secret_fails(): void
    {
        $fake = $this->twoBoxes();

        $r = $this->migrator($fake, new FakeRunner())->migrate('nope', false);

        self::assertArrayHasKey('nope', $r->failed);
        self::assertSame([], $r->skipped);
        self::assertSame(1, $r->exitCode());
        self::assertSame([], array_filter($fake->requests(), static fn (array $q): bool => $q[0] === 'LIST'));
    }

    public function test_explicit_box_with_secret(): void
    {
        $r = $this->migrator($this->twoBoxes(), new FakeRunner())->migrate('kz', false);

        self::assertSame(['kz'], $r->migrated);
        self::assertSame(0, $r->exitCode());
    }

    public function test_invalid_secret_fails_box(): void
    {
        $fake = (new FakeVault())->withBoxes(['kz/'])->withSecret('kz', self::KEY, ['db' => ['host' => 'pg', 'username' => 'u']]);

        $r = $this->migrator($fake, new FakeRunner())->migrate(null, false);

        self::assertStringContainsString('dbname', $r->failed['kz']);
    }

    public function test_password_is_redacted(): void
    {
        $fake = (new FakeVault())->withBoxes(['kz/'])->withSecret('kz', self::KEY, $this->secret('kz_cash', 'Pa55word!'));
        $runner = (new FakeRunner())->failOn('kz', new \RuntimeException('auth failed for Pa55word!'));

        $r = $this->migrator($fake, $runner)->migrate(null, false);

        self::assertStringNotContainsString('Pa55word!', $r->failed['kz']);
        self::assertStringContainsString('***', $r->failed['kz']);
        self::assertStringNotContainsString('Pa55word!', $this->printed());
    }

    public function test_pretend_is_passed_to_runner(): void
    {
        $runner = new FakeRunner();

        $this->migrator($this->twoBoxes(), $runner)->migrate(null, true);

        self::assertSame([true, true], array_column($runner->calls(), 1));
    }

    public function test_runner_gets_mapped_connection(): void
    {
        $runner = new FakeRunner();

        $this->migrator($this->twoBoxes(), $runner)->migrate('kz', false);

        self::assertSame('pg:5432/kz_cash', $runner->calls()[0][2]->describe());
        self::assertStringContainsString('Подключение pg:5432/kz_cash', $this->printed());
    }
}
