<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;

use App\Jobs\ExecuteProvisioningServiceRouterTaskJob;
use App\Models\RouterConfig;
use App\Models\RouterTask;
use App\Services\ProvisioningServiceClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProvisioningController extends Controller
{
    /**
     * Save configuration scripts and generate a token.
     */
    public function saveConfigs(Request $request)
    {
        $request->validate([
            'router_id' => 'required|exists:router_configs,id',
            'scripts.vpn' => 'required|string',
            'scripts.info' => 'required|string',
        ]);

        $token = Str::uuid()->toString();
        $router = RouterConfig::findOrFail($request->router_id);

        // Save scripts to storage
        Storage::disk('local')->put("configs/{$token}_vpn.rsc", $request->scripts['vpn']);
        Storage::disk('local')->put("configs/{$token}_info.rsc", $request->scripts['info']);

        // Update router with token
        $router->update(['config_token' => $token]);

        return response()->json([
            'message' => 'Configurations saved successfully',
            'token' => $token,
        ], 200);
    }

    /**
     * Retrieve configuration scripts by token.
     */
    public function getConfigs(Request $request)
    {
        $request->validate([
            'token' => 'required|uuid',
        ]);

        $token = $request->token;

        if (!Storage::disk('local')->exists("configs/{$token}_vpn.rsc") ||
            !Storage::disk('local')->exists("configs/{$token}_info.rsc")) {
            return response()->json(['error' => 'Invalid or expired token'], 404);
        }

        $vpnScript = Storage::disk('local')->get("configs/{$token}_vpn.rsc");
        $infoScript = Storage::disk('local')->get("configs/{$token}_info.rsc");

        // Optionally, delete scripts after retrieval
        // Storage::disk('local')->delete(["configs/{$token}_vpn.rsc", "configs/{$token}_info.rsc"]);

        return response()->json([
            'vpn' => $vpnScript,
            'info' => $infoScript,
        ], 200);
    }

    /**
     * Fetch interface information from the router using RouterOS API.
     */
    public function fetchInterfaces(Request $request, ProvisioningServiceClient $provisioningClient)
    {
        $request->validate([
            'router_id' => 'required|exists:router_configs,id',
        ]);

        $routerConfig = RouterConfig::with('router')->findOrFail($request->router_id);
        $router = $routerConfig->router;

        if (!$router) {
            return response()->json(['error' => 'Router not found for configuration'], 404);
        }

        try {
            $liveData = $provisioningClient->fetchLiveData($router, 'provisioning', (string) $router->tenant_id);

            return response()->json([
                'interfaces' => array_map(function (array $iface) {
                    return [
                        'name' => $iface['name'] ?? 'Unknown',
                        'type' => $iface['type'] ?? 'Unknown',
                        'mac' => $iface['mac-address'] ?? ($iface['mac'] ?? 'N/A'),
                    ];
                }, $liveData['interfaces'] ?? []),
                'routerboard' => [
                    'model' => $liveData['board_name'] ?? ($router->model ?? 'Unknown'),
                    'serial' => 'N/A',
                ],
                'system' => [
                    'version' => $liveData['version'] ?? ($router->os_version ?? 'N/A'),
                    'cpu' => $liveData['cpu_load'] ?? 'N/A',
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch interfaces via provisioning service', [
                'router_id' => $router->id,
                'router_config_id' => $routerConfig->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch interfaces: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Apply service configurations to the router using provisioning service.
     */
    public function applyConfigs(Request $request)
    {
        $request->validate([
            'router_id' => 'required|exists:router_configs,id',
            'interface_assignments' => 'required|array',
            'configurations' => 'required|array',
        ]);

        $routerConfig = RouterConfig::with('router')->findOrFail($request->router_id);
        $router = $routerConfig->router;

        if (!$router) {
            return response()->json(['error' => 'Router not found for configuration'], 404);
        }

        try {
            $commands = [];

            foreach ($request->interface_assignments as $assignment) {
                $iface = $assignment['interface'] ?? null;
                $service = $assignment['service'] ?? null;
                $config = $request->configurations[$iface] ?? [];

                if (!$iface || !$service) {
                    continue;
                }

                if ($service === 'hotspot') {
                    $commands[] = '/ip pool add name="hs-pool-' . $iface . '" ranges=' . $config['ip_pool'];
                    $commands[] = '/ip hotspot profile add name="' . $config['hotspot_profile'] . '" hotspot-address=' . $router->ip_address;
                    $commands[] = '/ip hotspot add name="hotspot-' . $iface . '" interface=' . $iface . ' profile="' . $config['hotspot_profile'] . '" address-pool="hs-pool-' . $iface . '"';
                } elseif ($service === 'pppoe') {
                    $commands[] = '/ip pool add name="pppoe-pool-' . $iface . '" ranges=' . $config['ip_pool'];
                    $commands[] = '/ppp profile add name="pppoe-profile-' . $iface . '" local-address=' . $router->ip_address . ' address-pool="pppoe-pool-' . $iface . '"';
                    $commands[] = '/interface pppoe-server server add service-name="' . $config['pppoe_service'] . '" interface=' . $iface . ' authentication=pap,chap';
                }
            }

            if ($commands === []) {
                return response()->json(['error' => 'No valid provisioning commands were generated'], 422);
            }

            $task = RouterTask::create([
                'tenant_id' => (string) $router->tenant_id,
                'router_id' => (string) $router->id,
                'user_id' => (string) auth()->id(),
                'type' => RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
                'status' => RouterTask::STATUS_QUEUED,
                'progress' => 0,
                'message' => 'Task accepted and queued',
                'request_payload' => [
                    'commands' => $commands,
                    'source' => 'legacy_provisioning_controller',
                ],
            ]);

            ExecuteProvisioningServiceRouterTaskJob::dispatch($task->id, (string) $router->tenant_id, (string) $router->id);

            return response()->json([
                'success' => true,
                'message' => 'Configuration apply task accepted',
                'task' => [
                    'id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'progress' => $task->progress,
                    'router_id' => $task->router_id,
                    'created_at' => $task->created_at,
                    'status_url' => route('api.routers.tasks.show', ['router' => $task->router_id, 'task' => $task->id]),
                ],
            ], 202);
        } catch (\Exception $e) {
            Log::error('Failed to apply configurations via provisioning service', [
                'router_id' => $router->id,
                'router_config_id' => $routerConfig->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to apply configurations: ' . $e->getMessage()], 500);
        }
    }
}
