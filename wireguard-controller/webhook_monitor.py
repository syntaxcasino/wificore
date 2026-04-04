#!/usr/bin/env python3
"""
WireGuard Peer Webhook Monitor
Monitors WireGuard peer activity and sends webhook events to Laravel backend
for event-based router status updates.

Usage: python3 webhook_monitor.py
Environment variables:
  - WEBHOOK_BASE_URL: Laravel backend URL (default: http://backend:80)
  - WEBHOOK_API_KEY: API key for webhook authentication
  - MONITOR_INTERVAL: Seconds between checks (default: 5)
  - INTERFACE_PREFIX: Interface name prefix to monitor (default: wg)
"""

import os
import time
import json
import logging
import subprocess
from typing import Dict, List
import requests

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Configuration
WEBHOOK_BASE_URL = os.getenv('WEBHOOK_BASE_URL', 'http://backend:80')
WEBHOOK_API_KEY = os.getenv('WEBHOOK_API_KEY', '')
MONITOR_INTERVAL = int(os.getenv('MONITOR_INTERVAL', '5'))
INTERFACE_PREFIX = os.getenv('INTERFACE_PREFIX', 'wg')

# Track last seen handshakes to detect changes
last_handshakes: Dict[str, int] = {}


def run_command(cmd: str) -> tuple[bool, str]:
    """Run shell command and return (success, output)"""
    try:
        result = subprocess.run(
            cmd,
            shell=True,
            capture_output=True,
            text=True,
            check=False
        )
        return result.returncode == 0, result.stdout
    except Exception as e:
        logger.error(f"Command failed: {cmd}, error: {e}")
        return False, ""


def get_wireguard_interfaces() -> List[str]:
    """Get list of WireGuard interfaces"""
    success, output = run_command("wg show interfaces")
    if not success or not output.strip():
        return []
    return [iface.strip() for iface in output.strip().split() if iface.strip().startswith(INTERFACE_PREFIX)]


def get_peer_dump(interface: str) -> List[Dict]:
    """Get peer dump for interface"""
    success, output = run_command(f"wg show {interface} dump")
    if not success or not output.strip():
        return []
    
    peers = []
    lines = output.strip().split('\n')
    
    for line in lines:
        parts = line.split('\t')
        if len(parts) >= 8:
            try:
                peers.append({
                    'public_key': parts[0],
                    'preshared_key': parts[1] if parts[1] != '(none)' else None,
                    'endpoint': parts[2] if parts[2] != '(none)' else None,
                    'allowed_ips': parts[3],
                    'latest_handshake': int(parts[4]) if parts[4] != '0' else 0,
                    'transfer_rx': int(parts[5]),
                    'transfer_tx': int(parts[6]),
                    'persistent_keepalive': int(parts[7]) if parts[7] else None,
                })
            except (ValueError, IndexError) as e:
                logger.warning(f"Failed to parse peer line: {line}, error: {e}")
                continue
    
    return peers


def send_webhook(event_type: str, data: Dict) -> bool:
    """Send webhook event to Laravel backend"""
    if not WEBHOOK_API_KEY:
        logger.warning("WEBHOOK_API_KEY not set, skipping webhook")
        return False
    
    endpoint_map = {
        'handshake': '/api/webhooks/wireguard/peer/handshake',
        'expired': '/api/webhooks/wireguard/peer/expired',
        'batch': '/api/webhooks/wireguard/peers/batch',
    }
    
    endpoint = endpoint_map.get(event_type)
    if not endpoint:
        logger.warning(f"Unknown event type: {event_type}")
        return False
    
    url = f"{WEBHOOK_BASE_URL}{endpoint}"
    headers = {
        'Authorization': f'Bearer {WEBHOOK_API_KEY}',
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    }
    
    try:
        response = requests.post(
            url,
            headers=headers,
            json=data,
            timeout=5
        )
        
        if response.status_code == 200:
            logger.debug(f"Webhook sent successfully: {event_type}")
            return True
        else:
            logger.warning(f"Webhook failed: {response.status_code}, {response.text}")
            return False
            
    except requests.exceptions.RequestException as e:
        logger.error(f"Webhook request failed: {e}")
        return False


def check_peer_changes(interface: str, peers: List[Dict]) -> None:
    """Check for peer handshake changes and send webhooks"""
    global last_handshakes
    
    current_peers: Dict[str, Dict] = {}
    
    for peer in peers:
        public_key = peer['public_key']
        current_handshake = peer['latest_handshake']
        
        current_peers[public_key] = peer
        
        # Check if this is a new handshake
        last_handshake = last_handshakes.get(public_key, 0)
        
        if current_handshake > last_handshake and current_handshake > 0:
            # New handshake detected - router came online
            logger.info(f"New handshake detected for {public_key[:16]}... "
                       f"(age: {int(time.time()) - current_handshake}s)")
            
            send_webhook('handshake', {
                'public_key': public_key,
                'endpoint': peer['endpoint'],
                'allowed_ips': peer['allowed_ips'],
                'latest_handshake': current_handshake,
                'transfer_rx': peer['transfer_rx'],
                'transfer_tx': peer['transfer_tx'],
                'interface': interface,
            })
        
        elif current_handshake == 0 and last_handshake > 0:
            # Handshake cleared - peer might be offline
            logger.info(f"Peer handshake cleared: {public_key[:16]}...")
            
            send_webhook('expired', {
                'public_key': public_key,
                'reason': 'handshake_cleared',
                'interface': interface,
            })
    
    # Check for peers that disappeared (removed from interface)
    current_keys = set(current_peers.keys())
    last_keys = set(last_handshakes.keys())
    disappeared_keys = last_keys - current_keys
    
    for public_key in disappeared_keys:
        if last_handshakes.get(public_key, 0) > 0:
            logger.info(f"Peer disappeared from interface: {public_key[:16]}...")
            
            send_webhook('expired', {
                'public_key': public_key,
                'reason': 'peer_removed',
                'interface': interface,
            })
    
    # Update last handshakes
    last_handshakes = {
        public_key: peer['latest_handshake']
        for public_key, peer in current_peers.items()
    }


def send_batch_update(interfaces: List[str]) -> None:
    """Send batch update with all peers from all interfaces"""
    all_peers = []
    
    for interface in interfaces:
        peers = get_peer_dump(interface)
        for peer in peers:
            peer['interface'] = interface
        all_peers.extend(peers)
    
    if all_peers:
        send_webhook('batch', {'peers': all_peers})


def main():
    """Main monitor loop"""
    logger.info("=" * 60)
    logger.info("WireGuard Peer Webhook Monitor Started")
    logger.info(f"Webhook URL: {WEBHOOK_BASE_URL}")
    logger.info(f"Monitor interval: {MONITOR_INTERVAL}s")
    logger.info(f"Interface prefix: {INTERFACE_PREFIX}")
    logger.info(f"API Key configured: {'Yes' if WEBHOOK_API_KEY else 'No'}")
    logger.info("=" * 60)
    
    if not WEBHOOK_API_KEY:
        logger.error("WEBHOOK_API_KEY environment variable is required!")
        return 1
    
    iteration = 0
    
    while True:
        try:
            iteration += 1
            
            # Get list of interfaces
            interfaces = get_wireguard_interfaces()
            
            if not interfaces:
                if iteration % 10 == 0:  # Log every 10 iterations to avoid spam
                    logger.debug("No WireGuard interfaces found")
                time.sleep(MONITOR_INTERVAL)
                continue
            
            logger.debug(f"Monitoring {len(interfaces)} interface(s): {', '.join(interfaces)}")
            
            # Check each interface
            for interface in interfaces:
                peers = get_peer_dump(interface)
                if peers:
                    check_peer_changes(interface, peers)
            
            # Send batch update every 10 iterations (roughly every 50 seconds)
            if iteration % 10 == 0:
                send_batch_update(interfaces)
            
        except Exception as e:
            logger.error(f"Error in monitor loop: {e}", exc_info=True)
        
        time.sleep(MONITOR_INTERVAL)


if __name__ == '__main__':
    exit(main())
