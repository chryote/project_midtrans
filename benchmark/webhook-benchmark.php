<?php
/**
 * Midtrans Webhook Concurrency & Throughput Benchmark
 *
 * Simulates high-concurrency webhook ingestion with valid SHA-512 signatures,
 * testing distributed locks, database pessimistic locks, and throughput.
 *
 * Usage:
 *   php benchmark/webhook-benchmark.php --url=http://localhost:8080/api/v1/midtrans/webhook --requests=100 --concurrency=10
 */

declare(strict_types=1);

$options = getopt('', [
    'url:',
    'requests:',
    'concurrency:',
    'server-key:',
    'order-id:',
    'help',
]);

if (isset($options['help'])) {
    echo "======================================================================\n";
    echo " Midtrans Webhook Ingestion Benchmark Runner\n";
    echo "======================================================================\n";
    echo "Options:\n";
    echo "  --url=<url>            Target Webhook URL (default: http://localhost:8080/api/v1/midtrans/webhook)\n";
    echo "  --requests=<num>       Total number of webhook requests (default: 100)\n";
    echo "  --concurrency=<num>    Concurrent webhook deliveries (default: 10)\n";
    echo "  --server-key=<key>     Midtrans Server Key \n";
    echo "  --order-id=<id>        Order ID to simulate (default: auto-generated)\n";
    echo "  --help                 Show this help message\n";
    exit(0);
}

$url = $options['url'] ?? 'http://localhost:8080/api/v1/midtrans/webhook';
$totalRequests = (int) ($options['requests'] ?? 100);
$concurrency = (int) ($options['concurrency'] ?? 10);
$serverKey = $options['server-key'] ?? 'some-server-key';
$baseOrderId = $options['order-id'] ?? ('BENCH-' . time());

echo "======================================================================\n";
echo " Midtrans Webhook Concurrency Benchmark\n";
echo "======================================================================\n";
echo " Target URL   : {$url}\n";
echo " Requests     : {$totalRequests}\n";
echo " Concurrency  : {$concurrency}\n";
echo " Base Order ID: {$baseOrderId}\n";
echo "======================================================================\n\n";

$latencies = [];
$statusCodes = [];
$failedRequests = 0;
$successfulRequests = 0;

$mh = curl_multi_init();
$activeHandles = [];
$requestsDispatched = 0;

function generateWebhookPayload(string $orderId, string $serverKey): string {
    $statusCode = '200';
    $grossAmount = '150000.00';
    $signature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

    return json_encode([
        'order_id' => $orderId,
        'status_code' => $statusCode,
        'gross_amount' => $grossAmount,
        'transaction_status' => 'settlement',
        'transaction_id' => 'midtrans-bench-' . bin2hex(random_bytes(6)),
        'payment_type' => 'bank_transfer',
        'signature_key' => $signature,
    ]);
}

function createWebhookCurlHandle(string $url, string $payload): \CurlHandle {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: MidtransSimulator/1.0',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    return $ch;
}

$benchStartTime = microtime(true);

// Prime connection queue
while ($requestsDispatched < $totalRequests && count($activeHandles) < $concurrency) {
    // Generate order ID (re-use same ID to test race condition / duplicate idempotency)
    $orderId = $baseOrderId . '-' . ($requestsDispatched % 5);
    $payload = generateWebhookPayload($orderId, $serverKey);

    $ch = createWebhookCurlHandle($url, $payload);
    curl_multi_add_handle($mh, $ch);
    $handleId = (int) $ch;
    $activeHandles[$handleId] = [
        'handle' => $ch,
        'start_time' => microtime(true),
    ];
    $requestsDispatched++;
}

$stillRunning = null;
do {
    curl_multi_exec($mh, $stillRunning);

    while ($info = curl_multi_info_read($mh)) {
        $completedCh = $info['handle'];
        $handleId = (int) $completedCh;
        $startTime = $activeHandles[$handleId]['start_time'];
        $durationMs = (microtime(true) - $startTime) * 1000.0;

        $httpCode = curl_getinfo($completedCh, CURLINFO_HTTP_CODE);

        $latencies[] = $durationMs;
        $statusCodes[$httpCode] = ($statusCodes[$httpCode] ?? 0) + 1;

        if ($httpCode >= 200 && $httpCode < 300) {
            $successfulRequests++;
        } else {
            $failedRequests++;
        }

        curl_multi_remove_handle($mh, $completedCh);
        curl_close($completedCh);
        unset($activeHandles[$handleId]);

        // Dispatch next request
        if ($requestsDispatched < $totalRequests) {
            $orderId = $baseOrderId . '-' . ($requestsDispatched % 5);
            $payload = generateWebhookPayload($orderId, $serverKey);

            $newCh = createWebhookCurlHandle($url, $payload);
            curl_multi_add_handle($mh, $newCh);
            $newHandleId = (int) $newCh;
            $activeHandles[$newHandleId] = [
                'handle' => $newCh,
                'start_time' => microtime(true),
            ];
            $requestsDispatched++;
        }

        $completedCount = count($latencies);
        if ($completedCount % max(1, (int)($totalRequests / 10)) === 0 || $completedCount === $totalRequests) {
            $pct = round(($completedCount / $totalRequests) * 100);
            printf("\r Progress: [%-20s] %3d%% (%d / %d completed)", str_repeat('#', (int)($pct / 5)), $pct, $completedCount, $totalRequests);
        }
    }

    if ($stillRunning > 0) {
        curl_multi_select($mh, 0.05);
    }
} while ($stillRunning > 0 || !empty($activeHandles));

curl_multi_close($mh);
$benchDurationSec = microtime(true) - $benchStartTime;

echo "\n\n";
echo "======================================================================\n";
echo " Webhook Benchmark Results Summary\n";
echo "======================================================================\n";

sort($latencies);
$count = count($latencies);

$min = $count > 0 ? min($latencies) : 0;
$max = $count > 0 ? max($latencies) : 0;
$avg = $count > 0 ? array_sum($latencies) / $count : 0;

$p50 = $latencies[(int) floor($count * 0.50)] ?? 0;
$p90 = $latencies[(int) floor($count * 0.90)] ?? 0;
$p95 = $latencies[(int) floor($count * 0.95)] ?? 0;
$p99 = $latencies[(int) floor($count * 0.99)] ?? 0;

$rps = $benchDurationSec > 0 ? ($count / $benchDurationSec) : 0;

printf(" Total Execution Time : %8.3f seconds\n", $benchDurationSec);
printf(" Total Webhooks Sent  : %8d\n", $count);
printf(" Success (2xx)        : %8d (%5.1f%%)\n", $successfulRequests, ($count > 0 ? ($successfulRequests / $count) * 100 : 0));
printf(" Failures / Errors    : %8d (%5.1f%%)\n", $failedRequests, ($count > 0 ? ($failedRequests / $count) * 100 : 0));
printf(" Webhook Throughput   : %8.2f webhooks/sec\n", $rps);

echo "\nLatency Percentiles (ms):\n";
printf("  Min                 : %8.2f ms\n", $min);
printf("  Mean                : %8.2f ms\n", $avg);
printf("  50th Percentile (p50): %8.2f ms (Median)\n", $p50);
printf("  90th Percentile (p90): %8.2f ms\n", $p90);
printf("  95th Percentile (p95): %8.2f ms\n", $p95);
printf("  99th Percentile (p99): %8.2f ms\n", $p99);
printf("  Max                 : %8.2f ms\n", $max);

echo "\nHTTP Status Code Breakdown:\n";
ksort($statusCodes);
foreach ($statusCodes as $code => $freq) {
    printf("  HTTP %-3s          : %8d responses\n", $code === 0 ? 'ERR' : (string)$code, $freq);
}
echo "======================================================================\n";
