<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/** Итог обхода коробок; по нему команда выбирает код выхода для CI. */
final class Report
{
    /** @var list<string> */
    public array $migrated = [];

    /** @var array<string, string> коробка => причина */
    public array $failed = [];

    /** @var array<string, string> коробка => причина */
    public array $skipped = [];

    /** Сбой до обхода: список коробок недоступен или пуст. */
    public ?string $fatal = null;

    /**
     * Успех — ни одной ошибки и хотя бы одна мигрированная коробка: опечатка в
     * ключе сервиса превращает все коробки в «пропущено», и зелёный CI с нулём
     * миграций был бы хуже красного.
     */
    public function isSuccess(): bool
    {
        return $this->fatal === null && $this->failed === [] && $this->migrated !== [];
    }

    public function exitCode(): int
    {
        return $this->isSuccess() ? 0 : 1;
    }
}
