#!/usr/bin/env bash
set -euo pipefail

HOST="${1:-pgdog}"
DBNAME="${2:-app}"
MODE="${3:-mixed}"
LOOPS="${4:-25}"
WORKERS="${5:-2}"

run_worker() {
  local worker="$1"
  docker compose run --rm php \
    env REPRO_HOST="$HOST" \
        REPRO_DBNAME="$DBNAME" \
        REPRO_MODE="$MODE" \
        REPRO_LOOPS="$LOOPS" \
        REPRO_WORKER="$worker" \
        php /app/pdo_repro.php
}

declare -a pids=()

for worker in $(seq 1 "$WORKERS"); do
  run_worker "worker${worker}" &
  pids+=("$!")
done

for pid in "${pids[@]}"; do
  wait "$pid"
done
