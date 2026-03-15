<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\MikrotikSshService;
use Illuminate\Support\Facades\Log;

class SnmpConfigurationService
{
    protected MikrotikSshService $sshService;

    public function __construct(MikrotikSshService $sshService)
    {
        $this->sshService = $sshService;
    }

    /**
     * Enable SNMP on a MikroTik router (defaults to SNMPv2c).
     *
     * If SNMPv3 credentials are provided in $options, configures v3.
     * Otherwise, enables SNMPv2c with the community string.
     */
    public function enableSnmp(Router $router, array $options = []): array
    {
        $version = $options['version'] ?? '2c';

        if ($version === '3' || $version === 'v3') {
            return $this->enableSnmpV3($router, $options);
        }

        return $this->enableSnmpV2c($router, $options);
    }

    /**
     * Enable SNMPv2c on a MikroTik router (preserves configured community).
     */
    public function enableSnmpV2c(Router $router, array $options = []): array
    {
        $community = $options['community'] ?? config('telegraf.snmp_community', 'traidnet-monitor');
        $contact = $options['contact'] ?? 'Network Admin';
        $location = $options['location'] ?? $router->location ?? 'Unknown';
        // Always allow monitoring from the VPN server IP (10.8.0.1/32)
        // We do NOT use the tenant's VPN subnet here because in Host Mode, the server (10.8.0.1) 
        // is outside the tenant's allocated range (e.g. 10.100.0.0/16).
        $snmpSubnet = $options['snmp_subnet'] ?? '10.8.0.1/32';

        $commands = [
            '/snmp set enabled=yes',
            "/snmp set contact=\"{$contact}\"",
            "/snmp set location=\"{$location}\"",
            // Ensure community exists (idempotent: remove then re-add)
            ":do { /snmp community remove [find name=\"{$community}\"]; } on-error={} ",
            "/snmp community add name=\"{$community}\" addresses={$snmpSubnet} security=none read-access=yes write-access=no",
            "/snmp set trap-community=\"{$community}\" trap-version=2",
        ];

        try {
            $results = [];
            foreach ($commands as $command) {
                $result = $this->sshService->executeCommand($router, $command);
                $results[] = [
                    'command' => $command,
                    'output' => $result,
                ];
            }

            $router->update([
                'snmp_enabled' => true,
                'snmp_version' => '2c',
                'snmp_community' => $community,
            ]);

            Log::info('SNMPv2c enabled successfully', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'community' => $community,
            ]);

            return [
                'success' => true,
                'message' => 'SNMPv2c enabled successfully',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to enable SNMPv2c', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Configure SNMPv3 on a MikroTik router
     */
    public function enableSnmpV3(Router $router, array $credentials): array
    {
        $user = $credentials['user'] ?? config('telegraf.snmpv3_user', 'snmpmonitor');
        $authProtocol = strtolower($credentials['auth_protocol'] ?? 'SHA256');
        $authPassword = $credentials['auth_password'] ?? config('telegraf.snmpv3_auth_password', '');
        $privProtocol = strtolower($credentials['priv_protocol'] ?? 'AES');
        $privPassword = $credentials['priv_password'] ?? config('telegraf.snmpv3_priv_password', '');
        $contact = $credentials['contact'] ?? 'Network Admin';
        $location = $credentials['location'] ?? $router->location ?? 'Unknown';
        $snmpSubnet = $credentials['snmp_subnet'] ?? '10.8.0.1/32';

        if (empty($authPassword) || empty($privPassword)) {
            throw new \InvalidArgumentException('SNMPv3 requires both auth and priv passwords');
        }

        $commands = [
            '/snmp set enabled=yes',
            "/snmp set contact=\"{$contact}\"",
            "/snmp set location=\"{$location}\"",
            // Remove existing v3 user before adding (idempotent)
            ":do { /snmp community remove [find name={$user}]; } on-error={}",
            // Add SNMPv3 user
            "/snmp community add name={$user} addresses={$snmpSubnet} security=private " .
            "authentication-protocol={$authProtocol} authentication-password=\"{$authPassword}\" " .
            "encryption-protocol={$privProtocol} encryption-password=\"{$privPassword}\"",
        ];

        try {
            $results = [];
            foreach ($commands as $command) {
                $result = $this->sshService->executeCommand($router, $command);
                $results[] = [
                    'command' => $command,
                    'output' => $result,
                ];
            }

            $router->update([
                'snmp_enabled' => true,
                'snmp_version' => 'v3',
                'snmp_v3_user' => $user,
                'snmp_v3_auth_protocol' => strtoupper($authProtocol),
                'snmp_v3_auth_password' => $authPassword,
                'snmp_v3_priv_protocol' => strtoupper($privProtocol),
                'snmp_v3_priv_password' => $privPassword,
            ]);

            Log::info('SNMPv3 enabled successfully', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'user' => $user,
            ]);

            return [
                'success' => true,
                'message' => 'SNMPv3 enabled successfully',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to enable SNMPv3', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify SNMP is working on the router
     */
    public function verifySnmp(Router $router): array
    {
        try {
            $result = $this->sshService->executeCommand($router, '/snmp print');
            
            $enabled = str_contains($result, 'enabled: yes');
            
            return [
                'success' => true,
                'enabled' => $enabled,
                'output' => $result,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'enabled' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get SNMPv2c configuration script for manual application on MikroTik.
     */
    public function getSnmpConfigScript(array $options = []): string
    {
        $community = $options['community'] ?? config('telegraf.snmp_community', 'traidnet-monitor');
        $contact = $options['contact'] ?? 'Network Admin';
        $location = $options['location'] ?? 'Unknown';
        $snmpSubnet = $options['snmp_subnet'] ?? '10.8.0.1/32';

        return <<<SCRIPT
# Enable SNMPv2c for monitoring
/snmp set enabled=yes
/snmp set contact="{$contact}"
/snmp set location="{$location}"
:do { /snmp community remove [find name="{$community}"]; } on-error={}
/snmp community add name="{$community}" addresses={$snmpSubnet} security=none read-access=yes write-access=no
/snmp set trap-community="{$community}" trap-version=2
SCRIPT;
    }
}
