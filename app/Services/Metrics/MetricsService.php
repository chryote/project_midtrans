<?php

namespace App\Services\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisStorage;

class MetricsService
{
    protected static ?CollectorRegistry $sharedRegistry = null;
    protected ?CollectorRegistry $registry = null;
    protected string $namespace;

    public function __construct()
    {
        $this->namespace = config('prometheus.namespace', 'laravel_app');
        $this->initRegistry();
    }

    /**
     * Initialize Prometheus collector registry with Redis or InMemory adapter.
     */
    protected function initRegistry(): void
    {
        $adapterType = config('prometheus.storage_adapter', 'redis');

        if ($adapterType === 'redis') {
            try {
                $redisConfig = config('prometheus.redis', []);
                $options = [
                    'host' => $redisConfig['host'] ?? '127.0.0.1',
                    'port' => (int) ($redisConfig['port'] ?? 6379),
                    'password' => $redisConfig['password'] ?? null,
                    'timeout' => $redisConfig['timeout'] ?? 0.5,
                ];
                $adapter = new RedisStorage($options);
                $this->registry = new CollectorRegistry($adapter);
                return;
            } catch (\Throwable $e) {
                // Fallback to shared in-memory registry on connection failure
            }
        }

        if (static::$sharedRegistry === null) {
            static::$sharedRegistry = new CollectorRegistry(new InMemory());
        }

        $this->registry = static::$sharedRegistry;
    }

    /**
     * Get or create HTTP Request Counter.
     */
    public function recordHttpRequest(string $method, string $route, int $statusCode, float $durationSeconds): void
    {
        if (! config('prometheus.enabled', true) || ! $this->registry) {
            return;
        }

        try {
            // Request Count Counter
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                'http_requests_total',
                'Total number of HTTP requests processed',
                ['method', 'route', 'status_code']
            );
            $counter->inc([$method, $route, (string) $statusCode]);

            // Request Duration Histogram
            $buckets = config('prometheus.buckets', [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0]);
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                'http_request_duration_seconds',
                'HTTP request execution latency in seconds',
                ['method', 'route'],
                $buckets
            );
            $histogram->observe($durationSeconds, [$method, $route]);

            // Memory Gauges
            $memGauge = $this->registry->getOrRegisterGauge(
                $this->namespace,
                'app_memory_usage_bytes',
                'Current PHP allocated memory usage in bytes'
            );
            $memGauge->set(memory_get_usage(true));

            $peakMemGauge = $this->registry->getOrRegisterGauge(
                $this->namespace,
                'app_memory_peak_usage_bytes',
                'Peak PHP memory usage in bytes'
            );
            $peakMemGauge->set(memory_get_peak_usage(true));
        } catch (\Throwable $e) {
            // Prevent metric collection error from breaking user requests
        }
    }

    /**
     * Render all recorded metrics in Prometheus exposition text format.
     */
    public function render(): string
    {
        if (! $this->registry) {
            return '';
        }

        $renderer = new RenderTextFormat();
        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    /**
     * Get the registry instance directly.
     */
    public function getRegistry(): ?CollectorRegistry
    {
        return $this->registry;
    }

    /**
     * Reset registry (useful for test isolation).
     */
    public static function reset(): void
    {
        static::$sharedRegistry = null;
    }
}
