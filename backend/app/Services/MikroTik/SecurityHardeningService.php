<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Support\Facades\Log;

class SecurityHardeningService extends TenantAwareService
{
    /**
     * Apply comprehensive security hardening to router
     *
     * @param Router $router
     * @return array
     */
    public function applySecurityHardening(Router $router): array
    {
        $results = [
            'success' => false,
            'applied' => [],
            'errors' => [],
        ];

        try {
            $password = decrypt($router->password);
            $ipAddress = trim(explode('/', $router->ip_address)[0]);

            $client = new Client([
                'host' => $ipAddress,
                'user' => $router->username,
                'pass' => $password,
                'port' => $router->port ?? 8728,
                'timeout' => 10,
            ]);

            Log::info('Applying security hardening', ['router_id' => $router->id]);

            // 1. Configure Walled Garden
            $this->configureWalledGarden($client, $router->id, $results);

            // 2. Harden Management Services
            $this->hardenManagementServices($client, $router->id, $results);

            // 3. Configure DNS Servers
            $this->configureDNS($client, $router->id, $results);

            // 4. Disable FTP (ensure it's disabled after deployment)
            $this->disableFTP($client, $router->id, $results);

            // 5. Configure additional firewall rules
            $this->configureAdvancedFirewall($client, $router->id, $results);

            // 6. Enable SNMP for monitoring (optional)
            $this->configureSNMP($client, $router->id, $results);

            $results['success'] = empty($results['errors']);

            Log::info('Security hardening completed', [
                'router_id' => $router->id,
                'success' => $results['success'],
                'applied_count' => count($results['applied']),
            ]);

        } catch (\Exception $e) {
            Log::error('Security hardening failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Configure walled garden rules
     */
    private function configureWalledGarden(Client $client, string $routerId, array &$results): void
    {
        try {
            // Remove existing walled garden rules
            $existing = $client->query(new Query('/ip/hotspot/walled-garden/print'))->read();
            foreach ($existing as $rule) {
                $client->query((new Query('/ip/hotspot/walled-garden/remove'))
                    ->equal('.id', $rule['.id'])
                )->read();
            }

            $existingIP = $client->query(new Query('/ip/hotspot/walled-garden/ip/print'))->read();
            foreach ($existingIP as $rule) {
                $client->query((new Query('/ip/hotspot/walled-garden/ip/remove'))
                    ->equal('.id', $rule['.id'])
                )->read();
            }

            // Add walled garden hosts
            $hosts = [
                'hotspot.traidnet.co.ke' => 'Captive Portal',
                '*.googleapis.com' => 'Google APIs',
                '*.gstatic.com' => 'Google Static',
                '*.cloudflare.com' => 'Cloudflare CDN',
                '*.cloudfront.net' => 'AWS CloudFront',
            ];

            foreach ($hosts as $host => $comment) {
                $client->query((new Query('/ip/hotspot/walled-garden/add'))
                    ->equal('dst-host', $host)
                    ->equal('action', 'allow')
                    ->equal('comment', $comment)
                )->read();
            }

            // Add walled garden IPs
            $ips = [
                '8.8.8.8' => 'Google DNS',
                '1.1.1.1' => 'Cloudflare DNS',
                '8.8.4.4' => 'Google DNS Secondary',
            ];

            foreach ($ips as $ip => $comment) {
                $client->query((new Query('/ip/hotspot/walled-garden/ip/add'))
                    ->equal('dst-address', $ip)
                    ->equal('action', 'allow')
                    ->equal('comment', $comment)
                )->read();
            }

            $results['applied'][] = 'Walled Garden configured (' . count($hosts) . ' hosts, ' . count($ips) . ' IPs)';
            Log::info('Walled garden configured', ['router_id' => $routerId]);

        } catch (\Exception $e) {
            $results['errors'][] = 'Walled Garden: ' . $e->getMessage();
            Log::error('Walled garden configuration failed', [
                'router_id' => $routerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure FTP is disabled
     */
    private function disableFTP(Client $client, string $routerId, array &$results): void
    {
        try {
            $client->query((new Query('/ip/service/set'))
                ->equal('numbers', 'ftp')
                ->equal('disabled', 'yes')
            )->read();

            $results['applied'][] = 'FTP service disabled';
            Log::info('FTP disabled', ['router_id' => $routerId]);

        } catch (\Exception $e) {
            $results['errors'][] = 'FTP Disable: ' . $e->getMessage();
            Log::error('FTP disable failed', [
                'router_id' => $routerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Configure advanced firewall rules
     */
    private function configureAdvancedFirewall(Client $client, string $routerId, array &$results): void
    {
        try {
            $rules = $client->query(new Query('/ip/firewall/filter/print'))->read();

            // Check for WAN to LAN drop rule
            $hasWanDrop = false;
            foreach ($rules as $rule) {
                if (stripos($rule['comment'] ?? '', 'Drop WAN to LAN') !== false) {
                    $hasWanDrop = true;
                    break;
                }
            }

            if (!$hasWanDrop) {
                $client->query((new Query('/ip/firewall/filter/add'))
                    ->equal('chain', 'forward')
                    ->equal('action', 'drop')
                    ->equal('connection-state', 'new')
                    ->equal('in-interface', 'ether1')
                    ->equal('comment', 'Drop WAN to LAN')
                )->read();

                $results['applied'][] = 'Advanced firewall rule: Drop WAN to LAN';
            }

            Log::info('Advanced firewall configured', ['router_id' => $routerId]);

        } catch (\Exception $e) {
            $results['errors'][] = 'Advanced Firewall: ' . $e->getMessage();
            Log::error('Advanced firewall configuration failed', [
                'router_id' => $routerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Configure SNMP for monitoring
     */
    private function configureSNMP(Client $client, string $routerId, array &$results): void
    {
        try {
            // Enable SNMP
            $client->query((new Query('/snmp/set'))
                ->equal('enabled', 'yes')
                ->equal('contact', 'admin@traidnet.co.ke')
            )->read();

            // Check if community exists
            $communities = $client->query(new Query('/snmp/community/print'))->read();
            $hasPublic = false;

            foreach ($communities as $comm) {
                if (($comm['name'] ?? '') === 'public') {
                    $hasPublic = true;
                    break;
                }
            }

            if (!$hasPublic) {
                $client->query((new Query('/snmp/community/add'))
                    ->equal('name', 'public')
                    ->equal('addresses', '192.168.56.0/24')
                )->read();
            }

            $results['applied'][] = 'SNMP monitoring enabled';
            Log::info('SNMP configured', ['router_id' => $routerId]);

        } catch (\Exception $e) {
            // SNMP is optional, don't fail if it doesn't work
            Log::warning('SNMP configuration skipped', [
                'router_id' => $routerId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get security score for router
     *
     * @param Router $router
     * @return array
     */
    public function getSecurityScore(Router $router): array
    {
        $score = 0;
        $maxScore = 100;
        $checks = [];

        try {
            $password = decrypt($router->password);
            $ipAddress = trim(explode('/', $router->ip_address)[0]);

            $client = new Client([
                'host' => $ipAddress,
                'user' => $router->username,
                'pass' => $password,
                'port' => $router->port ?? 8728,
                'timeout' => 10,
            ]);

            // Check 1: FTP Disabled (10 points)
            $services = $client->query(new Query('/ip/service/print'))->read();
            foreach ($services as $service) {
                if (($service['name'] ?? '') === 'ftp') {
                    $ftpDisabled = ($service['disabled'] ?? 'no') === 'yes';
                    if ($ftpDisabled) {
                        $score += 10;
                        $checks['ftp'] = ['status' => 'pass', 'points' => 10];
                    } else {
                        $checks['ftp'] = ['status' => 'fail', 'points' => 0];
                    }
                    break;
                }
            }

            // Check 2: RADIUS Enabled (15 points)
            $radius = $client->query(new Query('/radius/print'))->read();
            $radiusOk = false;
            foreach ($radius as $r) {
                if (($r['service'] ?? '') === 'hotspot') {
                    $radiusOk = true;
                    break;
                }
            }
            if ($radiusOk) {
                $score += 15;
                $checks['radius'] = ['status' => 'pass', 'points' => 15];
            } else {
                $checks['radius'] = ['status' => 'fail', 'points' => 0];
            }

            // Check 3: Firewall Rules (20 points)
            $firewallRules = $client->query(new Query('/ip/firewall/filter/print'))->read();
            $hasEstablished = false;
            $hasInvalid = false;
            foreach ($firewallRules as $rule) {
                if (stripos($rule['comment'] ?? '', 'Established') !== false) $hasEstablished = true;
                if (stripos($rule['comment'] ?? '', 'Invalid') !== false) $hasInvalid = true;
            }
            if ($hasEstablished && $hasInvalid) {
                $score += 20;
                $checks['firewall'] = ['status' => 'pass', 'points' => 20];
            } else {
                $checks['firewall'] = ['status' => 'partial', 'points' => 10];
                $score += 10;
            }

            // Check 4: NAT Configured (10 points)
            $nat = $client->query(new Query('/ip/firewall/nat/print'))->read();
            $natOk = false;
            foreach ($nat as $rule) {
                if (($rule['action'] ?? '') === 'masquerade') {
                    $natOk = true;
                    break;
                }
            }
            if ($natOk) {
                $score += 10;
                $checks['nat'] = ['status' => 'pass', 'points' => 10];
            } else {
                $checks['nat'] = ['status' => 'fail', 'points' => 0];
            }

            // Check 5: DNS Configured (5 points)
            $dns = $client->query(new Query('/ip/dns/print'))->read();
            $dnsServers = $dns[0]['servers'] ?? '';
            if (!empty($dnsServers)) {
                $score += 5;
                $checks['dns'] = ['status' => 'pass', 'points' => 5];
            } else {
                $checks['dns'] = ['status' => 'fail', 'points' => 0];
            }

            // Check 6: Session Management (10 points)
            $userProfiles = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();
            $sessionOk = false;
            foreach ($userProfiles as $profile) {
                $idleTimeout = $profile['idle-timeout'] ?? 'none';
                if ($idleTimeout !== 'none') {
                    $sessionOk = true;
                    break;
                }
            }
            if ($sessionOk) {
                $score += 10;
                $checks['session_management'] = ['status' => 'pass', 'points' => 10];
            } else {
                $checks['session_management'] = ['status' => 'fail', 'points' => 0];
            }

            // Check 7: Rate Limiting (10 points)
            foreach ($userProfiles as $profile) {
                $rateLimit = $profile['rate-limit'] ?? 'none';
                if ($rateLimit !== 'none') {
                    $score += 10;
                    $checks['rate_limiting'] = ['status' => 'pass', 'points' => 10];
                    break;
                }
            }

            // Check 8: Management Interface Protection (20 points)
            $bridgePorts = $client->query(new Query('/interface/bridge/port/print'))->read();
            $ether2InBridge = false;
            foreach ($bridgePorts as $bp) {
                if (($bp['interface'] ?? '') === 'ether2') {
                    $ether2InBridge = true;
                    break;
                }
            }
            if (!$ether2InBridge) {
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

    /**
     * Get security rating based on percentage
     */
    private function getSecurityRating(int $percentage): string
    {
        if ($percentage >= 95) return 'EXCELLENT';
        if ($percentage >= 85) return 'GOOD';
        if ($percentage >= 70) return 'FAIR';
        return 'POOR';
    }

    /**
     * Harden management services (disable insecure, restrict secure)
     */
    private function hardenManagementServices(Client $client, string $routerId, array &$results): void
    {
        try {
            $services = $client->query(new Query('/ip/service/print'))->read();
            $managementNetwork = env('MANAGEMENT_NETWORK', '192.168.56.0/24');
            $hardenedCount = 0;

            foreach ($services as $service) {
                $name = $service['name'] ?? '';
                $id = $service['.id'] ?? '';

                // Disable insecure services
                if (in_array($name, ['telnet', 'ftp', 'www', 'api-ssl'])) {
                    $client->query((new Query('/ip/service/set'))
                        ->equal('.id', $id)
                        ->equal('disabled', 'yes')
                    )->read();
                    $hardenedCount++;
                    Log::info("Disabled insecure service: $name", ['router_id' => $routerId]);
                }

                // Restrict management services to management network
                if (in_array($name, ['ssh', 'winbox', 'api'])) {
                    $client->query((new Query('/ip/service/set'))
                        ->equal('.id', $id)
                        ->equal('address', $managementNetwork)
                    )->read();
                    $hardenedCount++;
                    Log::info("Restricted service to $managementNetwork: $name", ['router_id' => $routerId]);
                }
            }

            $results['applied'][] = "Management services hardened ($hardenedCount services)";
            Log::info('Management services hardened', [
                'router_id' => $routerId,
                'count' => $hardenedCount
            ]);

        } catch (\Exception $e) {
            $results['errors'][] = 'Management Services Hardening: ' . $e->getMessage();
            Log::error('Management services hardening failed', [
                'router_id' => $routerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Configure DNS servers
     */
    private function configureDNS(Client $client, string $routerId, array &$results): void
    {
        try {
            $dnsServers = env('DNS_SERVERS', '8.8.8.8,1.1.1.1');

            $client->query((new Query('/ip/dns/set'))
                ->equal('servers', $dnsServers)
                ->equal('allow-remote-requests', 'yes')
            )->read();

            $results['applied'][] = "DNS servers configured: $dnsServers";
            Log::info('DNS servers configured', [
                'router_id' => $routerId,
                'servers' => $dnsServers
            ]);

        } catch (\Exception $e) {
            $results['errors'][] = 'DNS Configuration: ' . $e->getMessage();
            Log::error('DNS configuration failed', [
                'router_id' => $routerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
