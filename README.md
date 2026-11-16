# Project Midtrans - Automated SaaS Subscription Engine

[![standard-readme compliant](https://img.shields.io/badge/readme%20style-standard-brightgreen.svg?style=flat-square)](https://github.com/RichardLitt/standard-readme)
[![PHP Version](https://img.shields.io/badge/php-8.4-777bb4.svg?style=flat-square&logo=php)](https://www.php.net/)
[![Laravel Framework](https://img.shields.io/badge/laravel-12.x-ff2d20.svg?style=flat-square&logo=laravel)](https://laravel.com/)
[![Docker](https://img.shields.io/badge/docker-ready-2496ed.svg?style=flat-square&logo=docker)](https://www.docker.com/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Production-ready, ACID-compliant automated SaaS subscription billing backend built with Laravel and Midtrans Core API (VT-Direct). Features distributed Redis locking, pessimistic row locks, token-based API authentication, Prometheus + Grafana telemetry, and asynchronous receipt processing.

---

## Table of Contents

- [Background](#background)
  - [Architecture](#architecture)
  - [Concurrency & ACID Compliance](#concurrency--acid-compliance)
- [Install](#install)
  - [Prerequisites](#prerequisites)
  - [Environment Setup](#environment-setup)
- [Usage](#usage)
  - [Docker Quickstart](#docker-quickstart)
  - [End-to-End Sandbox Flow](#end-to-end-sandbox-flow)
  - [Testing](#testing)
  - [Benchmarking](#benchmarking)
- [API](#api)
  - [Authentication](#authentication)
  - [Plans & Subscriptions](#plans--subscriptions)
  - [Midtrans Webhook](#midtrans-webhook)
  - [System & Monitoring](#system--monitoring)
- [Monitoring](#monitoring)
- [Contributing](#contributing)
- [License](#license)

---

## Background

Traditional payment gateways often rely on hosted payment pages or popups (such as Midtrans Snap). While suitable for one-off checkouts, subscription-based Software-as-a-Service (SaaS) products require headless, programmatic control over the checkout lifecycle, state transitions, renewal cycles, and receipt generation.

This project implements an **Automated SaaS Subscription Engine** using **Midtrans Core API (VT-Direct)**. It manages monthly subscriptions for a mock software product, initiates direct charges (Virtual Account or QRIS), processes asynchronous webhook notifications, and automates account activation with receipt delivery.

### Architecture

```
                          ┌────────────────────────┐
                          │   Client / Browser /   │
                          │   Benchmark Runner     │
                          └───────────┬────────────┘
                                      │ HTTP (Port 8080)
                                      ▼
                        ┌───────────────────────────┐
                        │    Nginx Reverse Proxy    │
                        └─────────────┬─────────────┘
                                      │ FastCGI (Port 9000)
                                      ▼
                        ┌───────────────────────────┐
                        │      Laravel (PHP 8.4)    │
                        │   (API Auth + Middleware) │
                        └───────┬───────────┬───────┘
                                │           │
              ┌─────────────────┴─┐       ┌─┴────────────────┐
              ▼                   ▼       ▼                  ▼
     ┌─────────────────┐ ┌─────────────┐ ┌─────────────┐ ┌───────────────┐
     │  MySQL Database │ │ Redis Cache │ │ Prometheus  │ │    Grafana    │
     │   (Port 3306)   │ │ & Queue     │ │ Metrics     │ │  Dashboards   │
     │                 │ │ (Port 6379) │ │ (Port 9090) │ │  (Port 3000)  │
     └─────────────────┘ └──────┬──────┘ └─────────────┘ └───────────────┘
                                │
                                ▼
                 ┌─────────────────────────────┐
                 │  Queue Worker (laravel_queue│
                 │   Asynchronous Receipts     │
                 └─────────────────────────────┘
```

| Service | Container | Base / Image | Internal Port | Host Port | Purpose |
|---|---|---|---|---|---|
| **app** | `laravel_app` | PHP 8.4-FPM Alpine | `9000` | - | Application backend (OPcache + JIT) |
| **nginx** | `laravel_nginx` | `nginx:1.27-alpine` | `80` | `8080` | Reverse proxy, static assets, gzip |
| **mysql** | `laravel_mysql` | `mysql:8.4` | `3306` | `3306` | Relational database (`midtrans_db`) |
| **redis** | `laravel_redis` | `redis:7-alpine` | `6379` | `6379` | Cache, sessions, queue & distributed lock |
| **queue** | `laravel_queue` | PHP 8.4-FPM Alpine | - | - | Background job queue worker |
| **prometheus** | `laravel_prometheus` | `prom/prometheus:v2.53.0` | `9090` | `9090` | Metrics scraper (`/api/metrics`) |
| **grafana** | `laravel_grafana` | `grafana/grafana:11.1.0` | `3000` | `3000` | Visual telemetry dashboards (`admin`/`admin`) |

### Concurrency & ACID Compliance

Webhook delivery in payment systems is inherently subject to duplicate deliveries, network retries, and race conditions. This engine enforces high reliability using a five-stage defense:

1. **Cryptographic SHA-512 Signature Verification**:
   $$\text{Signature} = \text{SHA512}(\text{order\_id} + \text{status\_code} + \text{gross\_amount} + \text{ServerKey})$$
   Rejects spoofed, tampered, or unauthorized callbacks with `403 Forbidden`.
2. **Redis Distributed Atomic Lock**:
   `Cache::lock("midtrans_webhook_lock:{$orderId}", 10)` serializes parallel webhook deliveries for the same order across PHP-FPM workers.
3. **Database Transaction & Pessimistic Row Lock**:
   `Invoice::where('order_id', $orderId)->lockForUpdate()->first()` locks the relational row at the database engine level, preventing race conditions or dirty reads.
4. **Idempotency Guarantee**:
   If an invoice is already marked `paid`, subsequent webhook callbacks return `200 OK` immediately, logging an `ignored` event without double-extending the subscription period or duplicating receipt emails.
5. **Post-Commit Asynchronous Job Dispatch**:
   `SendSubscriptionReceiptJob::dispatch()->afterCommit()` ensures the receipt job is pushed to the Redis queue only after the database transaction successfully commits.

---

## Install

### Prerequisites

- [Docker Engine](https://docs.docker.com/engine/install/) & [Docker Compose](https://docs.docker.com/compose/) (or Docker Desktop on Windows/macOS)
- Git
- *(Optional for local CLI dev)*: PHP 8.4+ and Composer 2.x

### Environment Setup

1. Clone the repository:
   ```sh
   git clone https://github.com/your-org/project_midtrans.git
   cd project_midtrans
   ```

2. Copy the environment configuration:
   ```sh
   cp .env.example .env
   ```

3. Configure your Midtrans Sandbox credentials in `.env`:
   ```dotenv
   MIDTRANS_IS_PRODUCTION=false
   MIDTRANS_SERVER_KEY=SB-Mid-server-your-server-key
   MIDTRANS_CLIENT_KEY=Mid-client-your-client-key
   ```

---

## Usage

### Docker Quickstart

Build and start all services in detached mode:

```sh
docker compose up -d --build
```

The automated entrypoint script will:
- Wait for MySQL and Redis health checks
- Configure writable directory permissions (`storage/`, `bootstrap/cache/`)
- Execute database migrations (`php artisan migrate --force`)
- Start the PHP-FPM application, Nginx proxy, Queue worker, Prometheus, and Grafana

Access points:
- **API Base URL**: [http://localhost:8080](http://localhost:8080)
- **Health Check**: [http://localhost:8080/api/health](http://localhost:8080/api/health)
- **Prometheus Metrics**: [http://localhost:8080/api/metrics](http://localhost:8080/api/metrics)
- **Prometheus Console**: [http://localhost:9090](http://localhost:9090)
- **Grafana Dashboard**: [http://localhost:3000](http://localhost:3000) (Credentials: `admin` / `admin`)

To stop all containers:
```sh
docker compose down
```

### End-to-End Sandbox Flow

#### 1. Register a User
```sh
curl -s -X POST http://localhost:8080/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Developer",
    "email": "jane@example.com",
    "password": "SecretPassword123!",
    "password_confirmation": "SecretPassword123!"
  }'
```
*Note the returned Bearer token.*

#### 2. Subscribe to the "Pro Plan" (BCA Virtual Account)
```sh
curl -s -X POST http://localhost:8080/api/v1/subscriptions/subscribe \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -d '{
    "plan_slug": "pro-plan",
    "payment_type": "bank_transfer",
    "bank": "bca"
  }'
```
*Returns `order_id` and the generated BCA `va_number`.*

#### 3. Simulate Payment in Midtrans Sandbox
1. Open the [Midtrans Payment Simulator](https://simulator.sandbox.midtrans.com/bca/va/index).
2. Input the `va_number` from step 2 and submit payment.
3. Midtrans delivers a webhook to `/api/v1/midtrans/webhook`.
4. The system validates the SHA-512 signature, activates the user's subscription, marks the invoice as `paid`, and queues the receipt email.

#### 4. Verify Active Subscription
```sh
curl -s -X GET http://localhost:8080/api/v1/subscriptions/current \
  -H "Authorization: Bearer <TOKEN>"
```

### Testing

The test suite covers API authentication, model casting, Midtrans signature validation, ACID rollbacks, concurrent webhook ingestion, and queued mail dispatching.

```sh
# Run the test suite locally
php artisan test --testdox

# Or run inside the Docker container
docker compose exec app php artisan test --testdox
```

### Benchmarking

A multi-tool benchmarking suite is provided in [`benchmark/`](benchmark/):

```sh
# 1. Benchmark Health Endpoint (500 requests, 25 concurrency)
php benchmark/bench.php --url=http://localhost:8080/api/health --requests=500 --concurrency=25

# 2. Benchmark Webhook Concurrency (Tests SHA-512 signature validation & Redis lock under load)
php benchmark/webhook-benchmark.php --url=http://localhost:8080/api/v1/midtrans/webhook --requests=100 --concurrency=10

# 3. High-Concurrency Scenario Testing with k6
k6 run benchmark/k6-benchmark.js
```

---

## API

All JSON responses follow a standardized envelope:
```json
{
  "status": "success|error",
  "code": 200,
  "message": "Human readable message",
  "data": {}
}
```

### Authentication

#### Register
- **Endpoint**: `POST /api/v1/auth/register`
- **Body**:
  ```json
  {
    "name": "Alex Developer",
    "email": "alex@example.com",
    "password": "Password123!",
    "password_confirmation": "Password123!"
  }
  ```
- **Response**: `201 Created` with user details and plaintext Bearer token.

#### Login
- **Endpoint**: `POST /api/v1/auth/login`
- **Body**:
  ```json
  {
    "email": "alex@example.com",
    "password": "Password123!"
  }
  ```
- **Response**: `200 OK` with user details and plaintext Bearer token.

#### Profile (Protected)
- **Endpoint**: `GET /api/v1/auth/me`
- **Header**: `Authorization: Bearer <TOKEN>`
- **Response**: `200 OK` with authenticated user profile.

#### Logout (Protected)
- **Endpoint**: `POST /api/v1/auth/logout`
- **Header**: `Authorization: Bearer <TOKEN>`
- **Response**: `200 OK`. Revokes the active personal access token.

### Plans & Subscriptions

#### List Active Plans
- **Endpoint**: `GET /api/v1/plans`
- **Response**: `200 OK` with available subscription plans (`pro-plan`, `enterprise-plan`).

#### Subscribe to Plan (Protected)
- **Endpoint**: `POST /api/v1/subscriptions/subscribe`
- **Header**: `Authorization: Bearer <TOKEN>`
- **Body**:
  ```json
  {
    "plan_slug": "pro-plan",
    "payment_type": "bank_transfer",
    "bank": "bca"
  }
  ```
  *(Supported payment types: `bank_transfer` [banks: `bca`, `bni`, `bri`, `permata`, `mandiri`], `qris`)*
- **Response**: `201 Created` with `subscription_id`, `order_id`, and `payment_instructions`.

#### Current Subscription (Protected)
- **Endpoint**: `GET /api/v1/subscriptions/current`
- **Header**: `Authorization: Bearer <TOKEN>`
- **Response**: `200 OK` with `is_premium` boolean, active subscription dates, and recent invoices.

### Midtrans Webhook

#### Payment Notification Callback
- **Endpoint**: `POST /api/v1/midtrans/webhook`
- **Header**: `Content-Type: application/json`
- **Body**: Payload sent by Midtrans containing `order_id`, `status_code`, `gross_amount`, `signature_key`, `transaction_status`, and `fraud_status`.
- **Response**: `200 OK` upon verification and processing.

### System & Monitoring

#### Health Check
- **Endpoint**: `GET /api/health`
- **Response**: `200 OK` (or `503`) reporting status of MySQL database and Redis connections.

#### Prometheus Metrics
- **Endpoint**: `GET /api/metrics` (or `GET /metrics`)
- **Response**: `200 OK` with `text/plain` Prometheus exposition format (`http_requests_total`, `http_request_duration_seconds`, `app_memory_usage_bytes`).

---

## Monitoring

The stack includes pre-configured telemetry out of the box:

- **Prometheus** scrapes application metrics every 5 seconds.
- **Grafana** automatically provisions a Prometheus datasource and mounts the dashboard from [`docker/grafana/dashboards/laravel-dashboard.json`](docker/grafana/dashboards/laravel-dashboard.json):
  - Request Rate (RPS by route and HTTP status)
  - Latency Histograms ($p50$, $p95$, $p99$ percentiles)
  - HTTP Status Code Breakdown ($2xx$, $4xx$, $5xx$)
  - PHP Memory Allocation (Current vs Peak)

---

## Contributing

Small note: If editing the Readme, please conform to the [standard-readme](https://github.com/RichardLitt/standard-readme) specification.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Ensure all tests pass (`php artisan test`)
5. Push to the Branch (`git push origin feature/AmazingFeature`)
6. Open a Pull Request

---

## License

[MIT](LICENSE) © Project Midtrans Contributors
