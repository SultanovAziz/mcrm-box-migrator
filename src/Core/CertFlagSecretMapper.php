<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Блок db секрета messenger (его пишет central).
 *
 * sslmode в этом секрете нет: TLS включает флаг isUseCert вместе с путём к
 * сертификату из окружения (YANDEX_CRT_PATH) — ровно как
 * VaultConnectionStorage::normalizeDbConfig в messenger. Без флага или без пути
 * sslmode не задаётся, и libpq берёт своё умолчание.
 */
final class CertFlagSecretMapper implements SecretMapper
{
    public function __construct(private readonly ?string $certPath)
    {
    }

    public function toDbConfig(array $secret): DbConfig
    {
        $db = DbBlockSecretMapper::dbBlock($secret);
        $useCert = $this->certPath !== null && $this->certPath !== '' && (int) ($db['isUseCert'] ?? 0) === 1;

        return new DbConfig(
            (string) $db['host'],
            DbBlockSecretMapper::port($db),
            (string) $db['dbname'],
            (string) $db['username'],
            (string) ($db['password'] ?? ''),
            $useCert ? 'verify-ca' : null,
            $useCert ? $this->certPath : null,
        );
    }
}
