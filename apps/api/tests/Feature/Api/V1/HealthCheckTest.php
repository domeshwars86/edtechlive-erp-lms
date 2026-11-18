<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_readiness_endpoint_checks_database_connectivity(): void
    {
        $this->getJson('/api/v1/health/ready')->assertOk()
            ->assertJsonPath('status', 'ok')->assertJsonPath('checks.database', 'ok');
    }

    public function test_readiness_endpoint_returns_safe_unavailable_response_when_database_fails(): void
    {
        $default = config('database.default');
        config(['database.default' => 'health_test', 'database.connections.health_test' => ['driver' => 'unsupported']]);
        try {
            $this->getJson('/api/v1/health/ready')->assertServiceUnavailable()
                ->assertExactJson([
                    'status' => 'unavailable', 'service' => 'edtechlive-api',
                    'checks' => ['database' => 'unavailable'],
                ]);
        } finally {
            DB::purge('health_test');
            config(['database.default' => $default]);
        }
    }
}
