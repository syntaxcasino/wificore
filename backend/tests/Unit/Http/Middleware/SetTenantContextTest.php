<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SetTenantContext;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Mockery;
use Tests\TestCase;

class SetTenantContextTest extends TestCase
{
    public function test_authenticated_non_system_user_without_tenant_is_denied(): void
    {
        $tenantContext = Mockery::mock(TenantContext::class);
        $middleware = new SetTenantContext($tenantContext);

        $request = Request::create('/api/tenant/current', 'GET');
        $request->setUserResolver(function () {
            return (object) [
                'id' => 'test-user-id',
                'role' => 'admin',
                'tenant_id' => null,
            ];
        });

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Tenant context is required for this account',
        ], $response->getData(true));
    }
}
