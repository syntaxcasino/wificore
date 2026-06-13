<?php

namespace App\Http\Controllers\Api;

use App\Events\RouterProvisioningProgress;
use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterService;
use App\Models\Tenant;
use App\Services\CacheInvalidationService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InternalProvisioningServiceController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function updateStatus(Request $request, string $serviceId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:running,completed,failed',
            'progress' => 'nullable|integer|min:0|max:100',
            'message' => 'nullable|string|max:1000',
            'result' => 'nullable|array',
            'error' => 'nullable|string|max:2000',
            'terminal' => 'nullable|boolean',
            'stage' => 'nullable|string|max:255',
        ]);

        $tenantId = (string) ($request->input('result.tenant_id') ?? '');
        if ($tenantId === '') {
            return response()->json([
                'success' => false,
                'error' => 'Missing tenant_id in callback payload',
            ], 422);
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->schema_created || ! $tenant->schema_name) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant schema unavailable for service deployment callback',
            ], 404);
        }

        $progress = (int) ($validated['progress'] ?? 0);
        $message = $validated['message'] ?? null;
        $result = $validated['result'] ?? [];
        $status = $validated['status'];
        $terminal = (bool) ($validated['terminal'] ?? true);
        $stage = $validated['stage'] ?? 'submitted';

        DB::transaction(function () use ($tenant, $serviceId, $status, $progress, $message, $result, $terminal, $stage) {
            $this->tenantContext->runInTenantContext($tenant, function () use ($serviceId, $status, $progress, $message, $result, $terminal, $stage) {
                $service = RouterService::with('router')->find($serviceId);
                if (! $service) {
                    throw new \RuntimeException('Router service not found for provisioning callback');
                }

                $router = $service->router ?: Router::find($service->router_id);
                $siblingIds = RouterService::where('router_id', $service->router_id)
                    ->where('service_type', $service->service_type)
                    ->pluck('id')
                    ->all();

                if ($status === 'failed') {
                    RouterService::whereIn('id', $siblingIds)->update([
                        'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                        'status' => RouterService::STATUS_INACTIVE,
                    ]);

                    if ($router) {
                        $router->update([
                            'status' => 'failed',
                            'provisioning_stage' => 'failed',
                            'last_checked' => now(),
                        ]);
                    }
                } elseif ($status === 'completed' && $terminal) {
                    RouterService::whereIn('id', $siblingIds)->update([
                        'deployment_status' => RouterService::DEPLOYMENT_DEPLOYED,
                        'status' => RouterService::STATUS_ACTIVE,
                        'deployed_at' => now(),
                    ]);

                    if ($router) {
                        $router->update([
                            'status' => 'online',
                            'provisioning_stage' => 'completed',
                            'model' => $result['model'] ?? $router->model,
                            'os_version' => $result['os_version'] ?? $router->os_version,
                            'last_seen' => $result['last_seen'] ?? now(),
                            'last_checked' => now(),
                        ]);

                        CacheInvalidationService::invalidateRouterCache((string) $tenant->id, (string) $router->id);
                    }
                } else {
                    RouterService::whereIn('id', $siblingIds)->update([
                        'deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS,
                    ]);

                    if ($router) {
                        [$routerStatus, $routerStage] = $this->mapStageToRouterState($stage);
                        $router->update([
                            'status' => $routerStatus,
                            'provisioning_stage' => $routerStage,
                            'last_checked' => now(),
                        ]);
                    }
                }

                if ($router) {
                    broadcast(new RouterProvisioningProgress(
                        (string) $router->id,
                        'service_deploy_' . $stage,
                        (float) $progress,
                        $message ?? 'Service deployment update received',
                        array_merge($result, [
                            'service_id' => $service->id,
                            'service_type' => $service->service_type,
                            'deployment_status' => $status === 'failed'
                                ? RouterService::DEPLOYMENT_FAILED
                                : ($status === 'completed' && $terminal ? RouterService::DEPLOYMENT_DEPLOYED : RouterService::DEPLOYMENT_IN_PROGRESS),
                            'terminal' => $terminal,
                        ])
                    ));
                }
            });
        });

        return response()->json(['success' => true]);
    }

    private function mapStageToRouterState(string $stage): array
    {
        return match ($stage) {
            'submitted' => ['provisioning', 'submitted'],
            'precheck_connectivity' => ['provisioning', 'ping_verification'],
            'deploying_config' => ['deploying', 'deploying_config'],
            'verifying_deployment' => ['verifying', 'verifying_deployment'],
            default => ['provisioning', $stage],
        };
    }
}
