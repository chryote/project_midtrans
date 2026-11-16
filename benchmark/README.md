# Benchmark & Load Testing Suite

This directory contains load testing and benchmarking tools to evaluate the performance, throughput (RPS), and latency percentiles (p50, p95, p99) of the Laravel application running inside Docker or locally.

---

## Benchmark Tools Available

| Tool | File | Requirement | Best For |
|---|---|---|---|
| **PHP Multi-cURL Runner** | `bench.php` | PHP CLI (`php -v`) | Zero-dependency general API testing |
| **Webhook Concurrency Runner** | `webhook-benchmark.php` | PHP CLI (`php -v`) | Midtrans Webhook race condition & SHA-512 throughput test |
| **k6 Scenario Runner** | `k6-benchmark.js` | [k6](https://k6.io/) | Full auth lifecycle, ramp-up stages & SLA assertions |
| **Automated Batch Script** | `run-benchmark.bat` / `.sh` | Windows / Unix | One-command automated benchmark runner |

---

## 1. Using the Built-In PHP CLI Benchmark Runner

No external software needed! Uses PHP's native `curl_multi` for asynchronous requests.

### Quick Commands

```bash
# Benchmark Health endpoint (500 requests, 25 concurrent)
php benchmark/bench.php --url=http://localhost:8080/api/health --requests=500 --concurrency=25

# Benchmark Metrics exposition endpoint
php benchmark/bench.php --url=http://localhost:8080/api/metrics --requests=300 --concurrency=20

# Benchmark Auth Login endpoint
php benchmark/bench.php --scenario=login --requests=200 --concurrency=10

# Benchmark Midtrans Webhook Ingestion & Concurrency
# (Fires 200 concurrent webhooks with valid SHA-512 signatures across shared order IDs)
php benchmark/webhook-benchmark.php --url=http://localhost:8080/api/v1/midtrans/webhook --requests=200 --concurrency=20

# Benchmark with Custom POST Payload and Bearer Token
php benchmark/bench.php \
  --url=http://localhost:8080/api/v1/auth/me \
  --token="1|YOUR_GENERATED_SANCTUM_TOKEN" \
  --requests=1000 \
  --concurrency=50
```

### Metrics Output Sample

```
======================================================================
 Benchmark Results Summary
======================================================================
 Total Time Elapsed   :    1.240 seconds
 Completed Requests   :      500
 Successful (2xx-3xx) :      500 (100.0%)
 Failed / Errors      :        0 (  0.0%)
 Requests Per Second  :   403.23 req/sec
 Transfer Throughput  :   152.12 KB/sec

Latency Percentiles (ms):
  Min                 :     2.15 ms
  Mean                :     4.88 ms
  50th Percentile (p50):     4.21 ms (Median)
  90th Percentile (p90):     7.42 ms
  95th Percentile (p95):     9.18 ms
  99th Percentile (p99):    14.65 ms
  Max                 :    22.30 ms

HTTP Status Breakdown:
  HTTP 200            :      500 requests
======================================================================
```

---

## 2. Using k6 Load Testing

Runs full lifecycle multi-stage testing:
1. Health check verification
2. New user registration
3. Profile retrieval using Bearer token
4. User logout & token revocation
5. Prometheus metrics validation

```bash
# Install k6 (if not already installed)
# Windows: winget install k6 --source winget
# macOS: brew install k6
# Linux: sudo apt-get install k6

# Run benchmark
k6 run benchmark/k6-benchmark.js

# Target custom URL or Docker host
k6 run -e TARGET_URL=http://localhost:8080 benchmark/k6-benchmark.js
```

---

## 3. Real-Time Monitoring during Benchmark

While the benchmark is executing, open Grafana or Prometheus to observe metrics in real time:

- **Grafana Dashboard**: `http://localhost:3000` (User: `admin`, Pass: `admin`)
  - Auto-provisioned dashboard: **"Laravel Application & API Metrics"**
  - Shows live RPS graphs, p50/p95/p99 latency histograms, HTTP status codes, and PHP memory usage.
- **Prometheus UI**: `http://localhost:9090`
- **Application Metrics Endpoint**: `http://localhost:8080/api/metrics`
