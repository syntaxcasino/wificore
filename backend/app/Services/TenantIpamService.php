<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantIpPool;
use App\Traits\TenantAware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantIpamService extends TenantAwareService
{
    use TenantAware;

    private const DEFAULT_POOL_SIZES = [
        'hotspot' => 254,
        'pppoe' => 254,
        'management' => 62,
    ];

    private const BASE_NETWORKS = [
        'hotspot' => '192.168.100.0',
        'pppoe' => '192.168.200.0',
        'management' => '192.168.10.0',
    ];

    /**
     * Get or create IP pool for a service type
     */
    public function getOrCreateServicePool(Tenant $tenant, string $serviceType): TenantIpPool
    {
        $this->setTenant($tenant->id);

        // Try to find existing active pool
        $pool = TenantIpPool::where('tenant_id', $tenant->id)
            ->forService($serviceType)
            ->available()
            ->first();

        if ($pool) {
            // Check if pool needs expansion
            if ($pool->needsExpansion()) {
                $this->expandPool($pool);
            }
            return $pool;
        }

        // Create new pool
        return $this->createServicePool($tenant, $serviceType);
    }

    /**
     * Create a new IP pool for a service
     */
    private function createServicePool(Tenant $tenant, string $serviceType): TenantIpPool
    {
        $this->setTenant($tenant->id);

        // Find next available network
        $network = $this->findAvailableNetwork($tenant, $serviceType);
        
        $poolSize = self::DEFAULT_POOL_SIZES[$serviceType] ?? 254;
        $cidr = $this->calculateCidr($poolSize);
        
        // Calculate IP ranges
        $networkParts = explode('.', $network);
        $gatewayIp = "{$network}";
        $rangeStart = $networkParts[0] . '.' . $networkParts[1] . '.' . $networkParts[2] . '.2';
        $rangeEnd = $networkParts[0] . '.' . $networkParts[1] . '.' . $networkParts[2] . '.' . ($poolSize + 1);

        $pool = TenantIpPool::create([
            'tenant_id' => $tenant->id,
            'service_type' => $serviceType,
            'pool_name' => $this->generatePoolName($tenant, $serviceType),
            'network_cidr' => "{$network}/{$cidr}",
            'gateway_ip' => $gatewayIp,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
            'total_ips' => $poolSize,
            'allocated_ips' => 0,
            'available_ips' => $poolSize,
            'auto_generated' => true,
            'status' => 'active',
            'metadata' => [
                'created_by' => 'system',
                'auto_expansion_enabled' => true,
            ],
        ]);

        Log::info('Created tenant IP pool', [
            'tenant_id' => $tenant->id,
            'service_type' => $serviceType,
            'pool_id' => $pool->id,
            'network' => $pool->network_cidr,
        ]);

        return $pool;
    }

    /**
     * Find next available network for tenant
     */
    private function findAvailableNetwork(Tenant $tenant, string $serviceType): string
    {
        $this->setTenant($tenant->id);

        $baseNetwork = self::BASE_NETWORKS[$serviceType];
        $baseParts = explode('.', $baseNetwork);
        
        // Get existing pools for this service type
        $existingPools = TenantIpPool::where('tenant_id', $tenant->id)
            ->forService($serviceType)
            ->pluck('network_cidr')
            ->toArray();

        // Find next available third octet
        $thirdOctet = (int)$baseParts[2];
        
        for ($i = 0; $i < 255; $i++) {
            $testNetwork = "{$baseParts[0]}.{$baseParts[1]}.{$thirdOctet}.0";
            $testCidr = "{$testNetwork}/24";
            
            if (!in_array($testCidr, $existingPools)) {
                return $testNetwork;
            }
            
            $thirdOctet++;
            if ($thirdOctet > 255) {
                $thirdOctet = 0;
            }
        }

        throw new \Exception("No available networks for service type: {$serviceType}");
    }

    /**
     * Expand an existing pool
     */
    public function expandPool(TenantIpPool $pool): void
    {
        if (!($pool->metadata['auto_expansion_enabled'] ?? true)) {
            Log::warning('Pool expansion disabled', ['pool_id' => $pool->id]);
            return;
        }

        // Double the pool size
        $newTotalIps = $pool->total_ips * 2;
        
        if ($newTotalIps > 65534) {
            Log::error('Pool expansion limit reached', ['pool_id' => $pool->id]);
            return;
        }

        // Recalculate range
        $networkParts = explode('.', explode('/', $pool->network_cidr)[0]);
        $newRangeEnd = $networkParts[0] . '.' . $networkParts[1] . '.' . $networkParts[2] . '.' . ($newTotalIps + 1);

        $pool->update([
            'range_end' => $newRangeEnd,
            'total_ips' => $newTotalIps,
            'available_ips' => $pool->available_ips + $pool->total_ips,
            'status' => 'active',
        ]);

        Log::info('Expanded IP pool', [
            'pool_id' => $pool->id,
            'old_size' => $pool->total_ips / 2,
            'new_size' => $newTotalIps,
        ]);
    }

    /**
     * Allocate IP from pool
     */
    public function allocateIpFromPool(TenantIpPool $pool): ?string
    {
        if ($pool->isExhausted()) {
            if ($pool->metadata['auto_expansion_enabled'] ?? true) {
                $this->expandPool($pool);
                $pool->refresh();
            } else {
                return null;
            }
        }

        // Simple allocation - just track count
        // Actual IP assignment handled by RADIUS
        $pool->allocateIp();

        return $this->getNextAvailableIp($pool);
    }

    /**
     * Release IP back to pool
     */
    public function releaseIp(TenantIpPool $pool, string $ip): void
    {
        $pool->releaseIp();

        Log::info('Released IP from pool', [
            'pool_id' => $pool->id,
            'ip' => $ip,
        ]);
    }

    /**
     * Validate pool capacity
     */
    public function validatePoolCapacity(TenantIpPool $pool, int $requiredIps): bool
    {
        return $pool->available_ips >= $requiredIps;
    }

    /**
     * Get pool utilization stats
     */
    public function getPoolStats(Tenant $tenant): array
    {
        $this->setTenant($tenant->id);

        $pools = TenantIpPool::where('tenant_id', $tenant->id)->get();

        return [
            'total_pools' => $pools->count(),
            'total_ips' => $pools->sum('total_ips'),
            'allocated_ips' => $pools->sum('allocated_ips'),
            'available_ips' => $pools->sum('available_ips'),
            'utilization_percentage' => $pools->sum('total_ips') > 0 
                ? round(($pools->sum('allocated_ips') / $pools->sum('total_ips')) * 100, 2)
                : 0,
            'pools_by_service' => $pools->groupBy('service_type')->map(function ($servicePools) {
                return [
                    'count' => $servicePools->count(),
                    'total_ips' => $servicePools->sum('total_ips'),
                    'allocated_ips' => $servicePools->sum('allocated_ips'),
                    'available_ips' => $servicePools->sum('available_ips'),
                ];
            }),
        ];
    }

    /**
     * Generate pool name
     */
    private function generatePoolName(Tenant $tenant, string $serviceType): string
    {
        $count = TenantIpPool::where('tenant_id', $tenant->id)
            ->forService($serviceType)
            ->count();

        return strtoupper($serviceType) . '-POOL-' . ($count + 1);
    }

    /**
     * Calculate CIDR from pool size
     */
    private function calculateCidr(int $poolSize): int
    {
        if ($poolSize <= 62) return 26;
        if ($poolSize <= 126) return 25;
        if ($poolSize <= 254) return 24;
        if ($poolSize <= 510) return 23;
        if ($poolSize <= 1022) return 22;
        return 21;
    }

    /**
     * Get next available IP from pool
     */
    private function getNextAvailableIp(TenantIpPool $pool): string
    {
        $rangeParts = explode('.', $pool->range_start);
        $lastOctet = (int)$rangeParts[3] + $pool->allocated_ips - 1;
        
        return "{$rangeParts[0]}.{$rangeParts[1]}.{$rangeParts[2]}.{$lastOctet}";
    }
}
