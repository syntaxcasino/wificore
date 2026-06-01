<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\RouterController;
use Illuminate\Http\Request;
use Tests\TestCase;

class RouterMassOrchestrationPreviewTest extends TestCase
{
    public function test_it_returns_a_mass_orchestration_preview_plan(): void
    {
        $request = Request::create('/api/routers/orchestration/preview', 'POST', [
            'change_type' => 'apply_service_configs',
            'template' => 'default',
            'batch_size' => 3,
            'routers' => [
                [
                    'id' => 1,
                    'name' => 'Core A',
                    'vendor' => 'mikrotik',
                    'model' => 'RB4011',
                    'os_version' => '7.18.0',
                    'status' => 'online',
                ],
                [
                    'id' => 2,
                    'name' => 'Edge B',
                    'vendor' => 'tp-link',
                    'model' => 'ER605',
                    'os_version' => '7.8.2',
                    'status' => 'rebooting',
                ],
            ],
        ]);

        $controller = app(RouterController::class);
        $response = $controller->previewMassOrchestration($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('apply_service_configs', $payload['plan']['change_type']);
        $this->assertSame(2, $payload['plan']['router_count']);
        $this->assertSame('preview', $payload['plan']['execution_strategy']['mode']);
        $this->assertCount(2, $payload['plan']['router_plans']);
    }
}
