#!/usr/bin/env bash
# Builds, runs, benchmarks, and tears down every runtime x PHP version x JIT
# combination one at a time, each capped at 2 CPU / 2GB RAM, so a modest host
# can still produce a fair comparison. Results are appended to
# docker/benchmark/results/results.csv. A failed build/run for one
# combination (e.g. no PHP 8.5 image yet for FrankenPHP/Swoole) is logged and
# skipped; it does not stop the rest of the matrix.
#
# Usage: bash docker/benchmark/run-matrix.sh [--with-db] [runtime ...] [-- version ...]
#   No runtime/version args: runs the full matrix (5 runtimes x 3 versions x
#   2 JIT states; PHP 8.5 excluded by default, see VERSIONS below).
#   --with-db: also starts a shared, isolated benchmark-only DB container
#   (docker/benchmark/db/init.sql — a penjualan/transaksi-shaped table with
#   synthetic data, NOT the real app database) and additionally benchmarks
#   GET /api/db/transaksi for every combination, appended to
#   docker/benchmark/results/results-db.csv. The DB container itself is NOT
#   resource-capped (it's a shared dependency, not the thing being measured)
#   and is started once, reused across the whole matrix, then torn down at
#   the end.
#   Requires: docker, and this repo's own `php system/console.php api:bench`.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

RUNTIMES=(php-fpm-apache php-fpm-nginx frankenphp frankenphp-worker swoole)
# PHP 8.5 intentionally excluded: docker-php-ext-install fails to compile
# pdo_mysql/pdo_sqlite/opcache against every 8.5 base image tried here
# (php-fpm, FrankenPHP, Swoole) — confirmed in isolation, not host noise.
# Pass "-- 8.5" explicitly if you want to retry it once that's fixed upstream.
VERSIONS=(8.2 8.3 8.4)
JIT_STATES=(off on)
PORT=18099
REQUESTS="${BENCH_REQUESTS:-300}"
CONCURRENCY="${BENCH_CONCURRENCY:-20}"

WITH_DB=""
if [ "${1:-}" = "--with-db" ]; then
    WITH_DB=1
    shift
fi

RESULTS_DIR="$ROOT_DIR/docker/benchmark/results"
RESULTS_CSV="$RESULTS_DIR/results.csv"
RESULTS_DB_CSV="$RESULTS_DIR/results-db.csv"
mkdir -p "$RESULTS_DIR"
if [ ! -f "$RESULTS_CSV" ]; then
    echo "runtime,php_version,jit,requests,concurrency,rps,avg_ms,p95_ms,p99_ms,success,failed,note" > "$RESULTS_CSV"
fi
if [ -n "$WITH_DB" ] && [ ! -f "$RESULTS_DB_CSV" ]; then
    echo "runtime,php_version,jit,endpoint,requests,concurrency,rps,avg_ms,p95_ms,p99_ms,success,failed,note" > "$RESULTS_DB_CSV"
fi

CONTAINER_NAME="codekop_bench_run"
DB_NETWORK="codekop_bench_net"
DB_CONTAINER="codekop_bench_db"

cleanup() {
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
}
cleanup_db() {
    if [ -n "$WITH_DB" ]; then
        docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
        docker network rm "$DB_NETWORK" >/dev/null 2>&1 || true
    fi
}
trap 'cleanup; cleanup_db' EXIT

log() { printf '[bench] %s\n' "$*" >&2; }

start_db() {
    docker network create "$DB_NETWORK" >/dev/null 2>&1 || true
    docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
    docker run -d --rm \
        --name "$DB_CONTAINER" \
        --network "$DB_NETWORK" \
        -e MARIADB_DATABASE=codekop_bench \
        -e MARIADB_USER=codekop_bench \
        -e MARIADB_PASSWORD=codekop_bench \
        -e MARIADB_ROOT_PASSWORD=codekop_bench_root \
        -v "$ROOT_DIR/docker/benchmark/db/init.sql:/docker-entrypoint-initdb.d/init.sql:ro" \
        mariadb:11 >/dev/null

    log "waiting for benchmark DB to seed and become healthy..."
    for _ in $(seq 1 60); do
        if docker exec "$DB_CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
            log "benchmark DB ready"
            return 0
        fi
        sleep 2
    done
    log "benchmark DB did not become healthy in time"
    return 1
}

bench_endpoint() {
    local runtime="$1" version="$2" jit="$3" url="$4" endpoint_label="$5" csv="$6" extra_col="$7"

    local output
    output="$(php system/console.php api:bench \
        --url="$url" \
        --requests="$REQUESTS" --concurrency="$CONCURRENCY" 2>&1)"
    log "$output"

    local rps avg p95 p99 success failed
    rps="$(printf '%s\n' "$output" | grep -oE 'rps=[0-9.]+' | cut -d= -f2)"
    avg="$(printf '%s\n' "$output" | grep -oE 'avg=[0-9.]+ms' | cut -d= -f2 | tr -d ms)"
    p95="$(printf '%s\n' "$output" | grep -oE 'p95=[0-9.]+ms' | cut -d= -f2 | tr -d ms)"
    p99="$(printf '%s\n' "$output" | grep -oE 'p99=[0-9.]+ms' | cut -d= -f2 | tr -d ms)"
    success="$(printf '%s\n' "$output" | grep -oE 'success=[0-9]+' | cut -d= -f2)"
    failed="$(printf '%s\n' "$output" | grep -oE 'failed=[0-9]+' | cut -d= -f2)"

    if [ -n "$extra_col" ]; then
        echo "${runtime},${version},${jit},${endpoint_label},${REQUESTS},${CONCURRENCY},${rps:-0},${avg:-0},${p95:-0},${p99:-0},${success:-0},${failed:-0}," >> "$csv"
    else
        echo "${runtime},${version},${jit},${REQUESTS},${CONCURRENCY},${rps:-0},${avg:-0},${p95:-0},${p99:-0},${success:-0},${failed:-0}," >> "$csv"
    fi
}

run_one() {
    local runtime="$1" version="$2" jit="$3"
    local image="codekop-bench-${runtime}-${version}"
    local dockerfile="docker/benchmark/${runtime}/Dockerfile"

    log "=== ${runtime} php${version} jit=${jit} ==="

    if ! docker build -q \
        --build-arg "PHP_VERSION=${version}" \
        -f "$dockerfile" \
        -t "$image" \
        . >/dev/null; then
        log "build failed, skipping"
        echo "${runtime},${version},${jit},0,0,0,0,0,0,0,0,build_failed" >> "$RESULTS_CSV"
        [ -n "$WITH_DB" ] && echo "${runtime},${version},${jit},/api/db/transaksi,0,0,0,0,0,0,0,0,build_failed" >> "$RESULTS_DB_CSV"
        return
    fi

    cleanup
    local run_args=(-d --rm --name "$CONTAINER_NAME" --cpus=2 --memory=2g -p "${PORT}:80" -e "BENCH_JIT=${jit}")
    if [ -n "$WITH_DB" ]; then
        run_args+=(--network "$DB_NETWORK" -e "DB_HOST=${DB_CONTAINER}" -e DB_NAME=codekop_bench -e DB_USER=codekop_bench -e DB_PASSWORD=codekop_bench)
    fi
    if ! docker run "${run_args[@]}" "$image" >/dev/null; then
        log "run failed, skipping"
        echo "${runtime},${version},${jit},0,0,0,0,0,0,0,0,run_failed" >> "$RESULTS_CSV"
        [ -n "$WITH_DB" ] && echo "${runtime},${version},${jit},/api/db/transaksi,0,0,0,0,0,0,0,0,run_failed" >> "$RESULTS_DB_CSV"
        return
    fi

    local ready=""
    for _ in $(seq 1 30); do
        if curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/api/health" 2>/dev/null | grep -q '^200$'; then
            ready=1
            break
        fi
        sleep 1
    done

    if [ -z "$ready" ]; then
        log "did not become healthy in time, skipping"
        echo "${runtime},${version},${jit},0,0,0,0,0,0,0,0,not_healthy" >> "$RESULTS_CSV"
        [ -n "$WITH_DB" ] && echo "${runtime},${version},${jit},/api/db/transaksi,0,0,0,0,0,0,0,0,not_healthy" >> "$RESULTS_DB_CSV"
        cleanup
        return
    fi

    bench_endpoint "$runtime" "$version" "$jit" "http://127.0.0.1:${PORT}/api/health" "" "$RESULTS_CSV" ""

    if [ -n "$WITH_DB" ]; then
        bench_endpoint "$runtime" "$version" "$jit" "http://127.0.0.1:${PORT}/api/db/transaksi?limit=20" "/api/db/transaksi" "$RESULTS_DB_CSV" "1"
    fi

    cleanup
}

# Optional filtering: `run-matrix.sh [--with-db] php-fpm-nginx frankenphp -- 8.4`
selected_runtimes=("${RUNTIMES[@]}")
selected_versions=("${VERSIONS[@]}")
if [ "$#" -gt 0 ]; then
    selected_runtimes=()
    while [ "$#" -gt 0 ] && [ "$1" != "--" ]; do
        selected_runtimes+=("$1")
        shift
    done
    if [ "${1:-}" = "--" ]; then
        shift
        selected_versions=("$@")
    fi
fi

if [ -n "$WITH_DB" ]; then
    if ! start_db; then
        log "aborting: benchmark DB failed to start"
        exit 1
    fi
fi

for runtime in "${selected_runtimes[@]}"; do
    for version in "${selected_versions[@]}"; do
        for jit in "${JIT_STATES[@]}"; do
            run_one "$runtime" "$version" "$jit"
        done
    done
done

log "done. Results in $RESULTS_CSV"
[ -n "$WITH_DB" ] && log "DB results in $RESULTS_DB_CSV"
