<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenieACSService
{
    protected string $nbiUrl;
    protected string $uiUrl;

    public function __construct()
    {
        // Internal Docker service URL for NBI
        $this->nbiUrl = 'http://wificore-genieacs-nbi:7557';
        // Internal Docker service URL for UI (if needed for checks)
        $this->uiUrl = 'http://wificore-genieacs-ui:3000';
    }

    /**
     * Provision a device in GenieACS by setting its tags
     * This is the primary method for zero-touch onboarding
     * 
     * @param string $deviceId Serial number or MAC address or OUI-ProductClass-Serial
     * @param string $tenantId The UUID of the tenant
     * @param array $extraTags Additional tags
     * @return bool
     */
    public function provisionDevice(string $deviceId, string $tenantId, array $extraTags = []): bool
    {
        try {
            // Ensure device ID is in correct format (OUI-ProductClass-Serial) if we only have serial/mac?
            // Actually GenieACS IDs are typically OUI-ProductClass-Serial. 
            // If we assume the input might be partial, we might need to search for it first.
            // But for now, let's assume we are passing the ID or a query.

            // Tagging the device with tenant_id is the key isolation mechanism.
            // We use the presets API or devices API.
            
            // NOTE: Ideally we create a Preset for the specific device ID to apply the tag
            // Or if the device is already online, we tag it directly.
            
            // Method 1: Create a Preset for this device (Pre-provisioning)
            // This works even if device hasn't connected yet.
            $presetName = "tenant-{$tenantId}-{$deviceId}";
            $presetData = [
                'weight' => 10,
                'precondition' => [
                    '_id' => $deviceId
                ],
                'configurations' => [
                    [
                        'type' => 'add_tag',
                        'tag' => "tenant:{$tenantId}"
                    ]
                ]
            ];
            
            $response = Http::put("{$this->nbiUrl}/presets/{$presetName}", $presetData);
            
            if (!$response->successful()) {
                Log::error('Failed to create GenieACS preset', [
                    'device_id' => $deviceId,
                    'tenant_id' => $tenantId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }

            Log::info('GenieACS preset created for device', [
                'device_id' => $deviceId,
                'tenant_id' => $tenantId
            ]);
            
            return true;

        } catch (\Exception $e) {
            Log::error('GenieACS Provisioning Exception', [
                'message' => $e->getMessage(),
                'device_id' => $deviceId
            ]);
            return false;
        }
    }

    /**
     * Get device details from GenieACS
     * 
     * @param string $deviceId
     * @return array|null
     */
    public function getDevice(string $deviceId): ?array
    {
        try {
            // Need to query by _id
            // Note: Use projection to limit fields if needed
            $query = json_encode(['_id' => $deviceId]);
            $response = Http::get("{$this->nbiUrl}/devices", [
                'query' => $query
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data[0] ?? null;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('GenieACS Get Device Exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Search devices (e.g., by tag)
     * 
     * @param string $tenantId
     * @return array
     */
    public function getTenantDevices(string $tenantId): array
    {
        try {
            $query = json_encode(['_tags' => "tenant:{$tenantId}"]);
            $response = Http::get("{$this->nbiUrl}/devices", [
                'query' => $query
            ]);

            return $response->json() ?? [];
        } catch (\Exception $e) {
            Log::error('GenieACS Tenant Devices Exception', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Reboot a device
     * 
     * @param string $deviceId
     * @return bool
     */
    public function rebootDevice(string $deviceId): bool
    {
        try {
            $response = Http::post("{$this->nbiUrl}/devices/{$deviceId}/tasks?timeout=3000&connection_request", [
                'name' => 'reboot'
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS Reboot Exception', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Factory Reset a device
     * 
     * @param string $deviceId
     * @return bool
     */
    public function factoryResetDevice(string $deviceId): bool
    {
        try {
            $response = Http::post("{$this->nbiUrl}/devices/{$deviceId}/tasks?timeout=3000&connection_request", [
                'name' => 'factoryReset'
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS Factory Reset Exception', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Push firmware to device
     * 
     * @param string $deviceId
     * @param string $fileName
     * @return bool
     */
    public function pushFirmware(string $deviceId, string $fileName): bool
    {
        try {
            $response = Http::post("{$this->nbiUrl}/devices/{$deviceId}/tasks?timeout=3000&connection_request", [
                'name' => 'download',
                'fileType' => '1 Firmware Upgrade Image',
                'fileName' => $fileName
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS Push Firmware Exception', ['message' => $e->getMessage()]);
            return false;
        }
    }
}
