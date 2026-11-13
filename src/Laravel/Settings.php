<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Laravel;

use Illuminate\Contracts\Config\Repository;
use Mcrm\BoxMigrator\Core\VaultConfig;

/**
 * Настройки мигратора с запасным путём через config('connection-storage.*').
 *
 * Сервисы задают ключ сервиса по-разному (у кассы он только умолчанием в её
 * конфиге, без переменной окружения), поэтому читается уже вычисленный конфиг
 * сервиса, а не env().
 */
final class Settings
{
    public static function serviceKey(Repository $config): string
    {
        $key = self::first($config, 'box-migrator.service_key', 'connection-storage.service.key');

        if ($key === null) {
            throw new \InvalidArgumentException(
                'Не задан ключ сервиса: box-migrator.service_key или connection-storage.service.key',
            );
        }

        return $key;
    }

    public static function vault(Repository $config): VaultConfig
    {
        $timeout = self::first($config, 'box-migrator.vault.timeout', 'connection-storage.vault.timeout');

        return new VaultConfig(
            (string) self::first($config, 'box-migrator.vault.url', 'connection-storage.vault.url'),
            (string) self::first($config, 'box-migrator.vault.login', 'connection-storage.vault.login'),
            (string) self::first($config, 'box-migrator.vault.password', 'connection-storage.vault.password'),
            (string) (self::first($config, 'box-migrator.vault.mount', 'connection-storage.vault.mount_path') ?? 'secret'),
            (string) self::first($config, 'box-migrator.vault.boxes_prefix', 'connection-storage.vault.boxes_path'),
            $timeout !== null && (int) $timeout > 0 ? (int) $timeout : 10,
        );
    }

    /** Первое непустое значение из ключей по порядку. */
    private static function first(Repository $config, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $config->get($key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
