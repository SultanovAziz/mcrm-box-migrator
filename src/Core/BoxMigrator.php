<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Обход коробок: список из Vault → секрет сервиса → подключение → лок → миграции.
 *
 * Коробки идут строго по одной, и ошибка одной не останавливает остальные: иначе
 * часть коробок осталась бы на старой схеме без следа в выводе. Метод не
 * бросает — всё, что случилось, попадает в Report, а код выхода решает он.
 */
final class BoxMigrator
{
    private readonly Redactor $redactor;

    public function __construct(
        private readonly VaultClient $vault,
        private readonly string $serviceKey,
        private readonly SecretMapper $mapper,
        private readonly BoxGuard $guard,
        private readonly MigrationRunner $runner,
        private readonly Output $output,
    ) {
        $this->redactor = new Redactor();
    }

    /** @param string|null $onlyBox коробка, названная явно; null — все из Vault */
    public function migrate(?string $onlyBox, bool $pretend): Report
    {
        $report = new Report();
        $boxes = $onlyBox !== null ? [$onlyBox] : $this->listBoxes($report);

        foreach ($boxes as $box) {
            $this->migrateBox($box, $onlyBox !== null, $pretend, $report);
        }

        $this->summarize($report);

        return $report;
    }

    /** @return list<string> */
    private function listBoxes(Report $report): array
    {
        try {
            $boxes = $this->vault->listBoxes();
        } catch (VaultException $e) {
            $report->fatal = 'Список коробок недоступен: '.$e->getMessage();

            return [];
        }

        if ($boxes === []) {
            $report->fatal = 'Vault вернул пустой список коробок';

            return [];
        }

        $this->output->info('Найдено коробок: '.count($boxes).' — '.implode(', ', $boxes));

        return $boxes;
    }

    private function migrateBox(string $box, bool $explicit, bool $pretend, Report $report): void
    {
        $this->output->info("┌ Миграция коробки: {$box}");

        try {
            $secret = $this->vault->readSecret($box, $this->serviceKey);

            if ($secret === null) {
                $reason = 'нет секрета '.$this->vault->secretPath($box, $this->serviceKey);

                // Названная явно коробка без секрета — ошибка: её просили мигрировать.
                if ($explicit) {
                    throw new \RuntimeException($reason);
                }

                $report->skipped[$box] = $reason;
                $this->output->warn("  └ Пропущено: {$reason}");

                return;
            }

            $db = $this->mapper->toDbConfig($secret);
            $this->redactor->add($db->password);
            $this->output->info('  Подключение '.$db->describe());

            $this->guard->hold($db, function () use ($box, $db, $pretend): void {
                $this->runner->run($box, $db, $pretend);
            });

            $report->migrated[] = $box;
            $this->output->info($pretend ? '  └ Готово (pretend)' : '  └ Готово');
        } catch (\Throwable $e) {
            $reason = $this->redactor->clean($e->getMessage() !== '' ? $e->getMessage() : $e::class);
            $report->failed[$box] = $reason;
            $this->output->error("  └ Ошибка: {$reason}");
        }
    }

    private function summarize(Report $report): void
    {
        if ($report->fatal !== null) {
            $this->output->error($report->fatal);

            return;
        }

        $line = sprintf(
            'Итог: мигрировано %d, с ошибкой %d, пропущено %d',
            count($report->migrated),
            count($report->failed),
            count($report->skipped),
        );
        $report->isSuccess() ? $this->output->info($line) : $this->output->error($line);

        foreach ($report->failed as $box => $reason) {
            $this->output->error("  ✗ {$box}: {$reason}");
        }

        foreach ($report->skipped as $box => $reason) {
            $this->output->warn("  – {$box}: {$reason}");
        }

        if ($report->migrated === [] && $report->failed === []) {
            $this->output->error("Ни одна коробка не мигрирована — проверьте ключ сервиса «{$this->serviceKey}»");
        }
    }
}
