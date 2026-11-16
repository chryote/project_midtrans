<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __construct(
        protected MetricsService $metricsService
    ) {}

    /**
     * Expose metrics in Prometheus text format.
     */
    public function index(): Response
    {
        $metrics = $this->metricsService->render();

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
