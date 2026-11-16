<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    use ApiResponse;

    /**
     * Check application and external service dependencies health.
     */
    public function check(): JsonResponse
    {
        $dbStatus = 'ok';
        $redisStatus = 'ok';
        $status = 'healthy';

        // Check MySQL database
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
            $status = 'degraded';
        }

        // Check Redis
        try {
            Redis::ping();
        } catch (\Throwable $e) {
            $redisStatus = 'error: ' . $e->getMessage();
            $status = 'degraded';
        }

        $statusCode = ($status === 'healthy') ? 200 : 503;

        return $this->successResponse([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'services' => [
                'database' => $dbStatus,
                'redis' => $redisStatus,
            ],
        ], 'System health check', $statusCode);
    }
}
