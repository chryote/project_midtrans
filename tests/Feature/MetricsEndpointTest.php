<?php

namespace Tests\Feature;

use Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    public function test_metrics_endpoint_returns_prometheus_exposition_format(): void
    {
        // First trigger an endpoint so metrics are populated
        $this->getJson(route('api.health'));

        // Query the metrics endpoint
        $response = $this->get('/metrics');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        $this->assertStringContainsString('# HELP', $content);
        $this->assertStringContainsString('# TYPE', $content);
        $this->assertStringContainsString('http_requests_total', $content);
    }

    public function test_api_metrics_alias_returns_valid_metrics(): void
    {
        $response = $this->get(route('api.metrics'));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }
}
