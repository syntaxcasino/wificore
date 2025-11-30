<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterVpnConfig;
use App\Services\WireGuardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouterVpnController extends Controller
{
    protected WireGuardService $wireGuardService;

    public function __construct(WireGuardService $wireGuardService)
    {
        $this->wireGuardService = $wireGuardService;
    }

    /**
     * Create VPN configuration for a router
     */
    public function createVpnConfig(Request $request, Router $router)
    {
        try {
            // Check if router already has VPN config
            if ($router->vpnConfig) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router already has VPN configuration',
                ], 400);
            }

            // Create VPN configuration
            $vpnConfig = $this->wireGuardService->createRouterVpnConfig($router);

            return response()->json([
                'success' => true,
                'message' => 'VPN configuration created successfully',
                'data' => [
                    'vpn_config' => [
                        'id' => $vpnConfig->id,
                        'vpn_ip_address' => $vpnConfig->vpn_ip_address,
                        'wireguard_public_key' => $vpnConfig->wireguard_public_key,
                        'listen_port' => $vpnConfig->listen_port,
                        'radius_server_ip' => $vpnConfig->radius_server_ip,
                        'radius_auth_port' => $vpnConfig->radius_auth_port,
                        'radius_acct_port' => $vpnConfig->radius_acct_port,
                    ],
                    'server' => [
                        'public_key' => $this->wireGuardService->getServerPublicKey(),
                        'endpoint' => $this->wireGuardService->getServerEndpoint(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create VPN config', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create VPN configuration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get MikroTik configuration script
     */
    public function getMikroTikScript(Router $router)
    {
        try {
            if (!$router->vpnConfig) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router does not have VPN configuration',
                ], 404);
            }

            $script = $this->wireGuardService->generateMikroTikScript($router->vpnConfig);

            return response()->json([
                'success' => true,
                'script' => $script,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate script',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download MikroTik configuration script
     */
    public function downloadScript(Router $router)
    {
        try {
            if (!$router->vpnConfig) {
                abort(404, 'Router does not have VPN configuration');
            }

            $script = $this->wireGuardService->generateMikroTikScript($router->vpnConfig);
            $filename = "router-{$router->id}-vpn-config.rsc";

            return response($script)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");

        } catch (\Exception $e) {
            abort(500, 'Failed to generate script');
        }
    }

    /**
     * Get VPN status
     */
    public function getVpnStatus(Router $router)
    {
        try {
            if (!$router->vpnConfig) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router does not have VPN configuration',
                ], 404);
            }

            $status = $this->wireGuardService->getPeerStatus(
                $router->vpnConfig->wireguard_public_key
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'vpn_config' => [
                        'vpn_ip' => $router->vpnConfig->vpn_ip_address,
                        'connected' => $router->vpnConfig->vpn_connected,
                        'last_handshake' => $router->vpnConfig->last_handshake,
                        'bytes_received' => $router->vpnConfig->bytes_received,
                        'bytes_sent' => $router->vpnConfig->bytes_sent,
                        'formatted_data_usage' => $router->vpnConfig->formatted_data_usage,
                    ],
                    'live_status' => $status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get VPN status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete VPN configuration
     */
    public function deleteVpnConfig(Router $router)
    {
        try {
            if (!$router->vpnConfig) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router does not have VPN configuration',
                ], 404);
            }

            // Remove from WireGuard server
            $this->wireGuardService->removePeerFromServer($router->vpnConfig);

            // Delete from database
            $router->vpnConfig->delete();

            return response()->json([
                'success' => true,
                'message' => 'VPN configuration deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete VPN config', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete VPN configuration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate RADIUS secret
     */
    public function regenerateRadiusSecret(Router $router)
    {
        try {
            if (!$router->vpnConfig) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router does not have VPN configuration',
                ], 404);
            }

            $newSecret = \Str::random(32);
            $router->vpnConfig->update(['radius_secret' => $newSecret]);

            // Update nas table
            \DB::table('nas')
                ->where('nasname', $router->vpnConfig->vpn_ip_address)
                ->update(['secret' => $newSecret]);

            return response()->json([
                'success' => true,
                'message' => 'RADIUS secret regenerated successfully',
                'data' => [
                    'radius_secret' => $newSecret,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate RADIUS secret',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
