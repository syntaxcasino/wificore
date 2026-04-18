<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterService;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Models\TenantIpPool;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\RouterServiceManager;
use App\Services\TenantContext;
use App\Services\RouterResourceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ScriptPreviewController
 *
 * Mirrors the EXACT production provisioning flow — same DB persistence,
 * same RouterServiceManager, same ZeroConfig generators — but skips
 * VPN/SSH connectivity and deployment.
 *
 * Flow (mirrors CreateRouterModal + ServiceConfigurationController):
 *   1. createRouter   — persists Router in tenant schema (like POST /routers)
 *   2. configureService — maps interface→service via RouterServiceManager (like POST /routers/{id}/services/configure)
 *   3. generateScript — runs the same generators as DeployRouterServiceJob (replaces SSH deploy)
 *   4. destroy        — deletes the preview router, services, and pools (cleanup)
 */
class ScriptPreviewController extends Controller
{
    /**
     * Step 1: Create a preview router inside the tenant schema.
     * POST /system/script-preview/router
     */
    public function createRouter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id'    => 'required|uuid|exists:tenants,id',
            'router_name'  => 'required|string|max:100',
            'router_model' => 'nullable|string|max:100',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $tenantContext = app(TenantContext::class);

        try {
            $router = DB::transaction(function () use ($tenant, $tenantContext, $validated) {
                return $tenantContext->runInTenantContext($tenant, function () use ($validated) {
                    return Router::create([
                        'name'       => 'preview_' . $validated['router_name'],
                        'model'      => $validated['router_model'] ?? 'RB750Gr3',
                        'status'     => 'offline',
                        'ip_address' => '0.0.0.0',
                        'username'   => 'preview',
                        'password'   => 'preview',
                    ]);
                });
            });

            $tier = RouterResourceManager::getRouterTierByModel($router->model);

            return response()->json([
                'success'   => true,
                'router_id' => $router->id,
                'name'      => $router->name,
                'model'     => $router->model,
                'tier'      => $tier,
                'tier_label' => str_replace('_', ' ', ucfirst($tier)),
            ]);
        } catch (\Throwable $e) {
            Log::error('ScriptPreview: createRouter failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Step 2: Configure a service on an interface — exact same code path
     * as ServiceConfigurationController::configure().
     * POST /system/script-preview/{routerId}/configure
     */
    public function configureService(Request $request, string $routerId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id'        => 'required|uuid|exists:tenants,id',
            'interface'        => 'required|string|max:50',
            'service_type'     => 'required|in:pppoe,hotspot,hybrid,none',
            'advanced_options' => 'nullable|array',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $tenantContext = app(TenantContext::class);
        $serviceManager = app(RouterServiceManager::class);

        try {
            $result = DB::transaction(function () use ($tenant, $tenantContext, $routerId, $validated, $serviceManager) {
                return $tenantContext->runInTenantContext($tenant, function () use (
                    $routerId, $validated, $serviceManager
                ) {
                    $router = Router::findOrFail($routerId);

                    $service = $serviceManager->configureService(
                        $router,
                        $validated['interface'],
                        $validated['service_type'],
                        $validated['advanced_options'] ?? []
                    );

                    if ($validated['service_type'] === 'none') {
                        return ['removed' => true];
                    }

                    return [
                        'service' => $service->load(['ipPool', 'vlans']),
                    ];
                });
            });

            if (!empty($result['removed'])) {
                return response()->json([
                    'success'   => true,
                    'message'   => 'Service removed from interface',
                    'interface' => $validated['interface'],
                ]);
            }

            return response()->json([
                'success'    => true,
                'message'    => 'Service configured',
                'service'    => $result['service'],
                'validation' => ['valid' => true],
            ]);
        } catch (\Throwable $e) {
            Log::error('ScriptPreview: configureService failed', [
                'router_id' => $routerId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Step 3: Generate the full MikroTik script — same generators as
     * DeployRouterServiceJob::generateConfiguration(), minus the SSH deploy.
     * POST /system/script-preview/{routerId}/generate
     */
    public function generateScript(Request $request, string $routerId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|uuid|exists:tenants,id',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $tenantContext = app(TenantContext::class);

        try {
            $result = DB::transaction(function () use ($tenant, $tenantContext, $routerId) {
                return $tenantContext->runInTenantContext($tenant, function () use ($routerId) {
                    $router = Router::findOrFail($routerId);

                    $services = RouterService::where('router_id', $routerId)
                        ->with(['router', 'ipPool', 'vlans'])
                        ->get();

                    if ($services->isEmpty()) {
                        throw new \RuntimeException('No services configured on this router. Map at least one interface first.');
                    }

                    // Group by service_type so each type produces exactly ONE script,
                    // regardless of how many interface rows were created.
                    $grouped = $services->groupBy('service_type');
                    $scripts = [];

                    foreach ($grouped as $type => $group) {
                        $scripts[] = $this->buildScript($this->mergeServiceGroup($group));
                    }

                    return implode("\n\n", $scripts);
                });
            });

            // Detect tier from the router that was used for generation
            $tier = DB::transaction(function () use ($tenant, $tenantContext, $routerId) {
                return $tenantContext->runInTenantContext($tenant, function () use ($routerId) {
                    $router = Router::findOrFail($routerId);
                    return RouterResourceManager::getRouterTier($router);
                });
            });

            return response()->json([
                'success'    => true,
                'script'     => $result,
                'lines'      => substr_count($result, "\n") + 1,
                'bytes'      => strlen($result),
                'tier'       => $tier,
                'tier_label' => str_replace('_', ' ', ucfirst($tier)),
            ]);
        } catch (\Throwable $e) {
            Log::error('ScriptPreview: generateScript failed', [
                'router_id' => $routerId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Step 4: Delete the preview router, its services, and associated pools.
     * DELETE /system/script-preview/{routerId}
     */
    public function destroy(Request $request, string $routerId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|uuid|exists:tenants,id',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $tenantContext = app(TenantContext::class);

        try {
            DB::transaction(function () use ($routerId, $tenant, $tenantContext) {
                return $tenantContext->runInTenantContext($tenant, function () use ($routerId) {
                    $router = Router::find($routerId);
                    if (!$router) {
                        return;
                    }

                    if (!str_starts_with($router->name, 'preview_')) {
                        throw new \RuntimeException("Router {$routerId} is not a preview router — refusing to delete.");
                    }

                    // Collect pool IDs from services before deletion
                    $poolIds = RouterService::where('router_id', $routerId)
                        ->whereNotNull('ip_pool_id')
                        ->pluck('ip_pool_id')
                        ->toArray();

                    // Also grab pool IDs from advanced_config (hybrid stores them there)
                    $hybridServices = RouterService::where('router_id', $routerId)
                        ->where('service_type', RouterService::TYPE_HYBRID)
                        ->get();
                    foreach ($hybridServices as $hs) {
                        $cfg = $hs->advanced_config ?? [];
                        if (!empty($cfg['hotspot_pool_id'])) {
                            $poolIds[] = $cfg['hotspot_pool_id'];
                        }
                        if (!empty($cfg['pppoe_pool_id'])) {
                            $poolIds[] = $cfg['pppoe_pool_id'];
                        }
                    }

                    $poolIds = array_unique(array_filter($poolIds));

                    // Delete services
                    RouterService::where('router_id', $routerId)->delete();

                    // Delete router (observer will unregister from tenant map)
                    $router->delete();

                    // Delete associated pools
                    if (!empty($poolIds)) {
                        TenantIpPool::withoutGlobalScopes()
                            ->whereIn('id', $poolIds)
                            ->delete();
                    }
                });
            });

            return response()->json([
                'success' => true,
                'message' => "Preview router {$routerId} and associated resources deleted.",
            ]);
        } catch (\Throwable $e) {
            Log::error('ScriptPreview: destroy failed', [
                'router_id' => $routerId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Return known router models grouped by tier for the frontend dropdown.
     * GET /system/script-preview/models
     */
    public function routerModels(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'models'  => [
                [
                    'tier'  => 'low_end',
                    'label' => 'Low End',
                    'description' => 'Minimal firewall, longer delays, ~7 rules',
                    'models' => [
                        ['value' => 'RB941-2nD', 'label' => 'hAP lite (RB941-2nD)'],
                        ['value' => 'RB951Ui-2HnD', 'label' => 'hAP (RB951Ui-2HnD)'],
                        ['value' => 'RB952Ui-5ac2nD', 'label' => 'hAP ac lite (RB952Ui-5ac2nD)'],
                        ['value' => 'RB750', 'label' => 'hEX Lite (RB750)'],
                        ['value' => 'RB750Gr3', 'label' => 'hEX (RB750Gr3)'],
                        ['value' => 'cAP lite', 'label' => 'cAP lite'],
                        ['value' => 'mAP', 'label' => 'mAP'],
                        ['value' => 'wAP', 'label' => 'wAP'],
                        ['value' => 'SXT LTE', 'label' => 'SXT LTE'],
                    ],
                ],
                [
                    'tier'  => 'mid_range',
                    'label' => 'Mid Range',
                    'description' => 'Full firewall, standard delays, ~15 rules',
                    'models' => [
                        ['value' => 'RB2011UiAS', 'label' => 'RB2011UiAS'],
                        ['value' => 'RB3011UiAS', 'label' => 'RB3011UiAS'],
                        ['value' => 'RB4011iGS+', 'label' => 'RB4011iGS+'],
                        ['value' => 'hAP ac2', 'label' => 'hAP ac2'],
                        ['value' => 'hAP ac3', 'label' => 'hAP ac3'],
                        ['value' => 'hEX S', 'label' => 'hEX S (RB760iGS)'],
                        ['value' => 'CHR', 'label' => 'Cloud Hosted Router (CHR)'],
                    ],
                ],
                [
                    'tier'  => 'high_end',
                    'label' => 'High End',
                    'description' => 'Full firewall, minimal delays, ~15 rules',
                    'models' => [
                        ['value' => 'CCR1009', 'label' => 'CCR1009'],
                        ['value' => 'CCR1016', 'label' => 'CCR1016'],
                        ['value' => 'CCR1036', 'label' => 'CCR1036'],
                        ['value' => 'CCR2004', 'label' => 'CCR2004'],
                        ['value' => 'CCR2116', 'label' => 'CCR2116'],
                        ['value' => 'CRS328', 'label' => 'CRS328'],
                        ['value' => 'CRS354', 'label' => 'CRS354'],
                        ['value' => 'RB1100AHx4', 'label' => 'RB1100AHx4'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Merge all RouterService rows belonging to the same service type into a single
     * representative service with all interface names combined.
     * This ensures each service type produces exactly one script regardless of
     * how many per-interface rows exist in the database.
     */
    private function mergeServiceGroup(\Illuminate\Support\Collection $group): RouterService
    {
        /** @var RouterService $primary */
        $primary = $group->first();

        if ($group->count() === 1) {
            return $primary;
        }

        // Collect every interface name across all rows in this group
        $allInterfaces = $group->flatMap(function (RouterService $svc) {
            $raw = $svc->interface_name;
            if (is_array($raw)) {
                return $raw;
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : [$raw];
        })
        ->filter(fn ($i) => is_string($i) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', trim($i)))
        ->unique()
        ->values()
        ->toArray();

        $merged = $primary->replicate();
        $merged->id             = $primary->id;
        $merged->interface_name = $allInterfaces;
        $merged->setRelation('router', $primary->router);
        $merged->setRelation('ipPool', $primary->ipPool);
        $merged->setRelation('vlans', $primary->vlans);

        return $merged;
    }

    /**
     * Dispatch a merged/representative RouterService to the correct generator.
     * At this point the service already carries all interfaces for its type.
     */
    private function buildScript(RouterService $service): string
    {
        switch ($service->service_type) {
            case RouterService::TYPE_HOTSPOT:
                return (new ZeroConfigHotspotGenerator())->generate($service);

            case RouterService::TYPE_PPPOE:
                return (new ZeroConfigPPPoEGenerator())->generate($service);

            case RouterService::TYPE_HYBRID:
                return (new ZeroConfigHybridGenerator())->generate($service);

            default:
                throw new \RuntimeException("Unsupported service type: {$service->service_type}");
        }
    }
}
