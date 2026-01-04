<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionVpnConfigurationJob;
use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Services\VpnService;
use App\Services\VpnConnectivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VpnConfigurationController extends Controller
{
    public function __construct(
        private VpnService $vpnService,
        private VpnConnectivityService $connectivityService
    ) {}

    /**
     * Get all VPN configurations for tenant
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Only tenant users can access VPN configurations',
            ], 403);
        }

        // Table is in tenant schema, so it's implicitly scoped to the tenant.
        // No 'tenant_id' column exists in the schema-based table.
        $configs = VpnConfiguration::with(['router'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($config) {
                return [
                    'id' => $config->id,
                    'router_id' => $config->router_id,
                    'router_name' => $config->router?->name,
                    'vpn_type' => $config->vpn_type,
                    'client_ip' => $config->client_ip,
                    'server_ip' => $config->server_ip,
                    'subnet_cidr' => $config->subnet_cidr,
                    'status' => $config->status,
                    'is_connected' => $config->isConnected(),
                    'last_handshake_at' => $config->last_handshake_at?->toIso8601String(),
                    'traffic' => $config->getFormattedTraffic(),
                    'interface_name' => $config->interface_name,
                    'created_at' => $config->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $configs,
        ]);
    }

    /**
     * Get specific VPN configuration
     */
    public function show(Request $request, int $id)
    {
        // Table is in tenant schema, so it's implicitly scoped.
        $config = VpnConfiguration::with(['router'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $config->id,
                'router_id' => $config->router_id,
                'router_name' => $config->router?->name,
                'vpn_type' => $config->vpn_type,
                'server_public_key' => $config->server_public_key,
                'client_public_key' => $config->client_public_key,
                'client_ip' => $config->client_ip,
                'server_ip' => $config->server_ip,
                'subnet_cidr' => $config->subnet_cidr,
                'server_endpoint' => $config->server_endpoint,
                'listen_port' => $config->listen_port,
                'status' => $config->status,
                'is_connected' => $config->isConnected(),
                'last_handshake_at' => $config->last_handshake_at?->toIso8601String(),
                'traffic' => $config->getFormattedTraffic(),
                'interface_name' => $config->interface_name,
                'keepalive_interval' => $config->keepalive_interval,
                'allowed_ips' => $config->allowed_ips,
                'dns_servers' => $config->dns_servers,
                'mikrotik_script' => $config->mikrotik_script,
                'linux_script' => $config->linux_script,
                'created_at' => $config->created_at->toIso8601String(),
                'updated_at' => $config->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create new VPN configuration
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'router_id' => 'nullable|exists:routers,id',
            'vpn_type' => 'nullable|in:wireguard,ipsec',
            'interface_name' => 'nullable|string|max:50',
            'keepalive_interval' => 'nullable|integer|min:10|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenantId = $request->user()->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Only tenant users can create VPN configurations',
            ], 403);
        }

        // Verify router belongs to tenant if specified
        if ($request->router_id) {
            $router = Router::where('id', $request->router_id)
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router not found or does not belong to your tenant',
                ], 404);
            }

            // Check if router already has VPN config
            $existing = VpnConfiguration::where('router_id', $request->router_id)->first();
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router already has a VPN configuration',
                    'vpn_config_id' => $existing->id,
                ], 422);
            }
        }

        // Dispatch job to create VPN configuration
        $options = [
            'vpn_type' => $request->vpn_type ?? 'wireguard',
            'interface_name' => $request->interface_name ?? 'wg-hotspot',
            'keepalive_interval' => $request->keepalive_interval ?? 25,
        ];

        ProvisionVpnConfigurationJob::dispatch($tenantId, $request->router_id, $options)
            ->onQueue('vpn-provisioning');

        Log::info('VPN configuration job dispatched', [
            'tenant_id' => $tenantId,
            'router_id' => $request->router_id,
            'requested_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'VPN configuration is being created. You will receive a notification when ready.',
        ], 202);
    }

    /**
     * Download MikroTik script
     */
    public function downloadMikrotikScript(Request $request, int $id)
    {
        // Table is in tenant schema, so it's implicitly scoped.
        $config = VpnConfiguration::findOrFail($id);

        $filename = "mikrotik-vpn-{$config->id}.rsc";

        return response($config->mikrotik_script)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Download Linux WireGuard config
     */
    public function downloadLinuxConfig(Request $request, int $id)
    {
        // Table is in tenant schema, so it's implicitly scoped.
        $config = VpnConfiguration::findOrFail($id);

        $filename = "{$config->interface_name}.conf";

        return response($config->linux_script)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Delete VPN configuration
     */
    public function destroy(Request $request, int $id)
    {
        // Table is in tenant schema, so it's implicitly scoped.
        $config = VpnConfiguration::findOrFail($id);

        // Update router if associated
        if ($config->router) {
            $config->router->update([
                'vpn_ip' => null,
                'vpn_status' => null,
            ]);
        }

        $this->vpnService->deleteVpnConfiguration($config);

        return response()->json([
            'success' => true,
            'message' => 'VPN configuration deleted successfully',
        ]);
    }

    /**
     * Get tenant's subnet allocation info
     */
    public function getSubnetInfo(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Only tenant users can access subnet information',
            ], 403);
        }

        // Use TenantVpnTunnel as the source of truth for the subnet
        $tunnel = \App\Models\TenantVpnTunnel::where('tenant_id', $tenantId)->first();

        if (!$tunnel) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No subnet allocated yet. Create your first VPN configuration to allocate a subnet.',
            ]);
        }

        // Calculate usage dynamically
        $totalIps = 65534; // /16 subnet
        // We can count configurations in the current tenant schema linked to this tunnel
        $allocatedIps = VpnConfiguration::where('tenant_vpn_tunnel_id', $tunnel->id)->count();
        $availableIps = $totalIps - $allocatedIps;
        $usagePercentage = $totalIps > 0 ? round(($allocatedIps / $totalIps) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'subnet_cidr' => $tunnel->subnet_cidr,
                'gateway_ip' => $tunnel->server_ip,
                'range_start' => str_replace('.0.0/16', '.0.2', $tunnel->subnet_cidr), // approximate
                'range_end' => str_replace('.0.0/16', '.255.254', $tunnel->subnet_cidr), // approximate
                'total_ips' => $totalIps,
                'allocated_ips' => $allocatedIps,
                'available_ips' => $availableIps,
                'usage_percentage' => $usagePercentage,
                'status' => $tunnel->status,
            ],
        ]);
    }

    /**
     * Verify VPN connectivity to a router
     * Tests if server can ping the router's VPN IP
     */
    public function verifyConnectivity(Request $request, int $id)
    {
        $config = VpnConfiguration::findOrFail($id);

        Log::info('VPN connectivity verification requested', [
            'config_id' => $id,
            'router_id' => $config->router_id,
            'client_ip' => $config->client_ip,
        ]);

        // Perform connectivity check
        $result = $this->connectivityService->verifyConnectivity($config);

        // Update VPN configuration status based on result
        if ($result['success'] && $result['packet_loss'] === 0) {
            $config->update([
                'status' => 'connected',
                'last_handshake_at' => now(),
            ]);
        } else {
            $config->update([
                'status' => 'disconnected',
            ]);
        }

        return response()->json([
            'success' => $result['success'],
            'data' => [
                'config_id' => $id,
                'router_id' => $config->router_id,
                'client_ip' => $config->client_ip,
                'connectivity' => [
                    'reachable' => $result['success'],
                    'packet_loss' => $result['packet_loss'],
                    'latency_ms' => $result['latency'],
                    'status' => $result['success'] ? 'connected' : 'disconnected',
                ],
                'message' => $result['message'],
            ],
        ]);
    }

    /**
     * Wait for VPN connectivity with retries
     * Useful for polling until router establishes VPN tunnel
     */
    public function waitForConnectivity(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'max_wait_seconds' => 'integer|min:10|max:300',
            'retry_interval' => 'integer|min:2|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $config = VpnConfiguration::findOrFail($id);

        $maxWaitSeconds = $request->input('max_wait_seconds', 120);
        $retryInterval = $request->input('retry_interval', 5);

        Log::info('Waiting for VPN connectivity', [
            'config_id' => $id,
            'router_id' => $config->router_id,
            'max_wait_seconds' => $maxWaitSeconds,
        ]);

        // Wait for connectivity
        $result = $this->connectivityService->waitForConnectivity(
            $config,
            $maxWaitSeconds,
            $retryInterval
        );

        // Update status
        if ($result['success']) {
            $config->update([
                'status' => 'connected',
                'last_handshake_at' => now(),
            ]);
        }

        return response()->json([
            'success' => $result['success'],
            'data' => [
                'config_id' => $id,
                'router_id' => $config->router_id,
                'client_ip' => $config->client_ip,
                'connectivity' => [
                    'reachable' => $result['success'],
                    'packet_loss' => $result['packet_loss'],
                    'latency_ms' => $result['latency'],
                    'status' => $result['success'] ? 'connected' : 'timeout',
                    'attempts' => $result['attempts'] ?? null,
                ],
                'message' => $result['message'],
            ],
        ]);
    }
}
