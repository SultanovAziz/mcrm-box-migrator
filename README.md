# mcrm/box-migrator

Миграции PostgreSQL сервиса по **всем коробкам** одной командой. Список коробок и
данные подключения берутся из Vault. Миграции остаются в сервисе, и прогоняет их
родной мигратор фреймворка (Laravel `migrate`, Yii `migrate/up`). Пакет только
обходит коробки.

```bash
php artisan postgres:migrate --from-vault     # Laravel: cash, main-crm, community
php yii postgres/migrate --from-vault         # Yii2: messenger
```

| Аргумент | Что делает |
|---|---|
| `boxname` | одна коробка вместо всех |
| `--from-vault` | все коробки из Vault (без `boxname` обязателен) |
| `--pretend` | Laravel — показать SQL; Yii — показать список неприменённых миграций (`migrate/new`). Миграции не применяются, но служебная таблица учёта миграций создаётся, если её ещё нет |

## Подключение

### Laravel

1. Добавьте репозиторий пакета в `repositories` в `composer.json` сервиса (так же
   подключён `mcrm/laravel-connection-storage`) и выполните
   `composer require mcrm/box-migrator`.
2. Удалите свою команду `postgres:migrate` **в том же коммите**. Иначе в сервисе будут две команды
   с одним именем, и Laravel молча оставит одну из них.
3. Обычно больше ничего не нужно. Ключ сервиса и доступ к Vault пакет берёт из
   `config('connection-storage.*')`, которым сервис уже читает свои секреты:
   `service.key`, `vault.url`, `vault.login`, `vault.password`, `vault.mount_path`,
   `vault.boxes_path`, `vault.timeout`.

Свои значения задаются в опубликованном конфиге
(`php artisan vendor:publish --tag=box-migrator-config`):

| Ключ | По умолчанию | Зачем менять |
|---|---|---|
| `service_key` | `connection-storage.service.key` | секрет сервиса лежит под другим именем |
| `connection` | `pgsql` | миграции сервиса идут через другое соединение (main-crm — `pgsql_box`) |
| `paths` | `[]` — `database/migrations` | нужен явный список путей |
| `default_sslmode` | `prefer` | — |
| `lock_wait_seconds` | `60` | `0` — не ждать: занятая база сразу считается ошибкой коробки |
| `vault.*` | `connection-storage.vault.*` | — |

### Yii2

```php
// config/console.php
'controllerMap' => [
    'postgres' => [
        'class' => \Mcrm\BoxMigrator\Yii\PostgresController::class,
        // 'migrationPaths' => ['@app/migrations-client'],   // по умолчанию
    ],
],
```

Окружение — то же, что у рантайма messenger: `VAULT_ADDR` (по умолчанию
`http://vault:8200`), `VAULT_AUTH_LOGIN` (по умолчанию `mcrm`), `VAULT_AUTH_PASSWORD`,
`YANDEX_CRT_PATH`. Ключ сервиса по умолчанию — `messengers`. Миграции видят имя
коробки в `Yii::$app->params['currentBoxname']`.

## Поведение

- **Список коробок** — каталоги верхнего уровня Vault (`LIST secret/metadata`).
- **Секрет** — `secret/<коробка>/<ключ сервиса>`, блок `db`. Обязательны `host`, `dbname`
  и `username`; `port` по умолчанию 5432.
- **TLS — как в рантайме сервиса.** Laravel: `sslmode` из секрета, иначе `prefer`. Messenger:
  `verify-ca` с `YANDEX_CRT_PATH`, если в секрете `isUseCert = 1`.
- **Нет секрета сервиса — коробка пропускается.** На верхнем уровне Vault лежат не только
  коробки (`card/`, `transactions/`). Если коробка названа явно (`boxname`), отсутствие
  секрета — ошибка.
- **Политика Vault.** Пользователю нужны `list` на `secret/metadata/*` и `read` на
  `secret/data/*`. Если чтение выдано по коробкам, на каталоги-некоробки Vault отвечает
  403, а не 404, и мигратор считает это ошибкой коробки, а не пропуском: отказ в доступе
  к настоящей коробке не должен проходить молча.
- **Коробки идут строго по одной.** Ошибка одной не останавливает остальные.
- **Блокировка на базе коробки.** На время миграций берётся `pg_advisory_lock`, у каждого
  сервиса свой ключ. Второй прогон той же базы ждёт до `lock_wait_seconds`, потом эта
  коробка считается ошибкой.
- **База коробки должна существовать.** Мигратор подключается к ней до запуска фреймворка.
  Отсутствующая база — ошибка коробки. Сам `migrate --force` в Laravel 11+ создал бы такую
  базу; создавать базы коробок — не дело мигратора.
- **Пароли и токены** не попадают ни в вывод, ни в тексты ошибок.

Код выхода — **0**, только если мигрирована хотя бы одна коробка и нет ни одной ошибки.
Во всех остальных случаях — **1**: ошибка хоть одной коробки, Vault недоступен, список
коробок пуст, ни одна коробка не мигрирована (например, из-за опечатки в ключе сервиса).

```
Найдено коробок: 3 — card, krsk, kz
┌ Миграция коробки: card
  └ Пропущено: нет секрета secret/data/card/cash_service
┌ Миграция коробки: krsk
  Подключение pg:5432/krsk_cash
  └ Готово
…
Итог: мигрировано 2, с ошибкой 0, пропущено 1
```

## Для DevOps: шаг в CI

Миграции — **отдельный шаг пайплайна после сборки образа и до выкатки**. Он запускается на
только что собранном образе, из сети, где видны Vault и базы коробок, **с тем же окружением,
что у контейнера сервиса**:

```bash
# Laravel-сервис (cash, main-crm, community)
docker run --rm --network <сеть> --env-file <окружение сервиса> \
  <образ> php artisan postgres:migrate --from-vault

# messenger
docker run --rm --network <сеть> --env-file <окружение сервиса> \
  <образ> php yii postgres/migrate --from-vault
```

Ненулевой код — выкатка останавливается.

Из окружения мигратор читает:

| Сервисы | Переменные |
|---|---|
| cash, main-crm, community | `VAULT_URL`, `VAULT_LOGIN`, `VAULT_PASS`, `VAULT_MOUNT_PATH`, `CONNECTION_STORAGE_SERVICE_KEY` |
| messenger | `VAULT_ADDR`, `VAULT_AUTH_LOGIN`, `VAULT_AUTH_PASSWORD`, `YANDEX_CRT_PATH` |

`CONNECTION_STORAGE_SERVICE_KEY` обязателен там, где умолчание в конфиге сервиса не совпадает с
именем секрета. У community умолчание — `resto-service`, а секрет лежит в `community`: без
переменной все коробки будут пропущены и команда вернёт 1.

- **Не в `Dockerfile`.** При сборке нет доступа к Vault и базам, и пересборка образа не
  должна менять прод.
- **Не в entrypoint контейнера.** При нескольких репликах миграции запускались бы при каждом
  старте каждой из них. Блокировка защищает от гонки, но не от лишней работы и задержки
  старта.
- **Если образ кеширует конфиг** (`php artisan config:cache` при сборке), переменные
  окружения шага CI не подхватятся: `env()` читается в момент кеширования.

## Правило для авторов миграций

Если одна коробка упала, остальные уже на новой схеме, а выкатка кода остановлена и в бою
работает старый код. Поэтому **миграция должна работать и со старым кодом**: сначала
добавляем (колонку, таблицу, индекс), удаляем или переименовываем — только следующим
релизом, когда старого кода уже нет.

## Разработка

```bash
scripts/test.sh                                      # PHP 8.1 (образ messenger)
PHP_IMAGE=cash-service-app:latest scripts/test.sh    # PHP 8.4
scripts/test.sh --testsuite laravel                  # core | laravel | yii
```

Скрипт поднимает одноразовые `postgres:16-alpine` и `hashicorp/vault:1.17` (dev-режим) в
своей сети `box-migrator-test` и удаляет их после прогона.
