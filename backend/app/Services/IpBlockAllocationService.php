<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IpBlockAllocationService
{
    // Base network for tenant allocations
    // Each tenant gets a /16 subnet (65,536 IP addresses)
    // Block 1: 10.1.0.0/16
    // Block 2: 10.2.0.0/16
    // Block 3: 10.3.0.0/16
    // etc.
    
    private const BASE_NETWORK = '10.0.0.0';
    private const SUBNET_MASK = 16;
    private const MAX_BLOCKS = 254; // 10.1.0.0 to 10.254.0.0
    
    /**
     * Allocate unique IP block to tenant
     * 
     * @param Tenant $tenant
     * @return array IP block configuration
     * @throws \Exception if no blocks available
     */
    public function allocateTenantIpBlock(Tenant $tenant): array
    {
        // Check if tenant already has an IP block
        if (isset($tenant->settings['ip_block'])) {
            Log::info('Tenant already has IP block allocated', [
                'tenant_id' => $tenant->id,
                'ip_block' => $tenant->settings['ip_block'],
            ]);
            return $tenant->settings['ip_block'];
        }
        
        // Get next available block number
        $lastBlock = DB::table('tenants')
            ->whereNotNull('settings')
            ->whereRaw("settings::jsonb ? 'ip_block'")
            ->orderByRaw("CAST((settings::jsonb->'ip_block'->>'block_number') AS INTEGER) DESC")
            ->first();
        
        $blockNumber = $lastBlock ? 
            (int)json_decode($lastBlock->settings)->ip_block->block_number + 1 : 1;
        
        // Check if we've exceeded maximum blocks
        if ($blockNumber > self::MAX_BLOCKS) {
            throw new \Exception('No available IP blocks. Maximum tenant capacity reached.');
        }
        
        // Calculate network addresses
        // Block 1: 10.1.0.0/16 (10.1.0.0 - 10.1.255.255)
        // Block 2: 10.2.0.0/16 (10.2.0.0 - 10.2.255.255)
        $networkAddress = "10.{$blockNumber}.0.0";
        $gatewayAddress = "10.{$blockNumber}.0.1";
        $dhcpStart = "10.{$blockNumber}.0.10";
        $dhcpEnd = "10.{$blockNumber}.255.254";
        $broadcastAddress = "10.{$blockNumber}.255.255";
        
        // Calculate usable IPs
        $totalIps = 65536; // 2^16
        $usableIps = $totalIps - 2; // Minus network and broadcast
        
        $ipBlock = [
            'block_number' => $blockNumber,
            'network' => "{$networkAddress}/{self::SUBNET_MASK}",
            'network_address' => $networkAddress,
            'gateway' => $gatewayAddress,
            'dhcp_range' => "{$dhcpStart}-{$dhcpEnd}",
            'dhcp_start' => $dhcpStart,
            'dhcp_end' => $dhcpEnd,
            'broadcast' => $broadcastAddress,
            'subnet_mask' => '255.255.0.0',
            'total_ips' => $totalIps,
            'usable_ips' => $usableIps,
            'allocated_at' => now()->toIso8601String(),
        ];
        
        // Update tenant settings with IP block
        $tenant->update([
            'settings' => array_merge($tenant->settings ?? [], [
                'ip_block' => $ipBlock
            ])
        ]);
        
        Log::info('IP block allocated to tenant', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'block_number' => $blockNumber,
            'network' => $ipBlock['network'],
            'gateway' => $ipBlock['gateway'],
        ]);
        
        return $ipBlock;
    }
    
    /**
     * Get IP block for tenant
     * 
     * @param Tenant $tenant
     * @return array|null
     */
    public function getTenantIpBlock(Tenant $tenant): ?array
    {
        return $tenant->settings['ip_block'] ?? null;
    }
    
    /**
     * Check if IP block is available
     * 
     * @param int $blockNumber
     * @return bool
     */
    public function isBlockAvailable(int $blockNumber): bool
    {
        if ($blockNumber < 1 || $blockNumber > self::MAX_BLOCKS) {
            return false;
        }
        
        $exists = DB::table('tenants')
            ->whereNotNull('settings')
            ->whereRaw("settings::jsonb->'ip_block'->>'block_number' = ?", [(string)$blockNumber])
            ->exists();
        
        return !$exists;
    }
    
    /**
     * Get all allocated blocks
     * 
     * @return array
     */
    public function getAllocatedBlocks(): array
    {
        $tenants = DB::table('tenants')
            ->whereNotNull('settings')
            ->whereRaw("settings::jsonb ? 'ip_block'")
            ->select('id', 'name', 'slug', 'settings')
            ->get();
        
        $blocks = [];
        foreach ($tenants as $tenant) {
            $settings = json_decode($tenant->settings, true);
            if (isset($settings['ip_block'])) {
                $blocks[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'tenant_slug' => $tenant->slug,
                    'ip_block' => $settings['ip_block'],
                ];
            }
        }
        
        return $blocks;
    }
    
    /**
     * Get available blocks count
     * 
     * @return int
     */
    public function getAvailableBlocksCount(): int
    {
        $allocated = DB::table('tenants')
            ->whereNotNull('settings')
            ->whereRaw("settings::jsonb ? 'ip_block'")
            ->count();
        
        return self::MAX_BLOCKS - $allocated;
    }
}
