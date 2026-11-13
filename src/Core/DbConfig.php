<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

/**
 * Подключение к базе одной коробки, собранное из секрета Vault.
 *
 * Пароль не выходит наружу ни в describe(), ни в дампах (__debugInfo): эти
 * значения попадают в вывод CI, а лог сборки читают больше людей, чем Vault.
 */
final class DbConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
        public readonly ?string $sslmode = null,
        public readonly ?string $sslrootcert = null,
    ) {
    }

    /** DSN для PDO: TLS задаётся параметрами libpq прямо в строке. */
    public function dsn(): string
    {
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";

        if ($this->sslmode !== null) {
            $dsn .= ";sslmode={$this->sslmode}";
        }

        if ($this->sslrootcert !== null) {
            $dsn .= ";sslrootcert={$this->sslrootcert}";
        }

        return $dsn;
    }

    /** Куда подключаемся — для вывода, без учётных данных. */
    public function describe(): string
    {
        return "{$this->host}:{$this->port}/{$this->database}";
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => '***',
            'sslmode' => $this->sslmode,
            'sslrootcert' => $this->sslrootcert,
        ];
    }
}
