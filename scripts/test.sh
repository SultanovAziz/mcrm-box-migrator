#!/usr/bin/env bash
# Прогон тестов пакета в docker против одноразового PostgreSQL.
#
#   scripts/test.sh [аргументы phpunit]
#   PHP_IMAGE=cash-service-app:latest scripts/test.sh --testsuite laravel
#
# PHP_IMAGE — любой локальный образ с PHP >= 8.1 и pdo_pgsql (по умолчанию образ
# messenger, PHP 8.1 — нижняя граница пакета). Composer берётся с хоста.
#
# Сеть и контейнеры PostgreSQL и Vault — только свои (box-migrator-test*): к сети
# стенда mcrm_infra они не подключаются, и удаляются только эти имена.
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_IMAGE=${PHP_IMAGE:-mcrm-messenger:es-test}
NET=box-migrator-test
PG=box-migrator-test-pg
VAULT=box-migrator-test-vault
VAULT_IMAGE=${VAULT_IMAGE:-hashicorp/vault:1.17}
COMPOSER_BIN=${COMPOSER_BIN:-/usr/bin/composer}
CACHE_DIR=${COMPOSER_CACHE_HOST_DIR:-$HOME/.cache/composer}

cleanup() {
    docker rm -f "$PG" "$VAULT" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup
docker network create "$NET" >/dev/null
docker run -d --rm --name "$PG" --network "$NET" \
    -e POSTGRES_PASSWORD=test postgres:16-alpine >/dev/null
# Vault в dev-режиме: всё в памяти, корневой токен root — только для засева в тестах.
docker run -d --rm --name "$VAULT" --network "$NET" --cap-add IPC_LOCK \
    -e VAULT_DEV_ROOT_TOKEN_ID=root -e VAULT_DEV_LISTEN_ADDRESS=0.0.0.0:8200 \
    "$VAULT_IMAGE" >/dev/null

# Docker на рабочей машине ограничен по ядрам, и под нагрузкой PostgreSQL
# поднимается дольше полуминуты — ждём до 90 с и при неудаче показываем лог.
ready=0
for _ in $(seq 1 90); do
    if docker exec "$PG" pg_isready -U postgres -q 2>/dev/null \
        && docker exec -e VAULT_ADDR=http://127.0.0.1:8200 "$VAULT" vault status >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
if [ "$ready" -ne 1 ]; then
    echo "PostgreSQL ($PG) или Vault ($VAULT) не поднялись за 90 с:" >&2
    docker logs --tail 20 "$PG" >&2 || true
    docker logs --tail 20 "$VAULT" >&2 || true
    exit 1
fi

mkdir -p "$CACHE_DIR"

# COMPOSER_NO_SECURITY_BLOCKING: на PHP 8.1 testbench доступен только с Laravel 10,
# а все его версии composer 2.9+ блокирует по уязвимостям. Это dev-зависимости
# одноразового тестового контейнера пакета; сервисы-потребители решают свои
# зависимости сами, и на них переменная не влияет.
docker run --rm --network "$NET" \
    --user "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -e XDEBUG_MODE=off \
    -e COMPOSER_NO_SECURITY_BLOCKING=1 \
    -e COMPOSER_CACHE_DIR=/composer-cache \
    -v "$CACHE_DIR":/composer-cache \
    -v "$COMPOSER_BIN":/usr/local/bin/composer:ro \
    -v "$PWD":/app -w /app \
    -e TEST_PG_HOST="$PG" -e TEST_PG_PORT=5432 \
    -e TEST_PG_USER=postgres -e TEST_PG_PASSWORD=test \
    -e TEST_VAULT_ADDR="http://$VAULT:8200" -e TEST_VAULT_ROOT_TOKEN=root \
    --entrypoint sh "$PHP_IMAGE" \
    -c 'php /usr/local/bin/composer update -n --prefer-dist -q && php vendor/bin/phpunit "$@"' -- "$@"
