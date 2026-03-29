# GenieACS Integration Guide - Robust Router Management

## Why GenieACS for MikroTik Management?

### Problems with Direct API Approach
1. **Requires VPN connectivity** - If VPN fails, management fails
2. **Server must initiate connection** - Doesn't work behind NAT
3. **Timeout issues** - Network latency causes failures
4. **No auto-recovery** - Manual intervention needed
5. **Single point of failure** - API down = no management

### TR-069/GenieACS Advantages
1. **Router initiates connection** - Works behind NAT/firewalls
2. **Periodic inform** - Automatic health checks (every 60s)
3. **Auto-provisioning** - Zero-touch deployment
4. **Firmware management** - Remote updates
5. **Configuration backup** - Automatic versioning
6. **Event-driven** - Router notifies server of changes
7. **Industry standard** - Battle-tested at ISP scale

---

## Architecture Overview

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────────┐
│  MikroTik       │         │   GenieACS       │         │   Laravel       │
│  Router         │◄───────►│   ACS Server     │◄───────►│   Backend       │
│                 │  TR-069 │                  │   API   │                 │
│  - TR-069 Client│         │  - Port 7547     │         │  - Provisioning │
│  - Auto Config  │         │  - MongoDB       │         │  - Monitoring   │
│  - Periodic     │         │  - Web UI        │         │  - Billing      │
│    Inform       │         │                  │         │                 │
└─────────────────┘         └──────────────────┘         └─────────────────┘
```

---

## GenieACS Setup (Already in Docker Compose)

### 1. Enable GenieACS Services

Your `docker-compose.production.yml` should include:

```yaml
services:
  genieacs:
    image: drumsergio/genieacs:latest
    container_name: wificore-genieacs
    ports:
      - "7547:7547"   # TR-069 ACS
      - "7557:7557"   # File server
      - "7567:7567"   # Web UI
    environment:
      - GENIEACS_CWMP_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-cwmp-access.log
      - GENIEACS_NBI_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-nbi-access.log
      - GENIEACS_FS_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-fs-access.log
      - GENIEACS_UI_JWT_SECRET=your-secret-key-here
      - GENIEACS_MONGODB_CONNECTION_URL=mongodb://wificore-mongo:27017/genieacs
    depends_on:
      - mongo
    networks:
      - wificore-network
    restart: unless-stopped

  mongo:
    image: mongo:7
    container_name: wificore-mongo
    volumes:
      - mongo_data:/data/db
    networks:
      - wificore-network
    restart: unless-stopped

volumes:
  mongo_data:
```

### 2. Start GenieACS

```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml up -d genieacs mongo
```

### 3. Access GenieACS UI

```
http://your-server-ip:7567
```

---

## MikroTik TR-069 Configuration

### Auto-Configuration Script

Add this to your router provisioning script:

```routeros
# Enable TR-069 Client
/tr069-client
set enabled=yes \
    acs-url="http://your-server-ip:7547" \
    username="" \
    password="" \
    periodic-inform-enabled=yes \
    periodic-inform-interval=60

# Optional: Set device info
/system identity
set name="{{router_name}}"

/system note
set note="Tenant: {{tenant_name}} | ID: {{router_id}}"
```

### Manual Configuration (for testing)

```routeros
/tr069-client
set enabled=yes acs-url=http://144.91.71.208:7547
set periodic-inform-enabled=yes periodic-inform-interval=60
```

---

## Laravel Integration

### 1. GenieACS Service Class

Create `app/Services/GenieAcsService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenieAcsService
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl = config('services.genieacs.url', 'http://wificore-genieacs:7557');
        $this->username = config('services.genieacs.username', '');
        $this->password = config('services.genieacs.password', '');
    }

    /**
     * Get device by serial number or ID
     */
    public function getDevice(string $deviceId): ?array
    {
        try {
            $response = Http::get("{$this->baseUrl}/devices/{$deviceId}");
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('GenieACS: Failed to get device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get all devices
     */
    public function getAllDevices(): array
    {
        try {
            $response = Http::get("{$this->baseUrl}/devices");
            return $response->successful() ? $response->json() : [];
        } catch (\Exception $e) {
            Log::error('GenieACS: Failed to get devices', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Refresh device data (trigger inform)
     */
    public function refreshDevice(string $deviceId): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/devices/{$deviceId}/tasks", [
                'name' => 'refreshObject',
                'objectName' => 'InternetGatewayDevice.'
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS: Failed to refresh device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Set parameter value on device
     */
    public function setParameter(string $deviceId, string $parameter, $value): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/devices/{$deviceId}/tasks", [
                'name' => 'setParameterValues',
                'parameterValues' => [
                    [$parameter, $value, 'xsd:string']
                ]
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS: Failed to set parameter', [
                'device_id' => $deviceId,
                'parameter' => $parameter,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Reboot device
     */
    public function rebootDevice(string $deviceId): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/devices/{$deviceId}/tasks", [
                'name' => 'reboot'
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS: Failed to reboot device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get device uptime and status
     */
    public function getDeviceStatus(string $deviceId): array
    {
        $device = $this->getDevice($deviceId);
        
        if (!$device) {
            return [
                'online' => false,
                'uptime' => null,
                'last_inform' => null
            ];
        }
        
        return [
            'online' => isset($device['_lastInform']) && 
                       (time() - strtotime($device['_lastInform'])) < 120,
            'uptime' => $device['InternetGatewayDevice.DeviceInfo.UpTime']['_value'] ?? null,
            'last_inform' => $device['_lastInform'] ?? null,
            'software_version' => $device['InternetGatewayDevice.DeviceInfo.SoftwareVersion']['_value'] ?? null,
            'hardware_version' => $device['InternetGatewayDevice.DeviceInfo.HardwareVersion']['_value'] ?? null,
        ];
    }

    /**
     * Download configuration file to device
     */
    public function downloadFile(string $deviceId, string $fileUrl, string $fileType = '1 Firmware Upgrade Image'): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/devices/{$deviceId}/tasks", [
                'name' => 'download',
                'fileType' => $fileType,
                'fileName' => basename($fileUrl),
                'url' => $fileUrl
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('GenieACS: Failed to download file', [
                'device_id' => $deviceId,
                'file_url' => $fileUrl,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
```

### 2. Configuration

Add to `config/services.php`:

```php
'genieacs' => [
    'url' => env('GENIEACS_URL', 'http://wificore-genieacs:7557'),
    'username' => env('GENIEACS_USERNAME', ''),
    'password' => env('GENIEACS_PASSWORD', ''),
],
```

Add to `.env.production`:

```bash
GENIEACS_URL=http://wificore-genieacs:7557
GENIEACS_USERNAME=
GENIEACS_PASSWORD=
```

### 3. Hybrid Monitoring Job

Create `app/Jobs/MonitorRoutersViaGenieACS.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\GenieAcsService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorRoutersViaGenieACS implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(
        public string $tenantId
    ) {}

    public function handle(GenieAcsService $genieacs): void
    {
        $this->executeInTenantContext(function () use ($genieacs) {
            $routers = Router::where('management_method', 'tr069')
                ->orWhere('management_method', 'hybrid')
                ->get();

            foreach ($routers as $router) {
                try {
                    // Use serial number or MAC as device ID
                    $deviceId = $router->serial_number ?? $router->mac_address;
                    
                    if (!$deviceId) {
                        Log::warning('Router has no serial/MAC for TR-069', [
                            'router_id' => $router->id
                        ]);
                        continue;
                    }

                    $status = $genieacs->getDeviceStatus($deviceId);
                    
                    $router->update([
                        'status' => $status['online'] ? 'online' : 'offline',
                        'last_seen' => $status['last_inform'] ?? now(),
                        'uptime' => $status['uptime'],
                        'os_version' => $status['software_version'],
                    ]);

                    Log::info('Router status updated via GenieACS', [
                        'router_id' => $router->id,
                        'status' => $status['online'] ? 'online' : 'offline',
                        'last_inform' => $status['last_inform']
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to monitor router via GenieACS', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });
    }
}
```

---

## Migration: Add Management Method Column

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->enum('management_method', ['api', 'tr069', 'hybrid', 'snmp'])
                  ->default('api')
                  ->after('status');
            $table->string('serial_number')->nullable()->after('mac_address');
            $table->timestamp('last_inform')->nullable()->after('last_seen');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['management_method', 'serial_number', 'last_inform']);
        });
    }
};
```

---

## Hybrid Approach (Recommended)

Use TR-069 for monitoring and API for configuration:

```php
class RouterManagementService
{
    public function __construct(
        protected GenieAcsService $genieacs,
        protected MikrotikProvisioningService $mikrotik
    ) {}

    public function getRouterStatus(Router $router): array
    {
        // Try TR-069 first (more reliable)
        if (in_array($router->management_method, ['tr069', 'hybrid'])) {
            $status = $this->genieacs->getDeviceStatus($router->serial_number);
            if ($status['online']) {
                return $status;
            }
        }

        // Fallback to API
        if (in_array($router->management_method, ['api', 'hybrid'])) {
            return $this->mikrotik->verifyConnectivity($router);
        }

        return ['online' => false];
    }

    public function configureRouter(Router $router, array $config): bool
    {
        // Use API for configuration (more flexible)
        return $this->mikrotik->deployConfiguration($router, $config);
    }
}
```

---

## Comparison Table

| Feature | MikroTik API | TR-069/GenieACS | SNMP |
|---------|-------------|-----------------|------|
| **Reliability** | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ |
| **NAT Traversal** | ❌ Needs VPN | ✅ Native | ⚠️ Limited |
| **Auto-Discovery** | ❌ Manual | ✅ Automatic | ⚠️ Scan needed |
| **Configuration** | ✅ Full control | ✅ Full control | ❌ Read-only |
| **Monitoring** | ✅ Real-time | ✅ Periodic | ✅ Real-time |
| **Firmware Updates** | ✅ Yes | ✅ Yes | ❌ No |
| **Industry Standard** | ❌ Vendor-specific | ✅ Yes (ISP) | ✅ Yes (Enterprise) |
| **Learning Curve** | Medium | High | Low |
| **Resource Usage** | Low | Medium | Very Low |

---

## Recommended Implementation Strategy

### Phase 1: Parallel Running (Week 1-2)
- Keep existing API-based system
- Deploy GenieACS alongside
- Configure new routers with TR-069
- Monitor both systems

### Phase 2: Hybrid Mode (Week 3-4)
- Use TR-069 for monitoring (health checks)
- Use API for configuration (when needed)
- Compare reliability metrics

### Phase 3: Full Migration (Week 5+)
- Migrate existing routers to TR-069
- Keep API as fallback
- Deprecate VPN requirement for management

---

## Benefits for Your Use Case

1. **No VPN Required**: Routers behind NAT can still be managed
2. **Auto-Recovery**: Router reconnects automatically
3. **Scalability**: Handle 1000+ routers easily
4. **Zero-Touch**: New routers auto-configure
5. **Reliability**: Industry-proven solution
6. **Monitoring**: Know immediately when router goes offline

---

## Next Steps

1. ✅ Deploy GenieACS container
2. ✅ Test with one MikroTik router
3. ✅ Implement GenieAcsService class
4. ✅ Add migration for management_method column
5. ✅ Create hybrid monitoring job
6. ✅ Update provisioning script to enable TR-069
7. ✅ Monitor and compare with API approach

**This is the industry-standard solution used by major ISPs worldwide. It's battle-tested and will solve your reliability issues.**
