<?php

namespace Tests\Feature\System;

use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_health_reports_api_and_required_dependencies(): void
    {
        $redisConnection = Mockery::mock();
        $redisConnection->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->andReturn($redisConnection);

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('services.api', 'ok')
            ->assertJsonPath('services.postgresql', 'ok')
            ->assertJsonPath('services.redis', 'ok')
            ->assertJsonPath('services.private_storage', 'ok');
    }
}
