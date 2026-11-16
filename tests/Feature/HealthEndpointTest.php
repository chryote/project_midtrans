<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_check_endpoint_returns_json_status(): void
    {
        $response = $this->getJson(route('api.health'));

        // Health endpoint can be 200 or 503 depending on live external connection in test environment
        $this->assertContains($response->getStatusCode(), [200, 503]);

        $response->assertJsonStructure([
            'status',
            'code',
            'message',
            'data' => [
                'status',
                'timestamp',
                'php_version',
                'laravel_version',
                'services' => [
                    'database',
                    'redis',
                ],
            ],
        ]);
    }
}
