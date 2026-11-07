#!/usr/bin/env bash
# Roda a suíte duas vezes: SQLite (rápido) e Postgres descartável (o banco de
# produção — o índice único e o tipo das colunas valem lá). Precisa só de Docker.
set -euo pipefail
cd "$(dirname "$0")/.."
U="$(id -u):$(id -g)"

# A imagem composer:2 não tem gd; a sail-8.4 tem gd, exif, webp e o composer.
docker run --rm -u "$U" -e COMPOSER_HOME=/tmp/c -v "$PWD":/app -w /app --entrypoint sh "${IMAGEM_PHP:-sail-8.4/app:latest}" \
  -c '[ -d vendor ] || composer install -q --no-interaction; vendor/bin/phpunit'

docker network create midia-teste >/dev/null
trap 'docker rm -f midia-pg >/dev/null 2>&1; docker network rm midia-teste >/dev/null 2>&1' EXIT
docker run -d --rm --name midia-pg --network midia-teste \
  -e POSTGRES_PASSWORD=teste -e POSTGRES_DB=midia postgres:16-alpine >/dev/null
until docker exec midia-pg pg_isready -U postgres -q; do sleep 1; done

# Imagem com pdo_pgsql e PHP 8.4 (o de produção).
docker run --rm --network midia-teste -u "$U" -e DB_HOST=midia-pg -e DB_PASSWORD=teste \
  -v "$PWD":/app -w /app --entrypoint php "${IMAGEM_PHP:-sail-8.4/app:latest}" \
  vendor/bin/phpunit -c tests/phpunit.pgsql.xml
