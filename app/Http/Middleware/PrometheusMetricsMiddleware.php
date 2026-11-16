<?php

namespace App\Http\Middleware;

use App\Services\Metrics\MetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMetricsMiddleware
{
    public function __construct(
        protected MetricsService $metricsService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = microtime(true) - $startTime;

        // Skip recording metrics for the metrics endpoint itself to avoid recursion/skew
        if ($request->is('api/metrics') || $request->is('metrics')) {
            return $response;
        }

        $method = $request->method();
        $route = $request->route() ? $request->route()->uri() : $request->path();
        $statusCode = $response->getStatusCode();

        $this->metricsService->recordHttpRequest($method, $route, $statusCode, $duration);

        return $response;
    }
}
