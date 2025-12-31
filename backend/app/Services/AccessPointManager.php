<?php

namespace App\Services;

use App\Models\Router;
use App\Models\AccessPoint;
use App\Models\ApActiveSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AccessPointManager extends TenantAwareService
{
    protected GenieACSService $genieAcsService;

    public function __construct(GenieACSService $genieAcsService)
    {
        $this->genieAcsService = $genieAcsService;
    }

    /**
     * Discover access points on network (placeholder)
     * 
     * @param Router $router
     * @return array
     */
    public function discoverAccessPoints(Router $router): array
    {
        // TODO: Implement actual AP discovery via network scanning
        // This could use SNMP, ARP tables, or other discovery methods
        
        Log::info("Discovering access points", [
            'router_id' => $router->id,
        ]);
        
        // Placeholder return
        return [];
    }

    /**
     * Add access point
     * 
     * @param Router $router
     * @param array $data
     * @return AccessPoint
     */
    public function addAccessPoint(Router $router, array $data): AccessPoint
    {
        try {
            $ap = AccessPoint::create([
                'router_id' => $router->id,
                'tenant_id' => $router->tenant_id,
                'name' => $data['name'],
                'vendor' => $data['vendor'],
                'model' => $data['model'] ?? null,
                'ip_address' => $data['ip_address'],
                'mac_address' => $data['mac_address'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'management_protocol' => $data['management_protocol'] ?? 'snmp',
                'credentials' => $data['credentials'] ?? null,
                'location' => $data['location'] ?? null,
                'status' => AccessPoint::STATUS_UNKNOWN,
                'total_capacity' => $data['total_capacity'] ?? null,
            ]);
            
            Log::info("Access point added successfully", [
                'ap_id' => $ap->id,
                'router_id' => $router->id,
                'vendor' => $ap->vendor,
            ]);

            // Zero-Touch Onboarding: Provision in GenieACS if serial number or MAC is present
            $deviceId = $data['serial_number'] ?? $data['mac_address'] ?? null;
            if ($deviceId) {
                // Ensure we have the correct identifier format for GenieACS if possible, 
                // or just pass what we have and let the service handle/log it.
                // We pass the router's tenant_id for isolation.
                $provisioned = $this->genieAcsService->provisionDevice($deviceId, $router->tenant_id);
                
                if ($provisioned) {
                    Log::info("Access point provisioned in GenieACS", [
                        'ap_id' => $ap->id,
                        'device_id' => $deviceId,
                        'tenant_id' => $router->tenant_id
                    ]);
                } else {
                    Log::warning("Failed to provision access point in GenieACS", [
                        'ap_id' => $ap->id,
                        'device_id' => $deviceId
                    ]);
                }
            }
            
            return $ap;
        } catch (\Exception $e) {
            Log::error("Failed to add access point", [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Update access point
     * 
     * @param AccessPoint $ap
     * @param array $data
     * @return AccessPoint
     */
    public function updateAccessPoint(AccessPoint $ap, array $data): AccessPoint
    {
        try {
            $ap->update($data);
            
            Log::info("Access point updated successfully", [
                'ap_id' => $ap->id,
            ]);
            
            return $ap->fresh();
        } catch (\Exception $e) {
            Log::error("Failed to update access point", [
                'ap_id' => $ap->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Remove access point
     * 
     * @param AccessPoint $ap
     * @return bool
     */
    public function removeAccessPoint(AccessPoint $ap): bool
    {
        try {
            $ap->delete();
            
            Log::info("Access point removed successfully", [
                'ap_id' => $ap->id,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to remove access point", [
                'ap_id' => $ap->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get active users count for an AP
     * 
     * @param AccessPoint $ap
     * @return int
     */
    public function getActiveUsers(AccessPoint $ap): int
    {
        return $ap->activeSessions()->active()->count();
    }

    /**
     * Get active sessions for an AP
     * 
     * @param AccessPoint $ap
     * @return Collection
     */
    public function getActiveSessions(AccessPoint $ap): Collection
    {
        return $ap->activeSessions()->active()->get();
    }

    /**
     * Sync access point status
     * 
     * @param AccessPoint $ap
     * @return array
     */
    public function syncAccessPointStatus(AccessPoint $ap): array
    {
        try {
            // TODO: Implement actual status sync based on vendor
            // This would use SNMP, SSH, or vendor-specific APIs
            
            $activeUsers = $this->getActiveUsers($ap);
            
            $ap->update([
                'active_users' => $activeUsers,
                'last_seen_at' => now(),
                'status' => AccessPoint::STATUS_ONLINE, // Placeholder
            ]);
            
            Log::info("Access point status synced", [
                'ap_id' => $ap->id,
                'active_users' => $activeUsers,
            ]);
            
            return $this->getStatistics($ap);
        } catch (\Exception $e) {
            Log::error("Failed to sync access point status", [
                'ap_id' => $ap->id,
                'error' => $e->getMessage(),
            ]);
            
            $ap->update(['status' => AccessPoint::STATUS_ERROR]);
            
            throw $e;
        }
    }

    /**
     * Get AP statistics
     * 
     * @param AccessPoint $ap
     * @return array
     */
    public function getStatistics(AccessPoint $ap): array
    {
        $sessions = $ap->activeSessions()->active()->get();
        
        return [
            'id' => $ap->id,
            'name' => $ap->name,
            'vendor' => $ap->vendor,
            'status' => $ap->status,
            'active_users' => $ap->active_users,
            'total_capacity' => $ap->total_capacity,
            'capacity_percentage' => $ap->getCapacityPercentage(),
            'signal_strength' => $ap->signal_strength,
            'uptime_seconds' => $ap->uptime_seconds,
            'uptime_formatted' => $ap->getUptimeFormatted(),
            'last_seen_at' => $ap->last_seen_at,
            'total_sessions' => $sessions->count(),
            'total_bytes_in' => $sessions->sum('bytes_in'),
            'total_bytes_out' => $sessions->sum('bytes_out'),
        ];
    }

    /**
     * Link RADIUS session to AP
     * 
     * @param string $calledStationId
     * @param array $sessionData
     * @return ApActiveSession|null
     */
    public function linkSessionToAP(string $calledStationId, array $sessionData): ?ApActiveSession
    {
        try {
            // Parse MAC address from Called-Station-ID
            // Format: "AA:BB:CC:DD:EE:FF:SSID-Name"
            $parts = explode(':', $calledStationId);
            if (count($parts) < 6) {
                return null;
            }
            
            $apMac = implode(':', array_slice($parts, 0, 6));
            
            // Find AP by MAC address
            $ap = AccessPoint::where('mac_address', $apMac)->first();
            
            if (!$ap) {
                Log::warning("Access point not found for MAC", [
                    'mac_address' => $apMac,
                    'called_station_id' => $calledStationId,
                ]);
                return null;
            }
            
            // Create or update session
            $session = ApActiveSession::updateOrCreate(
                [
                    'access_point_id' => $ap->id,
                    'mac_address' => $sessionData['mac_address'],
                ],
                [
                    'router_id' => $ap->router_id,
                    'username' => $sessionData['username'] ?? null,
                    'ip_address' => $sessionData['ip_address'] ?? null,
                    'session_id' => $sessionData['session_id'] ?? null,
                    'connected_at' => $sessionData['connected_at'] ?? now(),
                    'last_activity_at' => now(),
                    'bytes_in' => $sessionData['bytes_in'] ?? 0,
                    'bytes_out' => $sessionData['bytes_out'] ?? 0,
                ]
            );
            
            // Update AP active user count
            $ap->increment('active_users');
            
            Log::info("Session linked to AP", [
                'ap_id' => $ap->id,
                'session_id' => $session->id,
                'username' => $sessionData['username'] ?? 'unknown',
            ]);
            
            return $session;
        } catch (\Exception $e) {
            Log::error("Failed to link session to AP", [
                'called_station_id' => $calledStationId,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Remove session from AP
     * 
     * @param ApActiveSession $session
     * @return bool
     */
    public function removeSession(ApActiveSession $session): bool
    {
        try {
            $ap = $session->accessPoint;
            
            $session->delete();
            
            // Update AP active user count
            if ($ap) {
                $ap->decrement('active_users');
            }
            
            Log::info("Session removed from AP", [
                'ap_id' => $ap->id ?? null,
                'session_id' => $session->id,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to remove session from AP", [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
}
