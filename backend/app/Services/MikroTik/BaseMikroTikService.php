<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Base MikroTik Service
 * 
 * Provides common utilities and helper methods for all MikroTik services
 */
abstract class BaseMikroTikService extends TenantAwareService
{
    /**
     * Escape special characters for RouterOS scripts
     */
    protected function escapeRouterOsString(string $string): string
    {
        $string = str_replace(["\r\n", "\r", "\n"], '\r\n', $string);
        $string = str_replace(['\\', '"', ';', '{', '}', '$'], ['\\\\', '\"', '\;', '\{', '\}', '\$'], $string);
        return $string;
    }
    
    /**
     * Validate IP pool format (e.g., "192.168.88.10-192.168.88.254")
     */
    protected function validateIpPool(string $pool): string
    {
        if (!preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})-(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $pool, $matches)) {
            throw new \Exception("Invalid IP pool format: $pool");
        }

        $startIp = $matches[1];
        $endIp = $matches[2];

        if (!filter_var($startIp, FILTER_VALIDATE_IP) || !filter_var($endIp, FILTER_VALIDATE_IP)) {
            throw new \Exception("Invalid IP addresses in pool: $pool");
        }

        $startParts = explode('.', $startIp);
        $endParts = explode('.', $endIp);

        if ($startParts[0] !== $endParts[0] || $startParts[1] !== $endParts[1] || $startParts[2] !== $endParts[2]) {
            throw new \Exception("IP pool start and end addresses must be in the same subnet: $pool");
        }

        $startLast = (int) $startParts[3];
        $endLast = (int) $endParts[3];

        if ($startLast >= $endLast || $startLast < 1 || $endLast > 254) {
            throw new \Exception("Invalid IP pool range: $pool (start must be less than end, within 1-254)");
        }

        return $pool;
    }
    
    /**
     * Extract network address from IP pool
     */
    protected function getNetworkFromPool(string $pool): string
    {
        $parts = explode('-', $pool);
        $ipParts = explode('.', $parts[0]);
        return "{$ipParts[0]}.{$ipParts[1]}.{$ipParts[2]}.0/24";
    }
    
    /**
     * Extract gateway from IP pool (uses .1 of the network)
     */
    protected function getGatewayFromPool(string $pool): string
    {
        $parts = explode('-', $pool);
        $ipParts = explode('.', $parts[0]);
        return "{$ipParts[0]}.{$ipParts[1]}.{$ipParts[2]}.1";
    }
    
    /**
     * Generate random IP pool in a given subnet
     */
    protected function generateRandomPool(string $prefix = '192.168'): string
    {
        $thirdOctet = rand(10, 250);
        $start = rand(10, 200);
        $end = min($start + 50, 254);
        return "$prefix.$thirdOctet.$start-$prefix.$thirdOctet.$end";
    }
    
    /**
     * Create interface lists (LAN/WAN)
     */
    protected function createInterfaceLists(): array
    {
        return [
            '# Interface Lists',
            ':if ([/interface list find name="LAN"] = "") do={',
            '  /interface list add name=LAN comment="Local Area Network"',
            '}',
            ':if ([/interface list find name="WAN"] = "") do={',
            '  /interface list add name=WAN comment="Wide Area Network"',
            '}',
            '',
            '# Add ether1 to WAN by default',
            ':if ([/interface list member find list=WAN interface=ether1] = "") do={',
            '  /interface list member add list=WAN interface=ether1',
            '}',
            '',
        ];
    }
    
    /**
     * Configure DNS servers
     */
    protected function configureDNS(string $dnsServers): array
    {
        return [
            '# DNS Configuration',
            "/ip dns set allow-remote-requests=yes servers=\"$dnsServers\"",
            '',
        ];
    }
    
    /**
     * Configure NAT masquerade for WAN
     */
    protected function configureMasquerade(): array
    {
        return [
            '# NAT Masquerade',
            ':local existingMasq [/ip firewall nat find chain=srcnat action=masquerade out-interface-list=WAN]',
            ':if ([:len $existingMasq] > 0) do={',
            '  /ip firewall nat remove $existingMasq',
            '}',
            '/ip firewall nat add chain=srcnat out-interface-list=WAN action=masquerade comment="Internet access for services"',
            '',
        ];
    }
    
    /**
     * Configure RADIUS server
     */
    protected function configureRADIUS(string $radiusIp, string $radiusSecret, string $service = 'hotspot,ppp'): array
    {
        return [
            '# RADIUS Configuration',
            ":local radiusServer \"$radiusIp\"",
            ":local radiusSecret \"$radiusSecret\"",
            '',
            '# Check for environment variable override',
            ':if ([:len [/system/script/environment get RADIUS_SERVER]] > 0) do={',
            '  :set radiusServer [/system/script/environment get RADIUS_SERVER]',
            '}',
            '',
            ':local existingRadius [/radius find address=$radiusServer]',
            ':if ([:len $existingRadius] > 0) do={',
            '  /radius remove $existingRadius',
            '}',
            '',
            "/radius add address=\$radiusServer secret=\$radiusSecret service=$service timeout=3s",
            ':log info "Configured RADIUS server: $radiusServer"',
            '',
        ];
    }
    
    /**
     * Log configuration step
     */
    protected function logStep(string $step, array $context = []): void
    {
        Log::info($step, array_merge(['service' => static::class], $context));
    }
    
    /**
     * Generate script header
     */
    protected function generateHeader(string $title, string $routerId): array
    {
        return [
            '# ========================================',
            "# $title",
            '# Generated for Router ID: ' . $routerId,
            '# Generated at: ' . date('Y-m-d H:i:s'),
            '# Service: ' . class_basename(static::class),
            '# ========================================',
            '',
        ];
    }
    
    /**
     * Generate script footer
     */
    protected function generateFooter(): array
    {
        return [
            '',
            '# ========================================',
            '# Configuration Complete',
            '# ========================================',
            ':log info "Configuration applied successfully"',
        ];
    }
    
    /**
     * Validate interface name
     */
    protected function validateInterface(string $interface): string
    {
        // Remove any dangerous characters
        $interface = preg_replace('/[^a-zA-Z0-9\-_]/', '', $interface);
        
        if (empty($interface)) {
            throw new \Exception('Invalid interface name');
        }
        
        return $interface;
    }
    
    /**
     * Check if interface exists on router (script-based check)
     */
    protected function generateInterfaceCheck(string $interface): array
    {
        $safeInterface = $this->validateInterface($interface);
        
        return [
            ":local iface \"$safeInterface\"",
            ':local ifaceExists [/interface find name=$iface]',
            ':if ([:len $ifaceExists] = 0) do={',
            '  :log error "Interface $iface does not exist"',
            '  :error "Interface $iface does not exist"',
            '}',
        ];
    }
}
