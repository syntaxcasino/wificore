<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

use App\Models\PppoeUser;
use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * PPPoE Captive Portal Service
 * 
 * Manages captive portal configuration for unpaid/expired PPPoE users.
 * Redirects HTTP traffic to the payment portal when account is not active.
 */
class PppoeCaptivePortalService extends BaseMikroTikService
{
    /**
     * Generate captive portal configuration for unpaid users
     * This creates the walled garden and redirect rules
     */
    public function generateCaptivePortalConfig(string $routerId, array $options = []): string
    {
        $portalUrl = $options['portal_url'] ?? config('app.frontend_url') . '/portal';
        $portalDomain = parse_url($portalUrl, PHP_URL_HOST) ?? 'wificore.traidsolutions.com';

        $this->logStep('Generating PPPoE captive portal configuration', [
            'router_id' => $routerId,
            'portal_url' => $portalUrl,
        ]);

        $script = [];
        $script = array_merge($script, $this->generateHeader('PPPoE Captive Portal Configuration', $routerId));

        // Create address list for active (paid) users
        $script[] = "/ip firewall address-list add list=pppoe-paid-users comment=\"WiFiCore PPPoE Paid Users\" address=0.0.0.0 disabled=yes";
        $script[] = '';

        // Create Layer 7 protocol matcher for HTTP traffic (for redirect)
        $script[] = "/ip firewall layer7-protocol add name=http-get host=\"^.*\\.?(www)?.*\\$\" regexp=\"^(GET|POST) /\" comment=\"WiFiCore HTTP L7\"";
        $script[] = '';

        // NAT redirect chain for unpaid users
        $script[] = "# NAT Redirect for Unpaid PPPoE Users";
        $script[] = ':local portalUrl "' . $portalUrl . '"';
        $script[] = ':local portalDomain "' . $portalDomain . '"';
        $script[] = '';

        // Mark packets from unpaid PPPoE users (not in paid list)
        $script[] = "/ip firewall mangle add chain=prerouting action=mark-connection new-connection-mark=pppoe-unpaid passthrough=yes [find name-list=pppoe-paid-users] comment=\"WiFiCore: Mark paid users\" disabled=yes";
        $script[] = "/ip firewall mangle add chain=prerouting action=mark-connection new-connection-mark=pppoe-unpaid passthrough=yes connection-mark=no-mark comment=\"WiFiCore: Mark unpaid PPPoE\" disabled=yes";
        $script[] = '';

        // Walled Garden - Allow DNS and portal access
        $script[] = "# Walled Garden - Allow essential services";
        $script[] = "/ip firewall nat add chain=dstnat action=accept connection-mark=pppoe-unpaid dst-port=53 protocol=udp comment=\"WiFiCore: DNS UDP\"";
        $script[] = "/ip firewall nat add chain=dstnat action=accept connection-mark=pppoe-unpaid dst-port=53 protocol=tcp comment=\"WiFiCore: DNS TCP\"";
        $script[] = "/ip firewall nat add chain=dstnat action=accept connection-mark=pppoe-unpaid dst-address-list=WiFiCore-Portal comment=\"WiFiCore: Portal Access\"";
        $script[] = '';

        // Create address list for portal domains
        $script[] = "# Portal Domain Allow List";
        $script[] = "/ip firewall address-list add list=WiFiCore-Portal address={$portalDomain} comment=\"WiFiCore Portal Domain\"";
        $script[] = "/ip firewall address-list add list=WiFiCore-Portal address=*.{$portalDomain} comment=\"WiFiCore Portal Subdomains\"";
        // Common payment provider domains
        $script[] = "/ip firewall address-list add list=WiFiCore-Portal address=*.mpesa.com comment=\"Safaricom M-Pesa\"";
        $script[] = "/ip firewall address-list add list=WiFiCore-Portal address=*.safaricom.co.ke comment=\"Safaricom\"";
        $script[] = "/ip firewall address-list add list=WiFiCore-Portal address=api.safaricom.co.ke comment=\"M-Pesa API\"";
        $script[] = '';

        // Redirect HTTP traffic to portal (port 80)
        $script[] = "# Redirect HTTP to Portal (port 80)";
        $script[] = "/ip firewall nat add chain=dstnat action=dst-nat to-addresses={$portalDomain} to-ports=443 \\";
        $script[] = "    protocol=tcp dst-port=80 connection-mark=pppoe-unpaid \\";
        $script[] = "    comment=\"WiFiCore: Redirect HTTP to Portal\"";
        $script[] = '';

        // Redirect HTTPS traffic (optional - may cause cert warnings but effective)
        $script[] = "# Optional: Redirect HTTPS to Portal (shows cert warning but blocks all traffic)";
        $script[] = "/ip firewall nat add chain=dstnat action=dst-nat to-addresses={$portalDomain} to-ports=443 \\";
        $script[] = "    protocol=tcp dst-port=443 connection-mark=pppoe-unpaid \\";
        $script[] = "    comment=\"WiFiCore: Redirect HTTPS to Portal (disabled by default)\" disabled=yes";
        $script[] = '';

        // Add filter rules to block non-HTTP traffic from unpaid users
        $script[] = "# Block other traffic from unpaid users";
        $script[] = "/ip firewall filter add chain=forward action=drop connection-mark=pppoe-unpaid \\";
        $script[] = "    comment=\"WiFiCore: Block unpaid PPPoE traffic\" disabled=yes";
        $script[] = '';

        $script[] = "# === END PPPoE Captive Portal Configuration ===";
        $script[] = ":log info \"WiFiCore: PPPoE Captive Portal config applied for router {$routerId}\"";

        return implode("\n", $script);
    }

    /**
     * Generate script to mark a specific user as paid/active
     */
    public function markUserAsPaid(PppoeUser $pppoeUser, string $ipAddress): string
    {
        $script = [];
        $script[] = "# Mark PPPoE user as paid - {$pppoeUser->username}";
        $script[] = "/ip firewall address-list add list=pppoe-paid-users address={$ipAddress} comment=\"WiFiCore: {$pppoeUser->username}\"";
        
        // Remove any existing unpaid mark
        $script[] = "/ip firewall connection remove [find src-address=\"{$ipAddress}\" || dst-address=\"{$ipAddress}\"]";
        
        return implode("\n", $script);
    }

    /**
     * Generate script to mark a user as unpaid (for expired/suspended accounts)
     */
    public function markUserAsUnpaid(PppoeUser $pppoeUser, string $ipAddress): string
    {
        $script = [];
        $script[] = "# Mark PPPoE user as unpaid - {$pppoeUser->username}";
        $script[] = "/ip firewall address-list remove [find list=pppoe-paid-users address={$ipAddress} comment~\"WiFiCore: {$pppoeUser->username}\"]";
        
        // Clear connection tracking to force re-evaluation
        $script[] = "/ip firewall connection remove [find src-address=\"{$ipAddress}\" || dst-address=\"{$ipAddress}\"]";
        
        return implode("\n", $script);
    }

    /**
     * Generate simplified captive portal using RouterOS built-in hotspot-like behavior
     * Alternative: Use DNS hijacking for lighter-weight implementation
     */
    public function generateDnsHijackingConfig(string $routerId, array $options = []): string
    {
        $portalUrl = $options['portal_url'] ?? config('app.frontend_url') . '/portal';
        $portalIp = $options['portal_ip'] ?? gethostbyname(parse_url($portalUrl, PHP_URL_HOST));

        $this->logStep('Generating DNS hijacking config for captive portal', [
            'router_id' => $routerId,
            'portal_ip' => $portalIp,
        ]);

        $script = [];
        $script = array_merge($script, $this->generateHeader('PPPoE DNS Hijacking Captive Portal', $routerId));

        // Create DNS static entry for all domains -> portal IP for unpaid users
        $script[] = "# DNS Hijacking for Unpaid PPPoE Users";
        $script[] = "/ip dns static add name=\".*\" address={$portalIp} comment=\"WiFiCore: Captive Portal DNS\" disabled=yes";
        $script[] = '';

        // Alternative: Use NAT to redirect DNS queries from unpaid users to a local DNS
        $script[] = "# Redirect DNS from unpaid users to our DNS with custom records";
        $script[] = "/ip firewall nat add chain=dstnat action=redirect protocol=udp dst-port=53 \\";
        $script[] = "    src-address-list=!pppoe-paid-users comment=\"WiFiCore: DNS Hijack Unpaid\"";
        $script[] = '';

        // Add static DNS entries for portal (always resolve correctly)
        $portalDomain = parse_url($portalUrl, PHP_URL_HOST);
        $script[] = "/ip dns static add name={$portalDomain} address={$portalIp} comment=\"WiFiCore: Portal DNS (always correct)\"";
        
        return implode("\n", $script);
    }

    /**
     * Configure PPPoE profile for unpaid users with RADIUS attribute
     */
    public function generateUnpaidUserProfile(string $routerId, array $options = []): string
    {
        $rateLimit = $options['rate_limit'] ?? '128k/128k';  // Very slow
        $portalUrl = $options['portal_url'] ?? config('app.frontend_url') . '/portal?captive=1';

        $script = [];
        $script[] = "# PPPoE Profile for Unpaid Users - {$routerId}";
        
        // Create a special profile with redirect
        $script[] = "/ppp profile add name=WiFiCore-Unpaid local-address=10.200.0.1 remote-address=WiFiCore-Unpaid-Pool rate-limit={$rateLimit} comment=\"WiFiCore: Unpaid users with captive redirect\"";
        $script[] = '';

        // Configure DNS for captive portal
        $script[] = "/ip dns static add name=captive.wificore.local type=A address=10.200.0.1 comment=\"WiFiCore Captive\"";
        $script[] = '';

        // NAT rule for captive redirect on HTTP
        $script[] = "/ip firewall nat add chain=dstnat action=redirect protocol=tcp dst-port=80 src-address-list=pppoe-active-users dst-address-list=!WiFiCore-Portal comment=\"WiFiCore: HTTP Redirect for Unpaid\"";

        return implode("\n", $script);
    }
}
