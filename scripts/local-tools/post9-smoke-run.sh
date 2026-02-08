#!/bin/bash
set -euo pipefail

COMPOSE_BIN="${COMPOSE_BIN:-docker compose}"
BUILD="${BUILD:-1}"

if [ "${BUILD}" = "1" ]; then
  ${COMPOSE_BIN} up -d --build
else
  ${COMPOSE_BIN} up -d
fi

${COMPOSE_BIN} exec -T php php artisan migrate --force

${COMPOSE_BIN} exec -T php php artisan tinker --execute="DB::select('select 1');"
${COMPOSE_BIN} exec -T redis redis-cli ping
${COMPOSE_BIN} exec -T rabbitmq rabbitmq-diagnostics -q ping
