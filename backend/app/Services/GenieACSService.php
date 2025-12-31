<?php

namespace App\Services;

use App\Models\GenieacsDevice;
use App\Models\GenieacsTask;
use App\Models\GenieacsPreset;
use App\Models\GenieacsFault;
use App\Models\AccessPoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * GenieACS Service - Tenant-Aware TR-069/CWMP Device Management
 * 
 * Manages TR-069 devices via GenieACS NBI API with full tenant isolation
 */
class GenieACSService extends TenantAwareService
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
     * @param string|null $accessPointId Optional access point to link
     * @param array $extraTags Additional tags
     * @return GenieacsDevice|null
     */
    public function provisionDevice(string $deviceId, ?string $accessPointId = null, array $extraTags = []): ?GenieacsDevice
    {
        try {
            // Create or update device record in database
            $device = GenieacsDevice::updateOrCreate(
                ['device_id' => $deviceId],
                [
                    'access_point_id' => $accessPointId,
                    'provisioning_status' => GenieacsDevice::PROVISIONING_PENDING,
                    'connection_status' => GenieacsDevice::STATUS_UNKNOWN,
                ]
            );

            // Create preset in GenieACS for this device
            $presetName = "device-{$deviceId}";
            
            $configurations = [
                [
                    'type' => 'add_tag',
                    'tag' => "device_id:{$deviceId}"
                ]
            ];
            
            // Add access point tag if provided
            if ($accessPointId) {
                $configurations[] = [
                    'type' => 'add_tag',
                    'tag' => "ap_id:{$accessPointId}"
                ];
            }
            
            // Add extra tags
            foreach ($extraTags as $tag) {
                $configurations[] = [
                    'type' => 'add_tag',
                    'tag' => $tag
                ];
            }
            
            $presetData = [
                'weight' => 10,
                'precondition' => [
                    '_id' => $deviceId
                ],
                'configurations' => $configurations
            ];
            
            // Create preset in database
            GenieacsPreset::updateOrCreate(
                ['name' => $presetName],
                [
                    'device_id' => $deviceId,
                    'weight' => 10,
                    'precondition' => ['_id' => $deviceId],
                    'configurations' => $configurations,
                    'is_active' => true,
                    'description' => 'Auto-generated preset for device provisioning'
                ]
            );
            
            // Send to GenieACS
            $response = Http::put("{$this->nbiUrl}/presets/{$presetName}", $presetData);
            
            if (!$response->successful()) {
                $device->update([
                    'provisioning_status' => GenieacsDevice::PROVISIONING_FAILED,
                    'provisioning_error' => "Failed to create preset: {$response->status()} - {$response->body()}"
                ]);
                
                Log::error('Failed to create GenieACS preset', [
                    'device_id' => $deviceId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            $device->update([
                'provisioning_status' => GenieacsDevice::PROVISIONING_PROVISIONED,
                'provisioned_at' => now()
            ]);

            Log::info('GenieACS device provisioned successfully', [
                'device_id' => $deviceId,
                'access_point_id' => $accessPointId
            ]);
            
            return $device;

        } catch (\Exception $e) {
            Log::error('GenieACS Provisioning Exception', [
                'message' => $e->getMessage(),
                'device_id' => $deviceId,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get device details from GenieACS and sync to database
     * 
     * @param string $deviceId
     * @return GenieacsDevice|null
     */
    public function getDevice(string $deviceId): ?GenieacsDevice
    {
        try {
            // Query GenieACS
            $query = json_encode(['_id' => $deviceId]);
            $response = Http::get("{$this->nbiUrl}/devices", [
                'query' => $query
            ]);

            if (!$response->successful()) {
                return null;
            }
            
            $genieData = $response->json()[0] ?? null;
            if (!$genieData) {
                return null;
            }

            // Sync to database
            return $this->syncDeviceFromGenieACS($genieData);
            
        } catch (\Exception $e) {
            Log::error('GenieACS Get Device Exception', [
                'message' => $e->getMessage(),
                'device_id' => $deviceId
            ]);
            return null;
        }
    }

    /**
     * Sync device data from GenieACS to local database
     * 
     * @param array $genieData
     * @return GenieacsDevice
     */
    protected function syncDeviceFromGenieACS(array $genieData): GenieacsDevice
    {
        $deviceId = $genieData['_id'] ?? null;
        if (!$deviceId) {
            throw new \Exception('Device ID not found in GenieACS data');
        }

        $deviceInfo = $genieData['_deviceId'] ?? [];
        $lastInform = $genieData['_lastInform'] ?? null;
        $lastBoot = $genieData['_lastBoot'] ?? null;
        $tags = $genieData['_tags'] ?? [];

        return GenieacsDevice::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'serial_number' => $deviceInfo['_SerialNumber'] ?? null,
                'mac_address' => $deviceInfo['_OUI'] ?? null,
                'manufacturer' => $deviceInfo['_Manufacturer'] ?? null,
                'model' => $deviceInfo['_ProductClass'] ?? null,
                'software_version' => $genieData['InternetGatewayDevice.DeviceInfo.SoftwareVersion._value'] ?? null,
                'hardware_version' => $genieData['InternetGatewayDevice.DeviceInfo.HardwareVersion._value'] ?? null,
                'ip_address' => $genieData['InternetGatewayDevice.ManagementServer.ConnectionRequestURL._value'] ?? null,
                'connection_status' => $lastInform ? GenieacsDevice::STATUS_ONLINE : GenieacsDevice::STATUS_OFFLINE,
                'last_inform' => $lastInform ? date('Y-m-d H:i:s', $lastInform / 1000) : null,
                'last_boot' => $lastBoot ? date('Y-m-d H:i:s', $lastBoot / 1000) : null,
                'tags' => $tags,
                'parameters' => $genieData,
            ]
        );
    }

    /**
     * Get all devices for current tenant from database
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllDevices()
    {
        return GenieacsDevice::with('accessPoint')->get();
    }

    /**
     * Sync all devices from GenieACS to database
     * 
     * @return int Number of devices synced
     */
    public function syncAllDevices(): int
    {
        try {
            $response = Http::get("{$this->nbiUrl}/devices");

            if (!$response->successful()) {
                return 0;
            }

            $devices = $response->json() ?? [];
            $synced = 0;

            foreach ($devices as $deviceData) {
                try {
                    $this->syncDeviceFromGenieACS($deviceData);
                    $synced++;
                } catch (\Exception $e) {
                    Log::warning('Failed to sync device', [
                        'device_id' => $deviceData['_id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('GenieACS devices synced', ['count' => $synced]);
            return $synced;

        } catch (\Exception $e) {
            Log::error('GenieACS Sync All Devices Exception', [
                'message' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Reboot a device
     * 
     * @param string $deviceId
     * @return GenieacsTask|null
     */
    public function rebootDevice(string $deviceId): ?GenieacsTask
    {
        return $this->createTask($deviceId, GenieacsTask::TASK_REBOOT);
    }

    /**
     * Create a task for a device
     * 
     * @param string $deviceId
     * @param string $taskName
     * @param array $parameters
     * @return GenieacsTask|null
     */
    public function createTask(string $deviceId, string $taskName, array $parameters = []): ?GenieacsTask
    {
        try {
            // Find device in database
            $device = GenieacsDevice::where('device_id', $deviceId)->first();
            if (!$device) {
                Log::warning('Device not found in database', ['device_id' => $deviceId]);
                return null;
            }

            // Create task record
            $task = GenieacsTask::create([
                'genieacs_device_id' => $device->id,
                'device_id' => $deviceId,
                'task_name' => $taskName,
                'parameters' => $parameters,
                'status' => GenieacsTask::STATUS_PENDING,
            ]);

            // Send to GenieACS
            $payload = array_merge(['name' => $taskName], $parameters);
            $response = Http::post(
                "{$this->nbiUrl}/devices/{$deviceId}/tasks?timeout=3000&connection_request",
                $payload
            );
            
            if ($response->successful()) {
                $task->update([
                    'status' => GenieacsTask::STATUS_RUNNING,
                    'started_at' => now()
                ]);
                
                Log::info('GenieACS task created', [
                    'device_id' => $deviceId,
                    'task_name' => $taskName
                ]);
            } else {
                $task->update([
                    'status' => GenieacsTask::STATUS_FAILED,
                    'error_message' => "Failed to create task: {$response->status()} - {$response->body()}"
                ]);
                
                Log::error('Failed to create GenieACS task', [
                    'device_id' => $deviceId,
                    'task_name' => $taskName,
                    'status' => $response->status()
                ]);
            }
            
            return $task;

        } catch (\Exception $e) {
            Log::error('GenieACS Create Task Exception', [
                'message' => $e->getMessage(),
                'device_id' => $deviceId,
                'task_name' => $taskName
            ]);
            return null;
        }
    }

    /**
     * Factory Reset a device
     * 
     * @param string $deviceId
     * @return GenieacsTask|null
     */
    public function factoryResetDevice(string $deviceId): ?GenieacsTask
    {
        return $this->createTask($deviceId, GenieacsTask::TASK_FACTORY_RESET);
    }

    /**
     * Push firmware to device
     * 
     * @param string $deviceId
     * @param string $fileName
     * @return GenieacsTask|null
     */
    public function pushFirmware(string $deviceId, string $fileName): ?GenieacsTask
    {
        return $this->createTask($deviceId, GenieacsTask::TASK_DOWNLOAD, [
            'fileType' => '1 Firmware Upgrade Image',
            'fileName' => $fileName
        ]);
    }

    /**
     * Set parameter values on device
     * 
     * @param string $deviceId
     * @param array $parameters Key-value pairs of parameters to set
     * @return GenieacsTask|null
     */
    public function setParameterValues(string $deviceId, array $parameters): ?GenieacsTask
    {
        return $this->createTask($deviceId, GenieacsTask::TASK_SET_PARAMETER_VALUES, [
            'parameterValues' => $parameters
        ]);
    }

    /**
     * Get parameter values from device
     * 
     * @param string $deviceId
     * @param array $parameterNames
     * @return GenieacsTask|null
     */
    public function getParameterValues(string $deviceId, array $parameterNames): ?GenieacsTask
    {
        return $this->createTask($deviceId, GenieacsTask::TASK_GET_PARAMETER_VALUES, [
            'parameterNames' => $parameterNames
        ]);
    }

    /**
     * Delete a device from GenieACS
     * 
     * @param string $deviceId
     * @return bool
     */
    public function deleteDevice(string $deviceId): bool
    {
        try {
            $response = Http::delete("{$this->nbiUrl}/devices/{$deviceId}");
            
            if ($response->successful()) {
                // Also delete from database
                GenieacsDevice::where('device_id', $deviceId)->delete();
                
                Log::info('Device deleted from GenieACS', ['device_id' => $deviceId]);
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('GenieACS Delete Device Exception', [
                'message' => $e->getMessage(),
                'device_id' => $deviceId
            ]);
            return false;
        }
    }
}
