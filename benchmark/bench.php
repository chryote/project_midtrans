<?php
/**
 * CLI Concurrent HTTP Benchmark Tool
 *
 * Zero-dependency PHP benchmark runner utilizing curl_multi for asynchronous concurrency.
 *
 * Usage:
 *   php benchmark/bench.php --url=http://localhost:8080/api/health --requests=500 --concurrency=25
 *   php benchmark/bench.php --scenario=auth --requests=200 --concurrency=10
 */

declare(strict_types=1);

$options = getopt('', [
    'url:',
    'requests:',
    'concurrency:',
    'method:',
    'body:',
    'header:',
    'token:',
    'scenario:',
    'help',
]);

if (isset($options['help'])) {
    echo "========================================================\n";
    echo " Laravel Midtrans Service - CLI Benchmark Runner\n";
    echo "========================================================\n";
    echo "Options:\n";
    echo "  --url=<url>            Target URL (default: http://localhost:8080/api/health)\n";
    echo "  --requests=<num>       Total number of requests (default: 500)\n";
    echo "  --concurrency=<num>    Concurrent connections (default: 20)\n";
    echo "  --method=<GET|POST>    HTTP Method (default: GET)\n";
    echo "  --body=<json_string>   HTTP POST Request body (JSON)\n";
    echo "  --token=<bearer_token> Authorization Bearer Token\n";
    echo "  --scenario=<health|login|profile> Pre-configured benchmark scenarios\n";
    echo "  --help                 Show this help message\n";
    exit(0);
}

$url = $options['url'] ?? 'http://localhost:8080/api/health';
$totalRequests = (int) ($options['requests'] ?? 500);
$concurrency = (int) ($options['concurrency'] ?? 20);
$method = strtoupper($options['method'] ?? 'GET');
$body = $options['body'] ?? null;
$token = $options['token'] ?? null;
$scenario = $options['scenario'] ?? null;

// Handle Pre-configured Scenarios
if ($scenario === 'login') {
    $url = 'http://localhost:8080/api/v1/auth/login';
    $method = 'POST';
    $body = json_encode(['email' => 'benchmark@example.com', 'password' => 'secretPassword123!']);
} elseif ($scenario === 'health') {
    $url = 'http://localhost:8080/api/health';
    $method = 'GET';
}

echo "======================================================================\n";
echo " Starting Benchmark Run\n";
echo "======================================================================\n";
echo " Target URL   : {$url}\n";
echo " Method       : {$method}\n";
echo " Requests     : {$totalRequests}\n";
echo " Concurrency  : {$concurrency}\n";
if ($token) echo " Auth Header  : Bearer {$token}\n";
echo "======================================================================\n\n";

$latencies = [];
$statusCodes = [];
$failedRequests = 0;
$successfulRequests = 0;
$totalBytesTransferred = 0;

$mh = curl_multi_init();
$activeHandles = [];
$requestsDispatched = 0;

$benchStartTime = microtime(true);

function createCurlHandle(string $url, string $method, ?string $body, ?string $token): \CurlHandle {
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'User-Agent: LaravelBenchmark/1.0',
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $headers[] = 'Content-Type: application/json';
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    return $ch;
}

// Prime the queue
while ($requestsDispatched < $totalRequests && count($activeHandles) < $concurrency) {
    $ch = createCurlHandle($url, $method, $body, $token);
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
        $downloadSize = curl_getinfo($completedCh, CURLINFO_SIZE_DOWNLOAD);
        $totalBytesTransferred += (int) $downloadSize;

        $latencies[] = $durationMs;
        $statusCodes[$httpCode] = ($statusCodes[$httpCode] ?? 0) + 1;

        if ($httpCode >= 200 && $httpCode < 400) {
            $successfulRequests++;
        } else {
            $failedRequests++;
        }

        curl_multi_remove_handle($mh, $completedCh);
        curl_close($completedCh);
        unset($activeHandles[$handleId]);

        // Dispatch next request if pending
        if ($requestsDispatched < $totalRequests) {
            $newCh = createCurlHandle($url, $method, $body, $token);
            curl_multi_add_handle($mh, $newCh);
            $newHandleId = (int) $newCh;
            $activeHandles[$newHandleId] = [
                'handle' => $newCh,
                'start_time' => microtime(true),
            ];
            $requestsDispatched++;
        }

        // Print progress
        $completedCount = count($latencies);
        if ($completedCount % max(1, (int)($totalRequests / 20)) === 0 || $completedCount === $totalRequests) {
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
echo " Benchmark Results Summary\n";
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
$kbPerSec = $benchDurationSec > 0 ? (($totalBytesTransferred / 1024) / $benchDurationSec) : 0;

printf(" Total Time Elapsed   : %8.3f seconds\n", $benchDurationSec);
printf(" Completed Requests   : %8d\n", $count);
printf(" Successful (2xx-3xx) : %8d (%5.1f%%)\n", $successfulRequests, ($count > 0 ? ($successfulRequests / $count) * 100 : 0));
printf(" Failed / Errors      : %8d (%5.1f%%)\n", $failedRequests, ($count > 0 ? ($failedRequests / $count) * 100 : 0));
printf(" Requests Per Second  : %8.2f req/sec\n", $rps);
printf(" Transfer Throughput  : %8.2f KB/sec\n", $kbPerSec);

echo "\nLatency Percentiles (ms):\n";
printf("  Min                 : %8.2f ms\n", $min);
printf("  Mean                : %8.2f ms\n", $avg);
printf("  50th Percentile (p50): %8.2f ms (Median)\n", $p50);
printf("  90th Percentile (p90): %8.2f ms\n", $p90);
printf("  95th Percentile (p95): %8.2f ms\n", $p95);
printf("  99th Percentile (p99): %8.2f ms\n", $p99);
printf("  Max                 : %8.2f ms\n", $max);

echo "\nHTTP Status Breakdown:\n";
ksort($statusCodes);
foreach ($statusCodes as $code => $freq) {
    printf("  HTTP %-3s          : %8d requests\n", $code === 0 ? 'ERR' : (string)$code, $freq);
}
echo "======================================================================\n";
