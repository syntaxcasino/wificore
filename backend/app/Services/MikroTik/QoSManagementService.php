<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\TenantAwareService;
use Illuminate\Support\Facades\Log;

/**
 * QoS/Bandwidth Management Service
 * 
 * Provides Quality of Service configuration for MikroTik routers
 * including PCQ, priority queuing, traffic shaping, and burst management.
 */
class QoSManagementService extends TenantAwareService
{
    /**
     * Generate complete QoS configuration
     * 
     * @param Router $router
     * @param array $config
     * @return string
     */
    public function generateQoSConfiguration(Router $router, array $config = []): string
    {
        $totalBandwidth = $config['total_bandwidth'] ?? '1G/1G'; // Download/Upload
        $enableBurst = $config['enable_burst'] ?? true;
        $enablePCQ = $config['enable_pcq'] ?? true;
        
        $script = $this->generateScriptHeader($router);
        
        if ($enablePCQ) {
            $script .= $this->generatePCQConfiguration();
        }
        
        $script .= $this->generateMangleRules();
        $script .= $this->generateQueueTree($totalBandwidth, $enableBurst);
        $script .= $this->generateSimpleQueues($config);
        
        return $script;
    }
    
    /**
     * Generate script header
     */
    private function generateScriptHeader(Router $router): string
    {
        return <<<SCRIPT
# ============================================================
# WiFiCore QoS/Bandwidth Management Configuration
# Router: {$router->name}
# Generated: {now()->toDateTimeString()}
# ============================================================

:log info "Starting QoS configuration"


SCRIPT;
    }
    
    /**
     * Generate PCQ (Per Connection Queue) configuration
     */
    private function generatePCQConfiguration(): string
    {
        return <<<'SCRIPT'
# ============================================================
# 1. PCQ CONFIGURATION - Fair bandwidth distribution
# ============================================================

:log info "Configuring PCQ"

# PCQ for download (per destination address)
/queue type
:if ([:len [find name="pcq-download"]] = 0) do={
    add name=pcq-download \
        kind=pcq \
        pcq-rate=0 \
        pcq-limit=50 \
        pcq-classifier=dst-address \
        pcq-total-limit=2000 \
        comment="PCQ for download traffic"
}

# PCQ for upload (per source address)
:if ([:len [find name="pcq-upload"]] = 0) do={
    add name=pcq-upload \
        kind=pcq \
        pcq-rate=0 \
        pcq-limit=50 \
        pcq-classifier=src-address \
        pcq-total-limit=2000 \
        comment="PCQ for upload traffic"
}

# PCQ for download with burst
:if ([:len [find name="pcq-download-burst"]] = 0) do={
    add name=pcq-download-burst \
        kind=pcq \
        pcq-rate=0 \
        pcq-limit=50 \
        pcq-classifier=dst-address \
        pcq-total-limit=2000 \
        pcq-burst-rate=0 \
        pcq-burst-threshold=0 \
        pcq-burst-time=10s \
        comment="PCQ for download with burst"
}

# PCQ for upload with burst
:if ([:len [find name="pcq-upload-burst"]] = 0) do={
    add name=pcq-upload-burst \
        kind=pcq \
        pcq-rate=0 \
        pcq-limit=50 \
        pcq-classifier=src-address \
        pcq-total-limit=2000 \
        pcq-burst-rate=0 \
        pcq-burst-threshold=0 \
        pcq-burst-time=10s \
        comment="PCQ for upload with burst"
}

:log info "PCQ configured"


SCRIPT;
    }
    
    /**
     * Generate mangle rules for packet marking
     */
    private function generateMangleRules(): string
    {
        return <<<'SCRIPT'
# ============================================================
# 2. MANGLE RULES - Packet classification and marking
# ============================================================

:log info "Configuring mangle rules"

/ip firewall mangle

# ============================================================
# 2.1. HIGH PRIORITY - VoIP, Gaming, DNS
# ============================================================

# VoIP traffic (SIP, RTP)
add chain=prerouting protocol=udp dst-port=5060,5061 \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="VoIP SIP signaling"

add chain=prerouting protocol=udp dst-port=10000-20000 \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="VoIP RTP media"

# Gaming traffic (common game ports)
add chain=prerouting protocol=udp dst-port=27000-27050 \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="Gaming - Steam/Source"

add chain=prerouting protocol=udp dst-port=3074,3075 \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="Gaming - Xbox Live"

add chain=prerouting protocol=udp dst-port=3478-3479 \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="Gaming - PlayStation"

# DNS queries (fast response)
add chain=prerouting protocol=udp dst-port=53 \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="DNS queries"

# ICMP (ping, traceroute)
add chain=prerouting protocol=icmp \
    action=mark-packet new-packet-mark=high-priority passthrough=yes \
    comment="ICMP traffic"

# ============================================================
# 2.2. NORMAL PRIORITY - Web, Streaming, Email
# ============================================================

# HTTP/HTTPS traffic
add chain=prerouting protocol=tcp dst-port=80,443 \
    action=mark-packet new-packet-mark=normal-priority passthrough=yes \
    comment="Web traffic (HTTP/HTTPS)"

# Streaming services
add chain=prerouting protocol=tcp dst-port=1935 \
    action=mark-packet new-packet-mark=normal-priority passthrough=yes \
    comment="RTMP streaming"

add chain=prerouting protocol=udp dst-port=1935 \
    action=mark-packet new-packet-mark=normal-priority passthrough=yes \
    comment="RTMP streaming (UDP)"

# Email protocols
add chain=prerouting protocol=tcp dst-port=25,110,143,587,993,995 \
    action=mark-packet new-packet-mark=normal-priority passthrough=yes \
    comment="Email traffic"

# ============================================================
# 2.3. BULK PRIORITY - Downloads, P2P, FTP
# ============================================================

# FTP traffic
add chain=prerouting protocol=tcp dst-port=20,21 \
    action=mark-packet new-packet-mark=bulk-priority passthrough=yes \
    comment="FTP traffic"

# BitTorrent traffic
add chain=prerouting protocol=tcp dst-port=6881-6889 \
    action=mark-packet new-packet-mark=bulk-priority passthrough=yes \
    comment="BitTorrent"

add chain=prerouting protocol=udp dst-port=6881-6889 \
    action=mark-packet new-packet-mark=bulk-priority passthrough=yes \
    comment="BitTorrent (UDP)"

# Large file downloads (connection-bytes based)
add chain=prerouting protocol=tcp connection-bytes=10000000-4294967295 \
    action=mark-packet new-packet-mark=bulk-priority passthrough=yes \
    comment="Large downloads (>10MB)"

# ============================================================
# 2.4. MARK CONNECTIONS for easier tracking
# ============================================================

add chain=prerouting packet-mark=high-priority \
    action=mark-connection new-connection-mark=high-priority-conn passthrough=yes \
    comment="Mark high priority connections"

add chain=prerouting packet-mark=normal-priority \
    action=mark-connection new-connection-mark=normal-priority-conn passthrough=yes \
    comment="Mark normal priority connections"

add chain=prerouting packet-mark=bulk-priority \
    action=mark-connection new-connection-mark=bulk-priority-conn passthrough=yes \
    comment="Mark bulk priority connections"

:log info "Mangle rules configured"


SCRIPT;
    }
    
    /**
     * Generate queue tree with priority levels
     */
    private function generateQueueTree(string $totalBandwidth, bool $enableBurst): string
    {
        list($downloadLimit, $uploadLimit) = explode('/', $totalBandwidth);
        
        $burstConfig = $enableBurst ? 
            "burst-limit=150M/150M burst-threshold=100M/100M burst-time=8s/8s" : 
            "burst-limit=0/0 burst-threshold=0/0 burst-time=0s/0s";
        
        return <<<SCRIPT
# ============================================================
# 3. QUEUE TREE - Hierarchical bandwidth management
# ============================================================

:log info "Configuring queue tree"

/queue tree

# ============================================================
# 3.1. GLOBAL PARENT QUEUES
# ============================================================

# Global download queue
:if ([:len [find name="global-download"]] = 0) do={
    add name=global-download \
        parent=global \
        queue=default \
        priority=8 \
        max-limit={$downloadLimit} \
        comment="Total download bandwidth"
}

# Global upload queue
:if ([:len [find name="global-upload"]] = 0) do={
    add name=global-upload \
        parent=global \
        queue=default \
        priority=8 \
        max-limit={$uploadLimit} \
        comment="Total upload bandwidth"
}

# ============================================================
# 3.2. DOWNLOAD PRIORITY QUEUES
# ============================================================

# High priority download (VoIP, Gaming, DNS)
:if ([:len [find name="download-high"]] = 0) do={
    add name=download-high \
        parent=global-download \
        queue=default \
        priority=1 \
        max-limit=500M \
        packet-mark=high-priority \
        {$burstConfig} \
        comment="High priority download (VoIP, Gaming)"
}

# Normal priority download (Web, Streaming)
:if ([:len [find name="download-normal"]] = 0) do={
    add name=download-normal \
        parent=global-download \
        queue=pcq-download-burst \
        priority=4 \
        max-limit=800M \
        packet-mark=normal-priority \
        comment="Normal priority download (Web, Streaming)"
}

# Bulk priority download (Downloads, P2P)
:if ([:len [find name="download-bulk"]] = 0) do={
    add name=download-bulk \
        parent=global-download \
        queue=pcq-download \
        priority=8 \
        max-limit=300M \
        packet-mark=bulk-priority \
        comment="Bulk priority download (P2P, FTP)"
}

# ============================================================
# 3.3. UPLOAD PRIORITY QUEUES
# ============================================================

# High priority upload
:if ([:len [find name="upload-high"]] = 0) do={
    add name=upload-high \
        parent=global-upload \
        queue=default \
        priority=1 \
        max-limit=500M \
        packet-mark=high-priority \
        {$burstConfig} \
        comment="High priority upload (VoIP, Gaming)"
}

# Normal priority upload
:if ([:len [find name="upload-normal"]] = 0) do={
    add name=upload-normal \
        parent=global-upload \
        queue=pcq-upload-burst \
        priority=4 \
        max-limit=800M \
        packet-mark=normal-priority \
        comment="Normal priority upload (Web)"
}

# Bulk priority upload
:if ([:len [find name="upload-bulk"]] = 0) do={
    add name=upload-bulk \
        parent=global-upload \
        queue=pcq-upload \
        priority=8 \
        max-limit=300M \
        packet-mark=bulk-priority \
        comment="Bulk priority upload (P2P, FTP)"
}

:log info "Queue tree configured"


SCRIPT;
    }
    
    /**
     * Generate simple queues for per-user limits (optional)
     */
    private function generateSimpleQueues(array $config): string
    {
        $enablePerUserLimits = $config['enable_per_user_limits'] ?? false;
        
        if (!$enablePerUserLimits) {
            return <<<'SCRIPT'
# ============================================================
# 4. SIMPLE QUEUES - Per-user limits (disabled)
# ============================================================

# Simple queues disabled - using queue tree with PCQ
# RADIUS can set per-user limits via Mikrotik-Rate-Limit attribute

:log info "QoS configuration completed"

SCRIPT;
        }
        
        return <<<'SCRIPT'
# ============================================================
# 4. SIMPLE QUEUES - Per-user bandwidth limits
# ============================================================

:log info "Configuring simple queues"

# Note: These are examples. RADIUS should set per-user limits
# via Mikrotik-Rate-Limit attribute for dynamic management

# Example: Default queue for Hotspot users
/queue simple
:if ([:len [find name="hotspot-default"]] = 0) do={
    add name=hotspot-default \
        target=192.168.88.0/24 \
        max-limit=10M/10M \
        burst-limit=15M/15M \
        burst-threshold=8M/8M \
        burst-time=8s/8s \
        priority=8/8 \
        queue=pcq-download-burst/pcq-upload-burst \
        disabled=yes \
        comment="Default Hotspot queue (disabled - RADIUS controls)"
}

# Example: Default queue for PPPoE users
:if ([:len [find name="pppoe-default"]] = 0) do={
    add name=pppoe-default \
        target=10.10.10.0/24 \
        max-limit=20M/20M \
        burst-limit=30M/30M \
        burst-threshold=15M/15M \
        burst-time=8s/8s \
        priority=8/8 \
        queue=pcq-download-burst/pcq-upload-burst \
        disabled=yes \
        comment="Default PPPoE queue (disabled - RADIUS controls)"
}

:log info "Simple queues configured"

# ============================================================
# QoS CONFIGURATION COMPLETE
# ============================================================

:log info "QoS configuration completed successfully"

SCRIPT;
    }
    
    /**
     * Apply QoS configuration to router
     * 
     * @param Router $router
     * @param array $config
     * @return array
     */
    public function applyQoS(Router $router, array $config = []): array
    {
        $startTime = microtime(true);
        
        Log::info('Applying QoS configuration to router', [
            'router_id' => $router->id,
            'router_name' => $router->name
        ]);
        
        try {
            $script = $this->generateQoSConfiguration($router, $config);
            
            $ssh = new SshExecutor($router, 60);
            $ssh->connect();
            
            $scriptName = "qos_config_{$router->id}_" . time() . ".rsc";
            $tempFile = tempnam(sys_get_temp_dir(), 'qos_');
            file_put_contents($tempFile, $script);
            
            $ssh->uploadFile($tempFile, $scriptName);
            unlink($tempFile);
            
            $result = $ssh->importFile($scriptName);
            
            $ssh->deleteFile($scriptName);
            $ssh->disconnect();
            
            $response = [
                'success' => true,
                'router_id' => $router->id,
                'script_name' => $scriptName,
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'result_preview' => substr($result, 0, 500)
            ];
            
            Log::info('QoS configuration applied successfully', $response);
            
            return $response;
            
        } catch (\Exception $e) {
            Log::error('Failed to apply QoS configuration', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('QoS configuration failed: ' . $e->getMessage(), 500, $e);
        }
    }
    
    /**
     * Generate and save QoS configuration
     * 
     * @param Router $router
     * @param array $config
     * @return string
     */
    public function generateAndSave(Router $router, array $config = []): string
    {
        $script = $this->generateQoSConfiguration($router, $config);
        
        \App\Models\RouterConfig::updateOrCreate(
            [
                'router_id' => $router->id,
                'config_type' => 'qos'
            ],
            [
                'config_content' => $script
            ]
        );
        
        Log::info('QoS configuration generated and saved', [
            'router_id' => $router->id,
            'script_length' => strlen($script)
        ]);
        
        return $script;
    }
}
