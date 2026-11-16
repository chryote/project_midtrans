#!/bin/bash
set -e

TARGET_URL="${1:-http://localhost:8080}"
CONCURRENCY="${2:-20}"
REQUESTS="${3:-500}"

echo "======================================================================"
echo " Laravel Midtrans Scaffolding - Automated Benchmark Runner"
echo "======================================================================"
echo " Target Base URL : $TARGET_URL"
echo " Concurrency     : $CONCURRENCY"
echo " Total Requests  : $REQUESTS"
echo ""

if command -v k6 &> /dev/null; then
    echo "[k6 detected] Running high-concurrency scenario test with k6..."
    k6 run -e TARGET_URL="$TARGET_URL" benchmark/k6-benchmark.js
    exit 0
fi

if command -v php &> /dev/null; then
    echo "[PHP CLI detected] Running concurrent benchmark runner..."
    php benchmark/bench.php --url="$TARGET_URL/api/health" --requests="$REQUESTS" --concurrency="$CONCURRENCY"
    exit 0
fi

if command -v ab &> /dev/null; then
    echo "[ApacheBench detected] Running ApacheBench (ab)..."
    ab -n "$REQUESTS" -c "$CONCURRENCY" "$TARGET_URL/api/health"
    exit 0
fi

if command -v wrk &> /dev/null; then
    echo "[wrk detected] Running wrk load test..."
    wrk -t4 -c"$CONCURRENCY" -d30s "$TARGET_URL/api/health"
    exit 0
fi

echo "[Error] Neither k6, php, ab, nor wrk found in system PATH."
exit 1
