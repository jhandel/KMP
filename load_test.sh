#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$ROOT_DIR/app"
OUTPUT_DIR="${KMP_PERF_OUTPUT_DIR:-$ROOT_DIR/test-results/perf}"

export KMP_BASE_URL="${KMP_BASE_URL:-http://127.0.0.1:8080}"
export KMP_LOGIN_EMAIL="${KMP_LOGIN_EMAIL:-admin@amp.ansteorra.org}"
export KMP_LOGIN_PASSWORD="${KMP_LOGIN_PASSWORD:-TestPassword}"
export KMP_ROUTE_RUNS="${KMP_ROUTE_RUNS:-5}"
export KMP_CONCURRENCY_LEVELS="${KMP_CONCURRENCY_LEVELS:-1,5,10,20}"
export KMP_SLOW_REQUEST_MS="${KMP_SLOW_REQUEST_MS:-1500}"
export KMP_CPU_TARGET_UTIL_PCT="${KMP_CPU_TARGET_UTIL_PCT:-70}"
export KMP_MEMORY_TARGET_UTIL_PCT="${KMP_MEMORY_TARGET_UTIL_PCT:-80}"
export KMP_TELEMETRY_SAMPLE_MS="${KMP_TELEMETRY_SAMPLE_MS:-500}"

export KMP_ENABLE_DB_PROFILE="${KMP_ENABLE_DB_PROFILE:-1}"
export KMP_DB_HOST="${KMP_DB_HOST:-127.0.0.1}"
export KMP_DB_USER="${KMP_DB_USER:-KMPSQLDEV}"
export KMP_DB_PASS="${KMP_DB_PASS:-P@ssw0rd}"
export KMP_DB_NAME="${KMP_DB_NAME:-KMP_DEV}"
export KMP_PERF_SUITE="${KMP_PERF_SUITE:-sizing}"

export KMP_MULTI_TENANTS="${KMP_MULTI_TENANTS:-tenant-a@tenant-a.local@4;tenant-b@tenant-b.local@4}"
export KMP_MT_PATH="${KMP_MT_PATH:-/members/login}"
export KMP_MT_POOL_SIZE="${KMP_MT_POOL_SIZE:-8}"
export KMP_MT_REQUESTS_PER_WORKER="${KMP_MT_REQUESTS_PER_WORKER:-50}"
export KMP_MT_WARMUP_REQUESTS="${KMP_MT_WARMUP_REQUESTS:-2}"
export KMP_MT_TIMEOUT_MS="${KMP_MT_TIMEOUT_MS:-10000}"
export KMP_MT_P95_THRESHOLD_MS="${KMP_MT_P95_THRESHOLD_MS:-800}"
export KMP_MT_FAILURE_THRESHOLD_PCT="${KMP_MT_FAILURE_THRESHOLD_PCT:-1}"
export KMP_MT_MIN_THROUGHPUT_RPS="${KMP_MT_MIN_THROUGHPUT_RPS:-5}"
export KMP_MT_QUEUE_P95_THRESHOLD_MS="${KMP_MT_QUEUE_P95_THRESHOLD_MS:-250}"
export KMP_MT_NOISY_NEIGHBOR_THRESHOLD_PCT="${KMP_MT_NOISY_NEIGHBOR_THRESHOLD_PCT:-40}"
export KMP_PERF_OUTPUT_DIR="$OUTPUT_DIR"

mkdir -p "$OUTPUT_DIR"

echo "Running KMP performance benchmark suite..."
echo "Base URL: $KMP_BASE_URL"
echo "Suite: $KMP_PERF_SUITE"
echo "Output directory: $OUTPUT_DIR"

cd "$APP_DIR"
if [[ "$KMP_PERF_SUITE" == "sizing" || "$KMP_PERF_SUITE" == "all" ]]; then
    node scripts/perf/sizing-benchmark.js
fi

if [[ "$KMP_PERF_SUITE" == "multi-tenant" || "$KMP_PERF_SUITE" == "all" ]]; then
    node scripts/perf/multi-tenant-load-test.js
fi
