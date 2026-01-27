<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class MikrotikSnmpService
{
    public function fetchLiveData(Router $router, bool $includeInterfaces = false): array
    {
        if (!function_exists('snmp2_get')) {
            throw new \Exception('SNMP extension is not available', 500);
        }

        if ($router->snmp_enabled === false) {
            throw new \Exception('SNMP is disabled for this router', 422);
        }

        $ipRaw = (string) ($router->vpn_ip ?: $router->ip_address);
        $ip = trim(explode('/', $ipRaw, 2)[0]);
        if (!$ip) {
            throw new \Exception('Router IP is missing', 422);
        }

        $host = 'udp:' . $ip . ':' . (int) env('MIKROTIK_SNMP_PORT', 161);
        $community = (string) env('MIKROTIK_SNMP_COMMUNITY', 'public');
        $timeoutSeconds = (int) env('MIKROTIK_SNMP_TIMEOUT', 2);
        $retries = (int) env('MIKROTIK_SNMP_RETRIES', 1);

        $versionRaw = strtolower((string) ($router->snmp_version ?? '2c'));
        $version = match (true) {
            $versionRaw === '3' || $versionRaw === 'v3' || $versionRaw === 'snmpv3' => '3',
            $versionRaw === '1' || $versionRaw === 'v1' => '1',
            default => '2c',
        };

        $snmpV3User = $router->snmp_v3_user ? (string) $router->snmp_v3_user : '';
        $snmpV3AuthProtocol = $router->snmp_v3_auth_protocol ? strtoupper((string) $router->snmp_v3_auth_protocol) : '';
        $snmpV3AuthPassword = $router->snmp_v3_auth_password ? (string) $router->snmp_v3_auth_password : '';
        $snmpV3PrivProtocol = $router->snmp_v3_priv_protocol ? strtoupper((string) $router->snmp_v3_priv_protocol) : '';
        $snmpV3PrivPassword = $router->snmp_v3_priv_password ? (string) $router->snmp_v3_priv_password : '';

        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        snmp_set_oid_numeric_print(true);

        try {
            $identity = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.2.1.1.5.0', $timeoutSeconds, $retries);
            $sysDescr = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.2.1.1.1.0', $timeoutSeconds, $retries);
            $uptimeTicks = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.2.1.1.3.0', $timeoutSeconds, $retries);

            $cpuLoad = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.4.1.14988.1.1.3.10.0', $timeoutSeconds, $retries);
            $totalMemory = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.4.1.14988.1.1.3.7.0', $timeoutSeconds, $retries);
            $freeMemory = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.4.1.14988.1.1.3.8.0', $timeoutSeconds, $retries);

            $interfacesCount = $this->snmpGetPlain($version, $host, $community, $snmpV3User, $snmpV3AuthProtocol, $snmpV3AuthPassword, $snmpV3PrivProtocol, $snmpV3PrivPassword, '1.3.6.1.2.1.2.1.0', $timeoutSeconds, $retries);
            $parsed = $this->parseSysDescr($sysDescr);

            return [
                'status' => 'online',
                'board_name' => $parsed['board_name'] ?? ($router->model ?? null),
                'version' => $parsed['version'] ?? null,
                'identity' => $identity,
                'uptime' => $this->formatUptimeFromTimeticks($uptimeTicks),
                'cpu_load' => $this->toIntOrNull($cpuLoad),
                'total_memory' => $this->toIntOrNull($totalMemory),
                'free_memory' => $this->toIntOrNull($freeMemory),
                'interfaces_count' => $this->toIntOrNull($interfacesCount),
                'interface_count' => $this->toIntOrNull($interfacesCount),
                'hotspot_active' => null,
                'pppoe_active' => null,
                'active_connections' => null,
                'dhcp_leases' => null,
                'interfaces' => $includeInterfaces ? [] : [],
                'last_updated' => now()->toDateTimeString(),
                'source' => 'snmp',
            ];
        } catch (\Exception $e) {
            Log::warning('SNMP live data fetch failed', [
                'router_id' => $router->id,
                'ip' => $ip,
                'snmp_enabled' => $router->snmp_enabled,
                'snmp_version' => $router->snmp_version,
                'snmp_port' => (int) env('MIKROTIK_SNMP_PORT', 161),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function snmpGetPlain(
        string $version,
        string $host,
        string $community,
        string $snmpV3User,
        string $snmpV3AuthProtocol,
        string $snmpV3AuthPassword,
        string $snmpV3PrivProtocol,
        string $snmpV3PrivPassword,
        string $oid,
        int $timeoutSeconds,
        int $retries
    ): ?string
    {
        $timeoutMicros = max(1, $timeoutSeconds) * 1000000;

        if ($version === '3') {
            if (!function_exists('snmp3_get')) {
                throw new \Exception('SNMPv3 functions are not available', 500);
            }

            if ($snmpV3User === '') {
                throw new \Exception('SNMPv3 user is not configured for this router', 422);
            }

            $authProto = $snmpV3AuthProtocol !== '' ? $snmpV3AuthProtocol : 'MD5';
            $privProto = $snmpV3PrivProtocol !== '' ? $snmpV3PrivProtocol : 'DES';

            $hasAuth = $snmpV3AuthPassword !== '';
            $hasPriv = $snmpV3PrivPassword !== '';
            $secLevel = $hasAuth && $hasPriv ? 'authPriv' : ($hasAuth ? 'authNoPriv' : 'noAuthNoPriv');

            $value = @snmp3_get(
                $host,
                $snmpV3User,
                $secLevel,
                $authProto,
                $snmpV3AuthPassword,
                $privProto,
                $snmpV3PrivPassword,
                $oid,
                $timeoutMicros,
                $retries
            );
        } elseif ($version === '1') {
            if (!function_exists('snmpget')) {
                throw new \Exception('SNMPv1 functions are not available', 500);
            }

            $value = @snmpget($host, $community, $oid, $timeoutMicros, $retries);
        } else {
            $value = @snmp2_get($host, $community, $oid, $timeoutMicros, $retries);
        }

        if ($value === false) {
            throw new \Exception('SNMP request failed', 503);
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function toIntOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/-?\d+/', $value, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    private function formatUptimeFromTimeticks(?string $timeticks): ?string
    {
        if ($timeticks === null) {
            return null;
        }

        if (preg_match('/(\d+)/', $timeticks, $m)) {
            $ticks = (int) $m[1];
            $seconds = (int) floor($ticks / 100);

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;

            if ($days > 0) {
                return $days . 'd ' . $hours . 'h';
            }

            if ($hours > 0) {
                return $hours . 'h ' . $minutes . 'm';
            }

            return $minutes . 'm ' . $secs . 's';
        }

        return $timeticks;
    }

    private function parseSysDescr(?string $sysDescr): array
    {
        if ($sysDescr === null) {
            return [];
        }

        $result = [];

        if (preg_match('/RouterOS\s+([0-9]+(?:\.[0-9]+)+)/i', $sysDescr, $m)) {
            $result['version'] = $m[1];
        }

        if (preg_match('/\bon\s+(.+)$/i', $sysDescr, $m)) {
            $result['board_name'] = trim($m[1]);
        }

        return $result;
    }
}
