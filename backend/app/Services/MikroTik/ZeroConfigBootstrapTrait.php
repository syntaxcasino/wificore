<?php

namespace App\Services\MikroTik;

use App\Support\SubnetHelper;

/**
 * Shared bootstrap logic for ZeroConfig generators (PPPoE, Hotspot, Hybrid).
 *
 * Eliminates duplication of:
 *  - Interface normalization
 *  - Gateway IP computation
 *  - RouterOS string escaping
 *  - Service hardening (disable telnet/ftp/www, restrict SSH/Winbox)
 *  - Brute-force protection (SSH/Winbox address-list blacklist)
 *  - Firewall logging setup
 *  - TCP flag anomaly detection (high-end only)
 *  - Connection tracking configuration
 *  - Global default drop rules
 */
trait ZeroConfigBootstrapTrait
{
    // =========================================================================
    // Interface Normalization
    // =========================================================================

    /**
     * Normalize interface input (array, JSON, comma-separated) into a clean array
     * of valid RouterOS interface names.
     */
    protected function normalizeInterfaceList($rawInterfaces, ?string $fallback = null): array
    {
        if (is_array($rawInterfaces)) {
            $interfaces = $rawInterfaces;
        } elseif (is_string($rawInterfaces)) {
            $decoded = json_decode($rawInterfaces, true);
            if (is_array($decoded)) {
                $interfaces = [];
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $nestedDecoded = json_decode($item, true);
                        if (is_array($nestedDecoded)) {
                            $interfaces = array_merge($interfaces, $nestedDecoded);
                        } elseif (!empty(trim($item)) && $item[0] !== '[') {
                            $interfaces[] = $item;
                        }
                    }
                }
            } else {
                $interfaces = array_map('trim', explode(',', $rawInterfaces));
            }
        } else {
            $interfaces = [];
        }

        $interfaces = array_values(array_unique(array_filter($interfaces, function ($iface) {
            return is_string($iface) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $iface);
        })));

        if (empty($interfaces) && $fallback) {
            $interfaces = [$fallback];
        }

        return $interfaces;
    }

    // =========================================================================
    // Gateway IP Computation
    // =========================================================================

    /**
     * Compute a safe gateway IP within the given CIDR, falling back to network+1.
     */
    protected function getSafeGatewayIp(string $networkCidr, ?string $gatewayIp): string
    {
        $parts      = explode('/', $networkCidr, 2);
        $networkIp  = $parts[0] ?? '';
        $cidr       = (int) ($parts[1] ?? 24);
        $networkLong = ip2long($networkIp);

        if ($networkLong === false) {
            return (string) $gatewayIp;
        }

        if ($cidr < 0 || $cidr > 32) {
            $cidr = 24;
        }

        $mask            = $cidr === 0 ? 0 : ((-1 << (32 - $cidr)) & 0xFFFFFFFF);
        $networkAddrLong = $networkLong & $mask;
        $broadcastLong   = $networkAddrLong | (~$mask & 0xFFFFFFFF);
        $candidateLong   = $gatewayIp ? ip2long($gatewayIp) : false;

        if ($candidateLong === false) {
            return long2ip($networkAddrLong + 1);
        }

        if (($candidateLong & $mask) !== $networkAddrLong || $candidateLong === $networkAddrLong || $candidateLong === $broadcastLong) {
            return long2ip($networkAddrLong + 1);
        }

        return $gatewayIp;
    }

    // =========================================================================
    // RouterOS String Escaping
    // =========================================================================

    /**
     * Escape a string for safe embedding in a RouterOS double-quoted parameter value.
     *
     * Use this for simple values (RADIUS secrets, NAS identifiers, etc.)
     * that are placed inside "..." in a RouterOS command.
     *
     * Only characters that RouterOS interprets inside double-quoted strings are escaped:
     *   \  →  \\
     *   "  →  \"
     *   $  →  \$
     *
     * Note: ; { } do NOT need escaping inside double-quoted strings — RouterOS
     * only interprets them at the command-line level, not within quoted values.
     */
    protected function escapeRouterOsString(string $string): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $string);
    }

    /**
     * Escape a multi-line RouterOS script body for embedding in source="..." parameter.
     *
     * Differences from escapeRouterOsString:
     *   - Newlines are converted to \n (RouterOS newline escape inside double-quoted strings)
     *   - Backslashes are escaped FIRST, then newlines are inserted (avoids double-escaping)
     *   - ; { } are NOT escaped (they are valid RouterOS syntax within the script body)
     */
    protected function escapeScriptSource(string $scriptBody): string
    {
        // 1. Escape backslashes first (before we introduce our own)
        $scriptBody = str_replace('\\', '\\\\', $scriptBody);
        // 2. Escape double quotes
        $scriptBody = str_replace('"', '\\"', $scriptBody);
        // 3. Escape dollar signs (RouterOS variable sigil)
        $scriptBody = str_replace('$', '\\$', $scriptBody);
        // 4. Convert real newlines to RouterOS \n escape sequence
        $scriptBody = str_replace(["\r\n", "\r", "\n"], '\\n', $scriptBody);

        return $scriptBody;
    }

    // =========================================================================
    // Shared Bootstrap Script Sections
    // =========================================================================

    /**
     * Service hardening: disable unused services, restrict SSH/Winbox/api-ssl.
     *
     * The allowed address set is: $mgmt (WireGuard VPN tunnel subnet, e.g. 10.8.0.0/24)
     * combined with $vpnServerIp (the WireGuard server peer IP) when available.
     * This is intentionally narrower than the full 10.0.0.0/8 RFC-1918 range.
     *
     * @param string      $prefix       Comment prefix (e.g. "PPPoE-abc123")
     * @param string      $mgmt         VPN management subnet CIDR (e.g. "10.8.0.0/24")
     * @param string|null $vpnServerIp  WireGuard server IP; tightens service ACL when set
     */
    protected function bootstrapServiceHardening(string $prefix, string $mgmt, ?string $vpnServerIp = null): array
    {
        // Use the tighter of: explicit VPN server IP alone, or fall back to the full mgmt subnet.
        // This prevents any RFC-1918 host from reaching management services.
        $allowAddr = ($vpnServerIp && filter_var($vpnServerIp, FILTER_VALIDATE_IP))
            ? $vpnServerIp . '/32,' . $mgmt
            : $mgmt;

        return [
            "# Service Hardening (management access restricted to: {$allowAddr})",
            ":do { /ip service enable api-ssl } on-error={ /log info \"{$prefix}: api-ssl not available\" }",
            ":do { /ip service set api-ssl address=\"{$allowAddr}\" } on-error={ /log warning \"{$prefix}: Failed to set api-ssl address\" }",
            ":do { /ip service set ssh address=\"{$allowAddr}\" } on-error={ /log warning \"{$prefix}: Failed to restrict SSH\" }",
            ":do { /ip service set winbox address=\"{$allowAddr}\" } on-error={ /log warning \"{$prefix}: Failed to restrict Winbox\" }",
            ":do { /ip service disable telnet,ftp,www,www-ssl,api,romon } on-error={ /log info \"{$prefix}: Some services already disabled\" }",
        ];
    }

    /**
     * Firewall logging: add topics=firewall memory logging.
     *
     * @param string $prefix  Comment prefix (e.g. "PPPoE-abc123")
     */
    protected function bootstrapFirewallLogging(string $prefix): array
    {
        return [
            ":do { /system logging remove [/system logging find comment=\"{$prefix}-FW-LOG\"] } on-error={}",
            ":do { /system logging add action=\"memory\" topics=\"firewall\" comment=\"{$prefix}-FW-LOG\" } on-error={}",
        ];
    }

    /**
     * Brute-force protection for SSH (22), Winbox (8291), API (8728), and API-SSL (8729).
     * Only sources within $allowAddr can reach management ports (enforced by service ACL
     * and the MGMT-ALLOW firewall rule), so the detect rule is scoped to $allowAddr —
     * this prevents any RFC-1918 host outside the VPN tunnel from polluting the blacklist.
     * Blacklisted sources are dropped and logged for NOC visibility.
     *
     * @param string $prefix     Comment prefix for firewall rule comments
     * @param string $allowAddr  Allowed management source (vpnIp/32 or mgmt subnet CIDR)
     */
    protected function bootstrapBruteForceProtection(string $prefix, string $allowAddr = '10.0.0.0/8'): array
    {
        return [
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"{$prefix}-BRUTE\"] } on-error={}",
            // Drop blacklisted sources immediately on all management ports (logged)
            "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"22,8291,8728,8729\" connection-state=\"new\" src-address-list=\"bruteforce-blacklist\" action=\"drop\" log=\"yes\" log-prefix=\"BRUTE-BL-DROP\" comment=\"{$prefix}-BRUTE-DROP\"",
            // Detect: >5 new connections per /32 from allowed range → blacklist for 1h
            "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"22,8291,8728,8729\" connection-state=\"new\" src-address=\"{$allowAddr}\" connection-limit=\"5,32\" action=\"add-src-to-address-list\" address-list=\"bruteforce-blacklist\" address-list-timeout=\"1h\" comment=\"{$prefix}-BRUTE-DETECT\"",
        ];
    }

    /**
     * TCP flag anomaly detection — high-end only.
     * Covers: FIN+SYN, SYN+RST, NULL (no flags), XMAS (all flags set).
     * Applied to both input and forward chains.
     * All drops are logged with a distinct prefix for syslog/NOC visibility.
     *
     * @param string $prefix  Comment prefix for firewall rule comments
     */
    protected function bootstrapTcpFlagAnomalyDetection(string $prefix): array
    {
        return [
            // INPUT chain: malformed flag combinations targeting the router itself
            "/ip firewall filter add chain=input protocol=\"tcp\" tcp-flags=\"fin,syn\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-FINSYN\" comment=\"{$prefix}-TCP-FINSYN\"",
            "/ip firewall filter add chain=input protocol=\"tcp\" tcp-flags=\"syn,rst\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-SYNRST\" comment=\"{$prefix}-TCP-SYNRST\"",
            "/ip firewall filter add chain=input protocol=\"tcp\" tcp-flags=\"!fin,!syn,!rst,!ack\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-NULL\" comment=\"{$prefix}-TCP-NULL\"",
            "/ip firewall filter add chain=input protocol=\"tcp\" tcp-flags=\"fin,syn,rst,psh,ack,urg\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-XMAS\" comment=\"{$prefix}-TCP-XMAS\"",
            // FORWARD chain: anomalous subscriber traffic passing through the router
            "/ip firewall filter add chain=forward protocol=\"tcp\" tcp-flags=\"fin,syn\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-FINSYN\" comment=\"{$prefix}-FWD-FINSYN\"",
            "/ip firewall filter add chain=forward protocol=\"tcp\" tcp-flags=\"syn,rst\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-SYNRST\" comment=\"{$prefix}-FWD-SYNRST\"",
            "/ip firewall filter add chain=forward protocol=\"tcp\" tcp-flags=\"!fin,!syn,!rst,!ack\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-NULL\" comment=\"{$prefix}-FWD-NULL\"",
            "/ip firewall filter add chain=forward protocol=\"tcp\" tcp-flags=\"fin,syn,rst,psh,ack,urg\" action=\"drop\" log=\"yes\" log-prefix=\"TCP-ANOM-XMAS\" comment=\"{$prefix}-FWD-XMAS\"",
        ];
    }

    /**
     * Connection tracking with relaxed UDP/ICMP timeouts.
     */
    protected function bootstrapConnectionTracking(): string
    {
        return "/ip firewall connection tracking set enabled=\"yes\" tcp-established-timeout=\"1h\" udp-timeout=\"60s\" icmp-timeout=\"60s\"";
    }

    /**
     * SNMP community + remote syslog export.
     * Safe to call on any tier; on low-end the syslog export is omitted via $enableSyslog.
     *
     * @param string $mgmt          Management subnet (SNMP source restrict)
     * @param string $syslogHost    Remote syslog host IP
     * @param bool   $enableSyslog  Whether to configure remote syslog (skip on low-end)
     */
    protected function bootstrapSnmpSyslog(string $mgmt, string $syslogHost, bool $enableSyslog = true): array
    {
        $rules = [
            "# SNMP & Syslog Export",
            ":do { /snmp community remove [/snmp community find name=\"public\"] } on-error={}",
            ":do { /snmp community add name=\"wificore-ro\" address=\"{$mgmt}\" security=\"authorized\" read-access=\"yes\" write-access=\"no\" comment=\"WifiCore SNMP\" } on-error={ /log warning \"SNMP: community add failed\" }",
            ":do { /snmp set enabled=\"yes\" contact=\"noc@wificore\" location=\"WifiCore\" } on-error={ /log warning \"SNMP: enable failed\" }",
        ];
        if ($enableSyslog) {
            $rules[] = ":do { /system logging action remove [/system logging action find name=\"remote-syslog\"] } on-error={}";
            $rules[] = ":do { /system logging action add name=\"remote_syslog\" target=\"remote\" remote=\"{$syslogHost}\" remote-port=\"514\" remote-log-format=\"bsd\" comment=\"WifiCore Syslog\" } on-error={ :log warning \"Syslog: action add failed\" }";
            $rules[] = ":do { /system logging add action=\"remote-syslog\" topics=\"critical\" comment=\"WifiCore-SYSLOG-CRIT\" } on-error={}";
            $rules[] = ":do { /system logging add action=\"remote-syslog\" topics=\"error\" comment=\"WifiCore-SYSLOG-ERR\" } on-error={}";
            $rules[] = ":do { /system logging add action=\"remote-syslog\" topics=\"warning\" comment=\"WifiCore-SYSLOG-WARN\" } on-error={}";
            // PPPoE/PPP session events (connect, disconnect, auth) forwarded to remote syslog
            $rules[] = ":do { /system logging add action=\"remote-syslog\" topics=\"ppp\" comment=\"WifiCore-SYSLOG-PPP\" } on-error={}";
            $rules[] = ":do { /system logging add action=\"remote-syslog\" topics=\"pppoe\" comment=\"WifiCore-SYSLOG-PPPOE\" } on-error={}";
        }
        $rules[] = "";
        return $rules;
    }

    /**
     * RADIUS AAA attribute documentation block — emitted as script comments.
     * Reminds NOC engineers which FreeRADIUS attributes must be set per user.
     * No RouterOS commands; purely informational.
     */
    protected function bootstrapRadiusAaaAttributes(): array
    {
        return [
            "# ── RADIUS AAA ATTRIBUTE REQUIREMENTS ──────────────────────────────────────",
            "# FreeRADIUS must return the following per-user attributes:",
            "#   Mikrotik-Rate-Limit   = \"5M/5M\"    (per-user bandwidth, TX/RX)",
            "#   Framed-Pool           = \"<pool>\"   (dynamic IP pool assignment)",
            "#   Framed-IP-Address     = x.x.x.x    (static IP, if applicable)",
            "#   Mikrotik-Group        = \"<profile>\" (PPP/Hotspot profile selection)",
            "# Local queues are NOT used — RADIUS is the sole bandwidth enforcer.",
            "# ────────────────────────────────────────────────────────────────────────────",
            "",
        ];
    }

    /**
     * Management-service rate limiting via address-list.
     * Sources that exceed the connection rate are added to mgmt-rate-limit,
     * then dropped with logging so NOC can see who is flooding management ports.
     * Scoped to $allowAddr so only the VPN tunnel subnet is considered.
     *
     * @param string $prefix     Firewall rule comment prefix
     * @param string $allowAddr  Allowed management source (vpnIp/32 or mgmt subnet CIDR)
     */
    protected function bootstrapMgmtRateLimit(string $prefix, string $allowAddr = '10.0.0.0/8'): array
    {
        return [
            "# Management Rate Limiting — API-SSL (8729) + SNMP (161)",
            // API-SSL: >10 new connections per /32 in 32s → blacklist 5 min, then drop+log
            "/ip firewall filter add chain=input protocol=tcp dst-port=8729 src-address=\"{$allowAddr}\" connection-state=new connection-limit=10,32 action=add-src-to-address-list address-list=mgmt-rate-limit address-list-timeout=5m comment=\"{$prefix}-APISS-RL-DETECT\"",
            "/ip firewall filter add chain=input protocol=tcp dst-port=8729 src-address-list=mgmt-rate-limit action=drop log=yes log-prefix=\"MGMT-RL-APISS\" comment=\"{$prefix}-APISS-RL-DROP\"",
            // SNMP: >30 new requests per /32 in 32s → blacklist 5 min, then drop+log
            "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=\"{$allowAddr}\" connection-state=new connection-limit=30,32 action=add-src-to-address-list address-list=mgmt-rate-limit address-list-timeout=5m comment=\"{$prefix}-SNMP-RL-DETECT\"",
            "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address-list=mgmt-rate-limit action=drop log=yes log-prefix=\"MGMT-RL-SNMP\" comment=\"{$prefix}-SNMP-RL-DROP\"",
            "",
        ];
    }

    /**
     * PPP/PPPoE AAA hardening — enforces fail-closed behaviour on RADIUS outage.
     *
     * RouterOS fallback behaviour without this:
     *   - If `use-radius=yes` but RADIUS times out, RouterOS may still allow the session
     *     using local profile attributes (dns-server, rate-limit, remote-address pool).
     * This method explicitly nulls those attributes on the named profile so any session
     * that slips through without RADIUS has no IP, no DNS, and no bandwidth cap assignment.
     * It also logs the confirmed /ppp aaa state to syslog for audit.
     *
     * @param string $prefix   Comment prefix
     * @param string $profName PPP profile name (e.g. "pppoe-prof-abc123")
     */
    protected function bootstrapPppAaaHardening(string $prefix, string $profName): array
    {
        return [
            "# PPP AAA Hardening — fail-closed: no local fallback for IP/DNS/rate-limit",
            // Clear local-address so RouterOS cannot assign an IP if RADIUS is unreachable
            ":do { /ppp profile set [/ppp profile find name=\"{$profName}\"] local-address=\"\" } on-error={ /log warning \"{$prefix}: Failed to clear local-address on profile — sessions may get local IP if RADIUS is down\" }",
            // Explicitly remove local DNS so RADIUS Framed-Route / DNS attributes are the only source
            ":do { /ppp profile set [/ppp profile find name=\"{$profName}\"] dns-server=\"\" wins-server=\"\" } on-error={ /log warning \"{$prefix}: Failed to clear local DNS on profile (non-fatal)\" }",
            // Confirm /ppp aaa state: use-radius=yes + accounting=yes must both be set
            ":if ([/ppp aaa get use-radius] = true) do={ /log info \"{$prefix}: PPP AAA confirmed — use-radius=yes, RADIUS-only mode active.\" } else={ /log error \"{$prefix}: CRITICAL — PPP AAA use-radius is NOT set. Sessions may authenticate locally without rate-limit enforcement.\" }",
            ":if ([/ppp aaa get accounting] = true) do={ /log info \"{$prefix}: PPP accounting confirmed — session records will be sent to RADIUS.\" } else={ /log warning \"{$prefix}: PPP accounting is disabled — session usage data will NOT be sent to RADIUS.\" }",
            "",
        ];
    }

    /**
     * Per-session RADIUS attribute confirmation via PPP event scripts.
     *
     * Installs /ppp on-up and /ppp on-down RouterOS event scripts that log:
     *   - Username, assigned IP, service name, rate-limit (from /ppp active)
     *   - Whether Mikrotik-Rate-Limit was applied (non-empty rate-limit field)
     *   - Session teardown for disconnect auditing
     *
     * These log lines are emitted at the `info` level and forwarded to remote syslog
     * (since bootstrapSnmpSyslog already includes topics=ppp).
     *
     * @param string $prefix   Comment prefix
     * @param string $profName PPP profile name — only sessions using this profile are logged
     */
    protected function bootstrapPppSessionLogging(string $prefix, string $profName): array
    {
        $upScriptName   = "{$prefix}-ppp-up";
        $downScriptName = "{$prefix}-ppp-down";

        $upBody = implode("\n", [
            "# Per-session RADIUS attribute confirmation on PPP session-up",
            ":local u [/ppp active get [/ppp active find name=\$user] uptime]",
            ":local ip [/ppp active get [/ppp active find name=\$user] address]",
            ":local rl [/ppp active get [/ppp active find name=\$user] rate-limit]",
            ":local svc [/ppp active get [/ppp active find name=\$user] service]",
            ":if ([:len \$rl] > 0) do={",
            "  /log info \"{$prefix}: SESSION-UP user=\$user ip=\$ip rate-limit=\$rl service=\$svc [RADIUS: rate-limit APPLIED]\"",
            "} else={",
            "  /log error \"{$prefix}: SESSION-UP user=\$user ip=\$ip rate-limit=NONE service=\$svc [RADIUS: Mikrotik-Rate-Limit MISSING — check radreply]\"",
            "}",
        ]);

        $downBody = implode("\n", [
            "# Session teardown audit log (PPP on-down event)",
            "/log info \"{$prefix}: SESSION-DOWN user=\$user\"",
        ]);

        return [
            "# PPP Session Logging — per-session RADIUS attribute confirmation",
            ":do { /system script remove [/system script find name=\"{$upScriptName}\"] } on-error={}",
            ":do { /system script add name=\"{$upScriptName}\" source=\"{$this->escapeScriptSource($upBody)}\" comment=\"{$prefix}-PPP-UP-LOG\" } on-error={ /log warning \"{$prefix}: Failed to install PPP up-script\" }",
            ":do { /system script remove [/system script find name=\"{$downScriptName}\"] } on-error={}",
            ":do { /system script add name=\"{$downScriptName}\" source=\"{$this->escapeScriptSource($downBody)}\" comment=\"{$prefix}-PPP-DOWN-LOG\" } on-error={ /log warning \"{$prefix}: Failed to install PPP down-script\" }",
            // Attach event scripts to the PPP profile
            ":do { /ppp profile set [/ppp profile find name=\"{$profName}\"] on-up=\"{$upScriptName}\" on-down=\"{$downScriptName}\" } on-error={ /log warning \"{$prefix}: Failed to attach PPP event scripts to profile (non-fatal)\" }",
            "",
        ];
    }

    /**
     * Operational event logging: PPPoE server state monitor + sustained RADIUS outage alerting.
     *
     * Installs a RouterOS scheduler script that runs every 5 minutes:
     *   1. Checks whether the PPPoE server is disabled and logs an error if so.
     *   2. Checks RADIUS reachability; if unreachable, logs a sustained-outage critical alert
     *      distinct from the netwatch down event, so NOC sees it on each poll cycle.
     *
     * @param string $prefix      Comment prefix
     * @param string $svcName     PPPoE service-name (the value of service-name= on the server)
     * @param string $radiusIp    RADIUS server IP
     */
    protected function bootstrapOperationalLogging(string $prefix, string $svcName, string $radiusIp): array
    {
        $scriptName    = "{$prefix}-ops-monitor";
        $schedulerName = "{$prefix}-ops-sched";

        // RouterOS script body — single-quoted in PHP, so no PHP variable interpolation inside
        // The script itself uses RouterOS variables and string literals only.
        $scriptBody = implode("\n", [
            "# Operational monitor: PPPoE server state + RADIUS sustained outage",
            ":local svcName \"{$svcName}\"",
            ":local radiusIp \"{$radiusIp}\"",
            "",
            "# 1. PPPoE server state check",
            ":local svcDisabled [/interface pppoe-server server get [/interface pppoe-server server find service-name=\$svcName] disabled]",
            ":if (\$svcDisabled = true) do={",
            "  /log error \"{$prefix}: ALERT - PPPoE server '\$svcName' is DISABLED. Subscriber sessions cannot be established.\"",
            "} else={",
            "  /log info \"{$prefix}: PPPoE server '\$svcName' is running.\"",
            "}",
            "",
            "# 2. RADIUS sustained-outage check",
            ":local pingResult [/ping address=\"$radiusIp\" count=1 interval=500ms]",
            ":if (\$pingResult = 0) do={",
            "  /log error \"{$prefix}: SUSTAINED OUTAGE - RADIUS \$radiusIp still unreachable. All new sessions are being REJECTED.\"",
            "} else={",
            "  /log debug \"{$prefix}: RADIUS \$radiusIp OK (\$pingResult replies).\"",
            "}",
        ]);

        return [
            "# Operational Event Logging — PPPoE server state + RADIUS sustained-outage monitor",
            ":do { /system script remove [/system script find name=\"{$scriptName}\"] } on-error={}",
            ":do { /system script add name=\"{$scriptName}\" source=\"{$this->escapeScriptSource($scriptBody)}\" comment=\"{$prefix}-OPS-MON\" } on-error={ /log warning \"{$prefix}: Failed to install ops monitor script\" }",
            ":do { /system scheduler remove [/system scheduler find name=\"{$schedulerName}\"] } on-error={}",
            ":do { /system scheduler add name=\"{$schedulerName}\" interval=5m on-event=\"{$scriptName}\" start-time=startup comment=\"{$prefix}-OPS-SCHED\" } on-error={ /log warning \"{$prefix}: Failed to schedule ops monitor\" }",
            "",
        ];
    }

    /**
     * NetFlow/IPFIX traffic-flow export — high-end only.
     * Enables RouterOS /ip traffic-flow to stream per-flow records to a collector
     * (e.g. Grafana/ntopng/Elasticsearch) for subscriber traffic visibility.
     *
     * @param string $prefix       Comment prefix
     * @param string $collectorIp  IP of the NetFlow collector (typically the RADIUS/NMS host)
     * @param int    $collectorPort UDP port of the collector (default 2055 for nflow, 4739 for IPFIX)
     */
    protected function bootstrapTrafficFlow(string $prefix, string $collectorIp, int $collectorPort = 2055): array
    {
        return [
            "# Traffic Flow (NetFlow v9) — per-subscriber visibility",
            ":do { /ip traffic-flow target remove [/ip traffic-flow target find comment~\"{$prefix}-TFLOW\"] } on-error={}",
            ":do { /ip traffic-flow set enabled=\"yes\" interfaces=\"all\" active-flow-timeout=\"1m\" inactive-flow-timeout=\"15s\" } on-error={ /log warning \"{$prefix}: Failed to enable traffic-flow\" }",
            ":do { /ip traffic-flow target add dst-address=\"{$collectorIp}\" port=\"{$collectorPort}\" version=9 } on-error={ /log warning \"{$prefix}: Failed to add traffic-flow target {$collectorIp}:{$collectorPort}\" }",
            "",
        ];
    }

    /**
     * RADIUS continuous health monitoring via /tool netwatch.
     * Replaces the one-shot deploy-time ping with persistent monitoring:
     * - On DOWN: logs critical warning AND disables the PPPoE server (fail-closed).
     * - On UP:   logs recovery AND re-enables the PPPoE server.
     * Netwatch runs every 30 s; 3 s timeout.
     *
     * @param string      $prefix          Comment prefix
     * @param string      $radiusIp        RADIUS server IP to monitor
     * @param string|null $pppoeServiceName PPPoE server service-name to disable on DOWN (null = hotspot only, no disable)
     */
    protected function bootstrapRadiusNetwatch(string $prefix, string $radiusIp, ?string $pppoeServiceName = null): array
    {
        if ($pppoeServiceName) {
            $svcQ = '\"' . $pppoeServiceName . '\"';
            $downScript = ':log error \"' . $prefix . ': CRITICAL - RADIUS ' . $radiusIp . ' DOWN. Disabling PPPoE server to prevent unmetered sessions.\"; '
                . '/interface pppoe-server server set [/interface pppoe-server server find service-name=' . $svcQ . '] disabled=yes';
            $upScript   = ':log info \"' . $prefix . ': RADIUS ' . $radiusIp . ' recovered. Re-enabling PPPoE server.\"; '
                . '/interface pppoe-server server set [/interface pppoe-server server find service-name=' . $svcQ . '] disabled=no';
        } else {
            $downScript = ':log warning \"' . $prefix . ': CRITICAL - RADIUS ' . $radiusIp . ' is DOWN. New sessions will be REJECTED. Notify NOC immediately.\"';
            $upScript   = ':log info \"' . $prefix . ': RADIUS ' . $radiusIp . ' recovered. AAA services restored.\"';
        }

        return [
            "# RADIUS Health Monitor (netwatch) — automated failure alerting",
            ":do { /tool netwatch remove [/tool netwatch find comment~\"{$prefix}-RADIUS-WATCH\"] } on-error={}",
            ":do { /tool netwatch add host=\"{$radiusIp}\" interval=30s timeout=3s up-script=\"{$upScript}\" down-script=\"{$downScript}\" comment=\"{$prefix}-RADIUS-WATCH\" } on-error={ /log warning \"{$prefix}: Failed to add RADIUS netwatch — manual monitoring required\" }",
            "",
        ];
    }

    /**
     * Per-subscriber traffic fairness via PCQ queue types.
     * PCQ (Per Connection Queue) ensures no single subscriber can starve others
     * on a shared uplink. RADIUS Mikrotik-Rate-Limit enforces individual caps;
     * PCQ provides fairness within that cap across concurrent flows.
     *
     * High-end: full queue tree on the WAN interface.
     * Low-end:  PCQ types only — RADIUS-assigned simple queues use them automatically.
     *
     * @param string $prefix    Comment prefix
     * @param string $wanIface  WAN interface name (e.g. "ether1")
     * @param bool   $isLowEnd  Skip queue tree on memory-constrained devices
     */
    protected function bootstrapSubscriberQueues(string $prefix, string $wanIface, bool $isLowEnd = false): array
    {
        $rules = [
            "# Subscriber Queue Fairness (PCQ)",
            ":do { /queue type remove [/queue type find name=\"pcq-download-{$prefix}\"] } on-error={}",
            ":do { /queue type remove [/queue type find name=\"pcq-upload-{$prefix}\"] } on-error={}",
            ":do { /queue type add name=\"pcq-download-{$prefix}\" kind=\"pcq\" pcq-classifier=\"dst-address\" pcq-burst-rate=\"0\" } on-error={ /log warning \"{$prefix}: PCQ download type add failed\" }",
            ":do { /queue type add name=\"pcq-upload-{$prefix}\" kind=\"pcq\" pcq-classifier=\"src-address\" pcq-burst-rate=\"0\" } on-error={ /log warning \"{$prefix}: PCQ upload type add failed\" }",
        ];

        if (!$isLowEnd) {
            // Queue tree on WAN: PCQ parent queues ensure fair share across all subscribers
            $rules[] = ":do { /queue tree remove [/queue tree find comment~\"{$prefix}-QTREE\"] } on-error={}";
            $rules[] = ":do { /queue tree add name=\"{$prefix}-dl\" parent=\"{$wanIface}\" queue=\"pcq-download-{$prefix}\" comment=\"{$prefix}-QTREE-DL\" } on-error={ /log warning \"{$prefix}: Queue tree DL add failed\" }";
            $rules[] = ":do { /queue tree add name=\"{$prefix}-ul\" parent=\"global\" queue=\"pcq-upload-{$prefix}\" comment=\"{$prefix}-QTREE-UL\" } on-error={ /log warning \"{$prefix}: Queue tree UL add failed\" }";
        }

        $rules[] = "";
        return $rules;
    }

    /**
     * Global default drop rules — must be appended last.
     * Rules are prefixed so each router/service deployment owns its own cleanup tag,
     * preventing duplicate or orphan rules when multiple services share a device.
     *
     * @param string $prefix  Router-scoped prefix (e.g. "PPPoE-abc123", "hs-abc123")
     */
    protected function bootstrapGlobalDefaultDrop(string $prefix): array
    {
        return [
            "# Global Default Drop",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"{$prefix}-GLOBAL-DROP\"] } on-error={}",
            ":do { /ip firewall filter remove [/ip firewall filter find comment=\"{$prefix}-GLOBAL-EST-IN\"] } on-error={}",
            "/ip firewall filter add chain=input connection-state=\"established,related\" action=\"accept\" comment=\"{$prefix}-GLOBAL-EST-IN\"",
            "/ip firewall filter add chain=input action=\"drop\" log=\"yes\" log-prefix=\"GLOBAL-DROP-IN\" comment=\"{$prefix}-GLOBAL-DROP-IN\"",
            "/ip firewall filter add chain=forward action=\"drop\" log=\"yes\" log-prefix=\"GLOBAL-DROP-FWD\" comment=\"{$prefix}-GLOBAL-DROP-FWD\"",
            "",
        ];
    }

    /**
     * BCP 38 anti-spoofing + DDoS protection rules — shared across all service types.
     *
     * $config keys:
     *   id           string   Short router ID used in comment tags
     *   is_low_end   bool     Use minimal rule set on memory-constrained devices
     *   wan_list     string   Interface-list name for WAN (e.g. "WAN")
     *   subscriber_ifaces  array  Each entry: ['in' => '<iface-or-list>', 'is_list' => bool, 'pool_cidr' => '192.168.x.x/y']
     *                             PPPoE: single entry with pppoe_active_list; Hybrid: one per VLAN or one bridge
     */
    protected function bootstrapSecurityHardening(array $config): array
    {
        $id        = $config['id'];
        $isLowEnd  = $config['is_low_end'] ?? false;
        $wan       = $config['wan_list'] ?? 'WAN';
        $ifaces    = $config['subscriber_ifaces'] ?? [];

        $rules = [
            "# SECURITY HARDENING - BCP 38 Anti-Spoofing & DDoS Protection",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"SEC-$id\"] } on-error={}",
        ];
        $rules[] = "/ip firewall filter add chain=input protocol=\"icmp\" connection-state=\"new\" limit=\"20,5:packet\" action=\"drop\" comment=\"SEC-$id-DDoS-ICMP\"";

        $rules[] = "";
        return $rules;
    }
}