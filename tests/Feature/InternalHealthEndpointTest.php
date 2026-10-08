<?php

namespace Tests\Feature;

use Tests\TestCase;

class InternalHealthEndpointTest extends TestCase
{
    public function test_health_endpoint_requires_the_shared_token_and_returns_safe_checks(): void
    {
        config(['system_health.internal_token' => 'test-health-token']);

        $this->getJson('/internal/health')->assertStatus(403);

        $this->getJson('/internal/health', ['X-Internal-Health-Token' => 'test-health-token'])
            ->assertOk()
            ->assertJsonStructure([
                'app',
                'status',
                'checked_at',
                'checks' => ['app', 'database', 'cache', 'queue', 'storage'],
            ]);
    }
}

