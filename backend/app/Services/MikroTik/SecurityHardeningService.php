<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class SecurityHardeningService extends TenantAwareService
{
    public function applySecurityHardening(Router $router): array
    {
        $results = [
            'success' => false,
            'applied' => [],
            'errors' => [],
        ];

        try {
            $ssh = $this->executor($router, 30);
            $wanInterface = $this->resolveWanInterface($router->wan_interface ?? null);

            Log::info('Applying security hardening', ['router_id' => $router->id]);

            $this->configureWalledGarden($ssh, $router, $results);
            $this->hardenManagementServices($ssh, $router, $results);
            $this->configureDNS($ssh, $router, $results);
            $this->disableFTP($ssh, $router, $results);
            $this->configureAdvancedFirewall($ssh, $router, $wanInterface, $results);
            $this->configureSNMP($ssh, $router, $results);

            $results['success'] = empty($results['errors']);
        } catch (\Exception $e) {
            Log::error('Security hardening failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    public function getSecurityScore(Router $router): array
    {
        $score = 0;
        $maxScore = 100;
        $checks = [];

        try {
            $ssh = $this->executor($router, 15);
            $services = $ssh->exec('/ip service print detail without-paging');
            $radius = $ssh->exec('/radius print detail without-paging');
            $firewall = $ssh->exec('/ip firewall filter print detail without-paging');
            $nat = $ssh->exec('/ip firewall nat print detail without-paging');
            $dns = $ssh->exec('/ip dns print');
            $profiles = $ssh->exec('/ip hotspot user profile print detail without-paging');
            $bridgePorts = $ssh->exec('/interface bridge port print detail without-paging');

            if ($this->serviceDisabled($services, 'ftp')) {
                $score += 10;
                $checks['ftp'] = ['status' => 'pass', 'points' => 10];
            } else {
                $checks['ftp'] = ['status' => 'fail', 'points' => 0];
            }

            if (str_contains($radius, 'service=hotspot') || str_contains($radius, 'service: hotspot')) {
                $score += 15;
                $checks['radius'] = ['status' => 'pass', 'points' => 15];
            } else {
                $checks['radius'] = ['status' => 'fail', 'points' => 0];
            }

            $hasEstablished = stripos($firewall, 'Established') !== false;
            $hasInvalid = stripos($firewall, 'Invalid') !== false;
            if ($hasEstablished && $hasInvalid) {
                $score += 20;
                $checks['firewall'] = ['status' => 'pass', 'points' => 20];
            } else {
                $score += 10;
                $checks['firewall'] = ['status' => 'partial', 'points' => 10];
            }

            if (stripos($nat, 'masquerade') !== false) {
                $score += 10;
                $checks['nat'] = ['status' => 'pass', 'points' => 10];
            } else {
                $checks['nat'] = ['status' => 'fail', 'points' => 0];
            }

            if (preg_match('/servers[:=]\s*(\S+)/i', $dns, $match) && trim($match[1]) !== '') {
                $score += 5;
                $checks['dns'] = ['status' => 'pass', 'points' => 5];
            } else {
                $checks['dns'] = ['status' => 'fail', 'points' => 0];
            }

            if (stripos($profiles, 'idle-timeout=none') === false && stripos($profiles, 'idle-timeout: none') === false) {
                $score += 10;
                $checks['session_management'] = ['status' => 'pass', 'points' => 10];
            } else {
                $checks['session_management'] = ['status' => 'fail', 'points' => 0];
            }

            if (stripos($profiles, 'rate-limit') !== false && stripos($profiles, 'rate-limit=none') === false && stripos($profiles, 'rate-limit: none') === false) {
                $score += 10;
                $checks['rate_limiting'] = ['status' => 'pass', 'points' => 10];
            } else {
                $checks['rate_limiting'] = ['status' => 'fail', 'points' => 0];
            }

            if (stripos($bridgePorts, 'interface=ether2') === false && stripos($bridgePorts, 'interface: ether2') === false) {
                $score += 20;
                $checks['management_protection'] = ['status' => 'pass', 'points' => 20];
            } else {
                $checks['management_protection'] = ['status' => 'fail', 'points' => 0];
            }
        } catch (\Exception $e) {
            Log::error('Security score calculation failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
        }

        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'checks' => $checks,
            'rating' => $this->getSecurityRating($percentage),
        ];
    }

    private function resolveWanInterface(?string $wanInterface): string
    {
        $wanInterface = trim((string) $wanInterface);
        if ($wanInterface === '' || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $wanInterface)) {
            return 'ether1';
        }

        return $wanInterface;
    }

    private function configureWalledGarden(SshExecutor $ssh, Router $router, array &$results): void
    {
        try {
            $commands = [
                ':foreach rule in=[/ip hotspot walled-garden find] do={ /ip hotspot walled-garden remove $rule }',
                ':foreach rule in=[/ip hotspot walled-garden ip find] do={ /ip hotspot walled-garden ip remove $rule }',
            ];

            $hosts = [
                '*.wificore.traidsolutions.com' => 'Captive Portal - All Tenant Subdomains',
                'wificore.traidsolutions.com' => 'Captive Portal - Main Domain',
                '*.googleapis.com' => 'Google APIs',
                '*.gstatic.com' => 'Google Static',
                '*.cloudflare.com' => 'Cloudflare CDN',
                '*.cloudfront.net' => 'AWS CloudFront',
            ];
            foreach ($hosts as $host => $comment) {
                $commands[] = sprintf('/ip hotspot walled-garden add dst-host="%s" action=allow comment="%s"', addslashes($host), addslashes($comment));
            }

            $ips = [
                '8.8.8.8' => 'Google DNS',
                '1.1.1.1' => 'Cloudflare DNS',
                '8.8.4.4' => 'Google DNS Secondary',
            ];
            foreach ($ips as $ip => $comment) {
                $commands[] = sprintf('/ip hotspot walled-garden ip add dst-address=%s action=allow comment="%s"', $ip, addslashes($comment));
            }

            foreach ($commands as $command) {
                $ssh->exec($command);
            }

            $results['applied'][] = 'Walled Garden configured (' . count($hosts) . ' hosts, ' . count($ips) . ' IPs)';
        } catch (\Exception $e) {
            $results['errors'][] = 'Walled Garden: ' . $e->getMessage();
            Log::error('Walled garden configuration failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
        }
    }

    private function disableFTP(SshExecutor $ssh, Router $router, array &$results): void
    {
        try {
            $ssh->exec('/ip service set ftp disabled=yes');
            $results['applied'][] = 'FTP service disabled';
        } catch (\Exception $e) {
            $results['errors'][] = 'FTP Disable: ' . $e->getMessage();
        }
    }

    private function configureAdvancedFirewall(SshExecutor $ssh, Router $router, string $wanInterface, array &$results): void
    {
        try {
            $rules = $ssh->exec('/ip firewall filter print detail without-paging');
            if (stripos($rules, 'Drop WAN to LAN') === false) {
                $ssh->exec(sprintf('/ip firewall filter add chain=forward action=drop connection-state=new in-interface="%s" comment="Drop WAN to LAN"', addslashes($wanInterface)));
                $results['applied'][] = 'Advanced firewall rule: Drop WAN to LAN';
            }
        } catch (\Exception $e) {
            $results['errors'][] = 'Advanced Firewall: ' . $e->getMessage();
        }
    }

    private function configureSNMP(SshExecutor $ssh, Router $router, array &$results): void
    {
        try {
            $ssh->exec('/snmp set enabled=yes contact="admin@traidnet.co.ke"');
            $communities = $ssh->exec('/snmp community print detail without-paging');
            if (stripos($communities, 'name=public') === false && stripos($communities, 'name: public') === false) {
                $ssh->exec('/snmp community add name="public" addresses="192.168.56.0/24"');
            }
            $results['applied'][] = 'SNMP monitoring enabled';
        } catch (\Exception $e) {
            Log::warning('SNMP configuration skipped', ['router_id' => $router->id, 'reason' => $e->getMessage()]);
        }
    }

    private function hardenManagementServices(SshExecutor $ssh, Router $router, array &$results): void
    {
        try {
            $managementNetwork = env('MANAGEMENT_NETWORK', '192.168.56.0/24');
            $services = ['telnet', 'ftp', 'www', 'api-ssl'];
            foreach ($services as $service) {
                $ssh->exec('/ip service set ' . $service . ' disabled=yes');
            }
            foreach (['ssh', 'winbox', 'api'] as $service) {
                $ssh->exec(sprintf('/ip service set %s address="%s"', $service, addslashes($managementNetwork)));
            }
            $results['applied'][] = 'Management services hardened';
        } catch (\Exception $e) {
            $results['errors'][] = 'Management Services Hardening: ' . $e->getMessage();
        }
    }

    private function configureDNS(SshExecutor $ssh, Router $router, array &$results): void
    {
        try {
            $dnsServers = env('DNS_SERVERS', '8.8.8.8,1.1.1.1');
            $ssh->exec(sprintf('/ip dns set servers="%s" allow-remote-requests=yes', addslashes($dnsServers)));
            $results['applied'][] = 'DNS servers configured: ' . $dnsServers;
        } catch (\Exception $e) {
            $results['errors'][] = 'DNS Configuration: ' . $e->getMessage();
        }
    }

    private function serviceDisabled(string $servicesOutput, string $serviceName): bool
    {
        if (preg_match('/name[=:]\s*' . preg_quote($serviceName, '/') . '.*disabled[=:]\s*yes/i', $servicesOutput)) {
            return true;
        }

        return false;
    }

    private function executor(Router $router, int $timeout): SshExecutor
    {
        $ssh = app()->make(SshExecutor::class, ['router' => $router, 'timeout' => $timeout]);
        $ssh->connect();
        return $ssh;
    }

    private function getSecurityRating(int $percentage): string
    {
        if ($percentage >= 95) return 'EXCELLENT';
        if ($percentage >= 85) return 'GOOD';
        if ($percentage >= 70) return 'FAIR';
        return 'POOR';
    }
}
