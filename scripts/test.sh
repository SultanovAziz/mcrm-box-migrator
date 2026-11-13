#!/usr/bin/env bash
# Прогон тестов пакета в docker против одноразового PostgreSQL.
#
#   scripts/test.sh [аргументы phpunit]
#   PHP_IMAGE=cash-service-app:latest scripts/test.sh --testsuite laravel
#
# PHP_IMAGE — любой локальный образ с PHP >= 8.1 и pdo_pgsql (по умолчанию образ
# messenger, PHP 8.1 — нижняя граница пакета). Composer берётся с хоста.
#
# Сеть и контейнер PostgreSQL — только свои (box-migrator-test*): к сети стенда
# mcrm_infra тестовая база не подключается, и удаляются только эти два имени.
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_IMAGE=${PHP_IMAGE:-mcrm-messenger:es-test}
NET=box-migrator-test
PG=box-migrator-test-pg
COMPOSER_BIN=${COMPOSER_BIN:-/usr/bin/composer}
CACHE_DIR=${COMPOSER_CACHE_HOST_DIR:-$HOME/.cache/composer}

cleanup() {
    docker rm -f "$PG" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup
docker network create "$NET" >/dev/null
docker run -d --rm --name "$PG" --network "$NET" \
    -e POSTGRES_PASSWORD=test postgres:16-alpine >/dev/null

for _ in $(seq 1 30); do
    if docker exec "$PG" pg_isready -U postgres -q 2>/dev/null; then
        break
    fi
    sleep 1
done
docker exec "$PG" pg_isready -U postgres -q

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
    --entrypoint sh "$PHP_IMAGE" \
    -c 'php /usr/local/bin/composer update -n --prefer-dist -q && php vendor/bin/phpunit "$@"' -- "$@"
