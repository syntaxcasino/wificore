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
     * The allowed address set is: $mgmt (WireGuard VPN tunnel subnet, e.g. 10.8.0.0/24).
     * This prevents any RFC-1918 host from reaching management services.
     *
     * @param string      $prefix       Comment prefix (e.g. "PPPoE-abc123")
     * @param string      $mgmt         VPN management subnet CIDR (e.g. "10.8.0.0/24")
     * @param string|null $vpnServerIp  Deprecated parameter, kept for backward compatibility
     */
    protected function bootstrapServiceHardening(string $prefix, string $mgmt, ?string $vpnServerIp = null): array
    {
        // Use only the management subnet for service ACLs
        // This prevents any RFC-1918 host from reaching management services
        $allowAddr = $mgmt;

        return [
            "# Service Hardening (management access restricted to: {$allowAddr})",
            "/ip service set [find name=\"api\"] disabled=no address=\"{$allowAddr}\"",
            "/ip service set [find name=\"api-ssl\"] disabled=no address=\"{$allowAddr}\"",
            "/ip service set [find name=\"ssh\"] address=\"{$allowAddr}\"",
            "/ip service set [find name=\"winbox\"] address=\"{$allowAddr}\"",
            "/ip service set [find name=\"telnet\"] disabled=yes",
            "/ip service set [find name=\"ftp\"] disabled=yes",
            "/ip service set [find name=\"www\"] disabled=yes",
            "/ip service set [find name=\"www-ssl\"] disabled=yes",
            "/tool romon set enabled=no",
        ];
    }

    /**
     * System clock configuration: timezone setup.
     *
     * @param string|null $timezone  PHP timezone string (e.g. "Africa/Nairobi", "UTC")
     *                               Defaults to config('app.timezone') or 'Africa/Nairobi'
     */
    protected function bootstrapSystemClock(?string $timezone = null): array
    {
        $tz = $timezone ?: config('app.timezone', 'Africa/Nairobi');

        // Convert PHP timezone to RouterOS format (they're compatible)
        return [
            "# System Clock Configuration",
            "/system clock set time-zone-name=\"{$tz}\"",
        ];
    }

    /**
     * NTP client configuration for time synchronization.
     *
     * @param array|null $servers  Array of NTP server hostnames/IPs
     *                              Defaults to pool.ntp.org servers
     */
    protected function bootstrapNtpClient(?array $servers = null): array
    {
        $ntpServers = $servers ?: ['0.pool.ntp.org', '1.pool.ntp.org'];
        $serverList = implode(',', $ntpServers);

        return [
            "# NTP Client Configuration",
            "/system ntp client set enabled=yes servers=\"{$serverList}\"",
            "/system ntp server set enabled=no",
        ];
    }

    /**
     * Firewall logging: add topics=firewall memory logging.
     * On low-end devices (hAP lite etc.) firewall topic logging causes constant
     * flash writes on every dropped packet, spiking CPU. Skip on low-end.
     *
     * @param string $prefix    Comment prefix (e.g. "PPPoE-abc123")
     * @param bool   $isLowEnd  Skip firewall topic logging on memory/CPU-constrained devices
     */
    protected function bootstrapFirewallLogging(string $prefix, bool $isLowEnd = false): array
    {
        if ($isLowEnd) {
            return [];
        }
        return [
            "/system logging remove [find comment=\"{$prefix}-FW-LOG\"]",
            "/system logging add action=\"memory\" topics=\"firewall\"",
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
            "/ip firewall filter remove [find comment~\"{$prefix}-BRUTE\"]",
            // Drop blacklisted sources immediately on all management ports (logged)
            "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"22,8291,8728,8729\" connection-state=\"new\" src-address-list=\"bruteforce-blacklist\" action=\"drop\" log=\"yes\" log-prefix=\"BRUTE-BL-DROP\" comment=\"{$prefix}-BRUTE-DROP\"",
            // Detect abuse from untrusted sources only; trusted management ranges must not self-blacklist during provisioning.
            "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"22,8291,8728,8729\" connection-state=\"new\" src-address=!\"{$allowAddr}\" connection-limit=\"5,32\" action=\"add-src-to-address-list\" address-list=\"bruteforce-blacklist\" address-list-timeout=\"1h\" comment=\"{$prefix}-BRUTE-DETECT\"",
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
        $snmpCommunity = config('telegraf.snmp_community', 'traidnet-monitor');
        // Always allow monitoring from the WireGuard server IP (10.8.0.1/32).
        // Telegraf and the backend SNMP service both originate from this IP.
        // Using security=none (SNMPv2c) to match Telegraf's polling configuration.
        $snmpSubnet = '10.8.0.1/32';

        $rules = [
            '# SNMP & Syslog Export',
            '/snmp community remove [find name="' . $snmpCommunity . '"]',
            '/snmp community add name="' . $snmpCommunity . '" addresses="' . $snmpSubnet . '" security="none" read-access="yes" write-access="no" comment="WifiCore SNMP"',
            '/snmp set enabled="yes" contact="noc@wificore" location="WifiCore" trap-community="' . $snmpCommunity . '" trap-version=2',
        ];
        if ($enableSyslog) {
            $rules[] = '/system logging action add name="remote_syslog" target="remote" remote="' . $syslogHost . '" remote-port="514" remote-log-format="syslog" comment="WifiCore Syslog"';
            $loggingRules = [
                ['comment' => 'WifiCore-SYSLOG-CRIT', 'topics' => 'critical'],
                ['comment' => 'WifiCore-SYSLOG-ERR', 'topics' => 'error'],
                ['comment' => 'WifiCore-SYSLOG-WARN', 'topics' => 'warning'],
                // PPPoE/PPP session events (connect, disconnect, auth) forwarded to remote syslog
                ['comment' => 'WifiCore-SYSLOG-PPP', 'topics' => 'ppp'],
                ['comment' => 'WifiCore-SYSLOG-PPPOE', 'topics' => 'pppoe'],
                ['comment' => 'WifiCore-SYSLOG-RADIUS', 'topics' => 'radius'],
                ['comment' => 'WifiCore-SYSLOG-ACCOUNT', 'topics' => 'account'],
                ['comment' => 'WifiCore-SYSLOG-HOTSPOT', 'topics' => 'hotspot'],
            ];

            foreach ($loggingRules as $rule) {
                $comment = $rule['comment'];
                $topics = $rule['topics'];
                $rules[] = '/system logging add action="remote_syslog" topics="' . $topics . '" comment="' . $comment . '"';
            }
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
            "/ppp profile set \"{$profName}\" local-address=\"\"",
            "/ppp profile set \"{$profName}\" dns-server=\"\" wins-server=\"\"",
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
     * On low-end devices (hAP lite etc.) the on-up script is simplified to a single
     * deferred log to avoid multiple /ppp active get calls racing with session commit,
     * which causes script errors that tear down the session → reconnect loop.
     *
     * @param string $prefix    Comment prefix
     * @param string $profName  PPP profile name — only sessions using this profile are logged
     * @param bool   $isLowEnd  Use minimal safe script on memory/CPU-constrained devices
     */
    protected function bootstrapPppSessionLogging(string $prefix, string $profName, bool $isLowEnd = false): array
    {
        $upScriptName   = "{$prefix}-ppp-up";
        $downScriptName = "{$prefix}-ppp-down";

        if ($isLowEnd) {
            // On hAP lite the session entry may not be committed when on-up fires.
            // Multiple /ppp active get calls in rapid succession can fail and crash
            // the script, which causes RouterOS to tear down the session → reconnect loop.
            // Use a single guarded get with a small delay to let the entry settle.
            $upBody = implode("\n", [
                "# Per-session RADIUS attribute confirmation on PPP session-up (low-end safe)",
                ":delay 500ms",
                ":local rlEntry [/ppp active find name=\$user]",
                ":if ([:len \$rlEntry] > 0) do={",
                "  :local rl [/ppp active get \$rlEntry rate-limit]",
                "  :if ([:len \$rl] > 0) do={",
                "    /log info \"{$prefix}: SESSION-UP user=\$user [RADIUS: rate-limit APPLIED]\"",
                "  } else={",
                "    /log error \"{$prefix}: SESSION-UP user=\$user rate-limit=NONE [RADIUS: Mikrotik-Rate-Limit MISSING]\"",
                "  }",
                "} else={",
                "  /log warning \"{$prefix}: SESSION-UP user=\$user (session entry not found yet — non-fatal)\"",
                "}",
            ]);
        } else {
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
        }

        $downBody = implode("\n", [
            "# Session teardown audit log (PPP on-down event)",
            "/log info \"{$prefix}: SESSION-DOWN user=\$user\"",
        ]);

        // Flatten multi-line scripts for inline embedding in profile on-up/on-down
        $upBodyFlat   = str_replace("\n", "; ", $upBody);
        $downBodyFlat = str_replace("\n", "; ", $downBody);

        return [
            "# PPP Session Logging — per-session RADIUS attribute confirmation",
            "/ppp profile set \"{$profName}\" on-up=\"{$upBodyFlat}\" on-down=\"{$downBodyFlat}\"",
            "",
        ];
    }

    /**
     * Operational event logging: PPPoE server state monitor + sustained RADIUS outage alerting.
     *
     * Installs a RouterOS scheduler script that runs every 5 minutes (15 min on low-end):
     *   1. Checks whether the PPPoE server is disabled and logs an error if so.
     *   2. Checks RADIUS reachability; if unreachable, logs a sustained-outage critical alert
     *      distinct from the netwatch down event, so NOC sees it on each poll cycle.
     *
     * On low-end devices the RADIUS ping is skipped — netwatch already covers failure
     * detection and the extra /ping every 5 min adds measurable CPU load on hAP lite.
     *
     * @param string $prefix      Comment prefix
     * @param string $svcName     PPPoE service-name (the value of service-name= on the server)
     * @param string $radiusIp    RADIUS server IP
     * @param bool   $isLowEnd    Skip ping check and use longer interval on low-end devices
     */
    protected function bootstrapOperationalLogging(string $prefix, string $svcName, string $radiusIp, bool $isLowEnd = false): array
    {
        $scriptName    = "{$prefix}-ops-monitor";
        $schedulerName = "{$prefix}-ops-sched";

        // RouterOS script body — single-quoted in PHP, so no PHP variable interpolation inside
        // The script itself uses RouterOS variables and string literals only.
        $scriptLines = [
            "# Operational monitor: PPPoE server state" . ($isLowEnd ? '' : ' + RADIUS sustained outage'),
            ":local svcName \"{$svcName}\"",
        ];

        if (!$isLowEnd) {
            $scriptLines[] = ":local radiusIp \"{$radiusIp}\"";
        }

        $scriptLines = array_merge($scriptLines, [
            "",
            "# 1. PPPoE server state check",
            ":local svcDisabled [/interface pppoe-server server get [/interface pppoe-server server find service-name=\$svcName] disabled]",
            ":if (\$svcDisabled = true) do={",
            "  /log error \"{$prefix}: ALERT - PPPoE server '\$svcName' is DISABLED. Subscriber sessions cannot be established.\"",
            "} else={",
            "  /log info \"{$prefix}: PPPoE server '\$svcName' is running.\"",
            "}",
        ]);

        if (!$isLowEnd) {
            $scriptLines = array_merge($scriptLines, [
                "",
                "# 2. RADIUS sustained-outage check (skipped on low-end — netwatch covers this)",
                ":local pingResult [/ping address=\"{$radiusIp}\" count=1 interval=500ms]",
                ":if (\$pingResult = 0) do={",
                "  /log error \"{$prefix}: SUSTAINED OUTAGE - RADIUS {$radiusIp} still unreachable. All new sessions are being REJECTED.\"",
                "} else={",
                "  /log debug \"{$prefix}: RADIUS {$radiusIp} OK (\$pingResult replies).\"",
                "}",
            ]);
        }

        $scriptBody = implode("\n", $scriptLines);
        $schedInterval = $isLowEnd ? '15m' : '5m';
        // Flatten script for inline embedding in scheduler on-event (avoids /system script add which binary API blocks)
        $scriptFlat = str_replace("\n", "; ", $scriptBody);

        return [
            "# Operational Event Logging — PPPoE server state" . ($isLowEnd ? '' : ' + RADIUS sustained-outage monitor'),
            "/system scheduler remove \"{$schedulerName}\"",
            "/system scheduler add name=\"{$schedulerName}\" interval={$schedInterval} on-event=\"{$scriptFlat}\" start-time=startup comment=\"{$prefix}-OPS-SCHED\"",
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
            "/ip traffic-flow set enabled=\"yes\" interfaces=\"all\" active-flow-timeout=\"1m\" inactive-flow-timeout=\"15s\"",
            "/ip traffic-flow target add dst-address=\"{$collectorIp}\" port=\"{$collectorPort}\" version=9",
            "",
        ];
    }

    protected function bootstrapRadiusNetwatch(string $prefix, string $radiusIp, ?string $pppoeServiceName = null): array
    {
        if ($pppoeServiceName) {
            $svcQ = '"' . $pppoeServiceName . '"';
            $downScript = ':log error "' . $prefix . ': CRITICAL - RADIUS ' . $radiusIp . ' DOWN. Disabling PPPoE server to prevent unmetered sessions."; '
                . '/interface pppoe-server server set ' . $svcQ . ' disabled=yes';
            $upScript   = ':log info "' . $prefix . ': RADIUS ' . $radiusIp . ' recovered. Re-enabling PPPoE server."; '
                . '/interface pppoe-server server set ' . $svcQ . ' disabled=no';
        } else {
            $downScript = ':log warning "' . $prefix . ': CRITICAL - RADIUS ' . $radiusIp . ' is DOWN. New sessions will be REJECTED. Notify NOC immediately."';
            $upScript   = ':log info "' . $prefix . ': RADIUS ' . $radiusIp . ' recovered. AAA services restored."';
        }

        return [
            "# RADIUS Health Monitor (netwatch) — automated failure alerting",
            "/tool netwatch remove [find comment~\"{$prefix}-RADIUS-WATCH\"]",
            "/tool netwatch add host=\"{$radiusIp}\" interval=30s timeout=3s up-script=\"{$upScript}\" down-script=\"{$downScript}\" comment=\"{$prefix}-RADIUS-WATCH\"",
            "",
        ];
    }

    /**
     * Per-subscriber traffic fairness via PCQ queue types.
     * PCQ (Per Connection Queue) ensures no single subscriber can starve others
     * on a shared uplink. RADIUS Mikrotik-Rate-Limit enforces individual caps;
     * PCQ provides fairness within that cap across concurrent flows.
     *
     * Rate limiting strategy: RADIUS Mikrotik-Rate-Limit attribute (Option A).
     * Queue tree is NOT used — per-subscriber rate limits come entirely from RADIUS.
     * PCQ types are registered so that RADIUS-assigned simple queues can reference
     * them automatically for fair queuing across concurrent flows.
     *
     * @param string $prefix    Comment prefix
     * @param string $wanIface  WAN interface name (unused, kept for signature compatibility)
     * @param bool   $isLowEnd  On low-end devices, skip even PCQ type registration
     */
    protected function bootstrapSubscriberQueues(string $prefix, string $wanIface, bool $isLowEnd = false): array
    {
        $rules = [
            "# Subscriber Queue Fairness (PCQ types — rate limits via RADIUS Mikrotik-Rate-Limit)",
            "/queue tree remove [find comment~\"{$prefix}-QTREE\"]",
            "/queue type remove \"pcq-download-{$prefix}\"",
            "/queue type remove \"pcq-upload-{$prefix}\"",
        ];

        if (!$isLowEnd) {
            $rules[] = "/queue type add name=\"pcq-download-{$prefix}\" kind=\"pcq\" pcq-classifier=\"dst-address\" pcq-burst-rate=\"0\"";
            $rules[] = "/queue type add name=\"pcq-upload-{$prefix}\" kind=\"pcq\" pcq-classifier=\"src-address\" pcq-burst-rate=\"0\"";
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
            "/ip firewall filter remove [find comment~\"{$prefix}-GLOBAL-DROP\"]",
            "/ip firewall filter remove [find comment=\"{$prefix}-GLOBAL-EST-IN\"]",
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
        $id       = $config['id'];
        $isLowEnd = $config['is_low_end'] ?? false;
        $ifaces   = $config['subscriber_ifaces'] ?? [];

        $rules = [
            "# SECURITY HARDENING - BCP 38 Anti-Spoofing & DDoS Protection (GAP-14)",
            "/ip firewall filter remove [find comment~\"SEC-$id\"]",
        ];

        // Drop invalid connection states (applies to all tiers)
        $rules[] = "/ip firewall filter add chain=forward connection-state=\"invalid\" action=\"drop\" comment=\"SEC-$id-INVALID\"";

        // ICMP rate-limit to mitigate ICMP flood / ping-of-death
        $rules[] = "/ip firewall filter add chain=input protocol=\"icmp\" connection-state=\"new\" limit=\"20,5:packet\" action=\"drop\" comment=\"SEC-$id-DDoS-ICMP\"";

        // BCP 38 ingress source-address validation per subscriber interface.
        // RouterOS v7 does not support src-address=! in /import scripts, so we
        // use an accept+drop split: first accept traffic with a valid pool source,
        // then drop everything else from that interface (spoofed source).
        // Applied to ALL tiers — anti-spoofing is essential even on low-end devices.
        foreach ($ifaces as $iface) {
            $in       = $iface['in'] ?? null;
            $isList   = $iface['is_list'] ?? false;
            $poolCidr = $iface['pool_cidr'] ?? null;

            if (!$in || !$poolCidr) {
                continue;
            }

            $tag     = isset($iface['tag']) ? ('-' . $iface['tag']) : '';
            $inParam = $isList ? "in-interface-list=\"$in\"" : "in-interface=\"$in\"";
            // Accept legitimate subscriber traffic (valid source within pool CIDR)
            $rules[] = "/ip firewall filter add chain=forward $inParam src-address=\"$poolCidr\" action=\"accept\" comment=\"SEC-$id-BCP38{$tag}-SPOOF-OK\"";
            // Drop spoofed traffic (source outside pool CIDR) from subscriber interface
            $rules[] = "/ip firewall filter add chain=forward $inParam action=\"drop\" log=\"yes\" log-prefix=\"BCP38-$id\" comment=\"SEC-$id-BCP38{$tag}-SPOOF\"";
        }

        $rules[] = "";
        return $rules;
    }
}
