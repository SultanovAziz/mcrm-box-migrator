<?php

declare(strict_types=1);

/*
 * mcrm/box-migrator — миграции PostgreSQL по коробкам.
 *
 * null означает «взять у сервиса»: ключ сервиса и доступ к Vault берутся из
 * config('connection-storage.*'), которым сервис уже читает свои секреты. Так
 * DevOps не заводит новых переменных, а мигратор ходит туда же, куда рантайм.
 */
return [
    // Каталог секрета сервиса в коробке: secret/<box>/<service_key>.
    // null → connection-storage.service.key.
    'service_key' => env('BOX_MIGRATOR_SERVICE_KEY'),

    // Соединение, которое мигратор подменяет на базу коробки на время прогона.
    'connection' => 'pgsql',

    // Пути миграций; [] — путь Laravel по умолчанию (database/migrations).
    'paths' => [],

    // sslmode, если в секрете его нет (как в DatabaseConnector сервисов).
    'default_sslmode' => 'prefer',

    // Сколько ждать, пока базу коробки отпустит другой прогон миграций.
    'lock_wait_seconds' => 60,

    // null → connection-storage.vault.{url,login,password,mount_path,boxes_path,timeout}.
    'vault' => [
        'url' => null,
        'login' => null,
        'password' => null,
        'mount' => null,
        'boxes_prefix' => null,
        'timeout' => null,
    ],
];
