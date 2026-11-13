<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Секрет сервиса из Vault → подключение к базе коробки.
 *
 * TLS у сервисов настраивается по-разному (Laravel — sslmode из секрета,
 * messenger — флаг isUseCert и сертификат из окружения), а мигратор обязан
 * подключаться так же, как рантайм сервиса. Поэтому разбор — подменяемая часть.
 */
interface SecretMapper
{
    /**
     * @param array<string, mixed> $secret содержимое data.data секрета
     *
     * @throws InvalidSecretException нет блока db или обязательного поля
     */
    public function toDbConfig(array $secret): DbConfig;
}
