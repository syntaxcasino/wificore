<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;

use App\Models\RouterConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use RouterOS\Client;
use RouterOS\Query;

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
    public function fetchInterfaces(Request $request)
    {
        $request->validate([
            'router_id' => 'required|exists:router_configs,id',
        ]);

        $router = RouterConfig::findOrFail($request->router_id);

        try {
            // Initialize RouterOS client
            $client = new Client([
                'host' => $router->ip_address,
                'port' => $router->port,
                'user' => $router->username,
                'pass' => $router->password,
            ]);

            // Fetch interfaces
            $interfaceQuery = new Query('/interface/print');
            $interfaceQuery->where('detail', true);
            $interfaces = $client->query($interfaceQuery)->read();

            // Fetch routerboard info
            $routerboardQuery = new Query('/system/routerboard/print');
            $routerboard = $client->query($routerboardQuery)->read();

            // Fetch system resource info
            $systemQuery = new Query('/system/resource/print');
            $system = $client->query($systemQuery)->read();

            // Format response
            $interfaceData = [
                'interfaces' => array_map(function ($iface) {
                    return [
                        'name' => $iface['name'],
                        'type' => $iface['type'] ?? 'Unknown',
                        'mac' => $iface['mac-address'] ?? 'N/A',
                    ];
                }, $interfaces),
                'routerboard' => [
                    'model' => $routerboard[0]['model'] ?? 'Unknown',
                    'serial' => $routerboard[0]['serial-number'] ?? 'N/A',
                ],
                'system' => [
                    'version' => $system[0]['version'] ?? 'N/A',
                    'cpu' => $system[0]['cpu'] ?? 'N/A',
                ],
            ];

            // Update router details
            $router->update([
                'model' => $interfaceData['routerboard']['model'],
                'os_version' => $interfaceData['system']['version'],
                'location' => $router->location ?? 'Unknown',
                'status' => 'active',
            ]);

            return response()->json($interfaceData, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch interfaces: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Apply service configurations to the router using RouterOS API.
     */
    public function applyConfigs(Request $request)
    {
        $request->validate([
            'router_id' => 'required|exists:router_configs,id',
            'interface_assignments' => 'required|array',
            'configurations' => 'required|array',
        ]);

        $router = RouterConfig::findOrFail($request->router_id);

        try {
            // Initialize RouterOS client
            $client = new Client([
                'host' => $router->ip_address,
                'port' => $router->port,
                'user' => $router->username,
                'pass' => $router->password,
            ]);

            // Generate and apply configuration commands
            foreach ($request->interface_assignments as $assignment) {
                $iface = $assignment['interface'];
                $service = $assignment['service'];
                $config = $request->configurations[$iface] ?? [];

                if ($service === 'hotspot') {
                    // Configure IP pool
                    $client->query((new Query('/ip/pool/add'))
                        ->equal('name', "hs-pool-{$iface}")
                        ->equal('ranges', $config['ip_pool']))->read();

                    // Configure hotspot profile
                    $client->query((new Query('/ip/hotspot/profile/add'))
                        ->equal('name', $config['hotspot_profile'])
                        ->equal('hotspot-address', $router->ip_address))->read();

                    // Configure hotspot
                    $client->query((new Query('/ip/hotspot/add'))
                        ->equal('name', "hotspot-{$iface}")
                        ->equal('interface', $iface)
                        ->equal('profile', $config['hotspot_profile'])
                        ->equal('address-pool', "hs-pool-{$iface}"))->read();
                } elseif ($service === 'pppoe') {
                    // Configure IP pool
                    $client->query((new Query('/ip/pool/add'))
                        ->equal('name', "pppoe-pool-{$iface}")
                        ->equal('ranges', $config['ip_pool']))->read();

                    // Configure PPP profile
                    $client->query((new Query('/ppp/profile/add'))
                        ->equal('name', "pppoe-profile-{$iface}")
                        ->equal('local-address', $router->ip_address)
                        ->equal('address-pool', "pppoe-pool-{$iface}"))->read();

                    // Configure PPPoE server
                    $client->query((new Query('/interface/pppoe-server/server/add'))
                        ->equal('service-name', $config['pppoe_service'])
                        ->equal('interface', $iface)
                        ->equal('authentication', 'pap,chap'))->read();
                }
            }

            // Save configurations to router model
            $router->update([
                'interface_assignments' => $request->interface_assignments,
                'configurations' => $request->configurations,
                'status' => 'active',
            ]);

            return response()->json(['message' => 'Configurations applied successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to apply configurations: ' . $e->getMessage()], 500);
        }
    }
}