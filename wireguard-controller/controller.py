#!/usr/bin/env python3
"""
WireGuard Controller Service
Manages WireGuard interfaces and configurations for multi-tenant SaaS
"""

import os
import subprocess
import logging
import json
from flask import Flask, request, jsonify
from datetime import datetime

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

app = Flask(__name__)

# Configuration
API_KEY = os.getenv('WIREGUARD_API_KEY', 'change-me-in-production')
MAX_INTERFACES = 100

def verify_api_key():
    """Verify API key from request headers"""
    auth_header = request.headers.get('Authorization')
    if not auth_header or not auth_header.startswith('Bearer '):
        return False
    token = auth_header.split(' ')[1]
    return token == API_KEY

def run_command(cmd, check=True):
    """Run shell command and return result"""
    try:
        result = subprocess.run(
            cmd,
            shell=True,
            capture_output=True,
            text=True,
            check=check
        )
        # Even with check=False, consider non-zero return code as failure
        success = result.returncode == 0
        
        if not success:
            logger.error(f"Command failed with return code {result.returncode}: {cmd}")
            logger.error(f"STDOUT: {result.stdout}")
            logger.error(f"STDERR: {result.stderr}")
        
        return {
            'success': success,
            'stdout': result.stdout,
            'stderr': result.stderr,
            'returncode': result.returncode
        }
    except subprocess.CalledProcessError as e:
        logger.error(f"Command failed: {cmd}")
        logger.error(f"Error: {e.stderr}")
        return {
            'success': False,
            'stdout': e.stdout,
            'stderr': e.stderr,
            'returncode': e.returncode
        }

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({
        'status': 'healthy',
        'timestamp': datetime.utcnow().isoformat(),
        'service': 'wireguard-controller'
    })

@app.route('/vpn/apply', methods=['POST'])
def apply_config():
    """Apply WireGuard configuration"""
    if not verify_api_key():
        return jsonify({'error': 'Unauthorized'}), 401
    
    data = request.json
    interface = data.get('interface')
    config = data.get('config')
    
    if not interface or not config:
        return jsonify({'error': 'Missing interface or config'}), 400
    
    # Validate interface name
    if not interface.startswith('wg') or len(interface) > 15:
        return jsonify({'error': 'Invalid interface name'}), 400
    
    config_path = f"/etc/wireguard/{interface}.conf"
    
    try:
        # Write config file
        with open(config_path, 'w') as f:
            f.write(config)
        os.chmod(config_path, 0o600)
        
        logger.info(f"Config written to {config_path}")
        
        # Check if interface already exists
        check_result = run_command(f"ip link show {interface}", check=False)
        
        if check_result['returncode'] != 0:
            # Interface doesn't exist, bring it up
            logger.info(f"Creating new interface: {interface}")
            
            # Try wg-quick up first
            result = run_command(f"wg-quick up {interface}", check=False)
            
            if not result['success']:
                # If wg-quick fails, try manual interface creation
                logger.warning(f"wg-quick failed, trying manual creation: {result['stderr']}")
                
                # Create interface manually
                logger.info(f"Step 1: Creating WireGuard interface {interface}")
                link_result = run_command(f"ip link add dev {interface} type wireguard", check=False)
                if not link_result['success']:
                    raise Exception(f"Failed to create interface: {link_result['stderr']}")
                
                logger.info(f"Step 2: Applying WireGuard configuration")
                conf_result = run_command(f"wg setconf {interface} {config_path}", check=False)
                if not conf_result['success']:
                    raise Exception(f"Failed to set configuration: {conf_result['stderr']}")
                
                # Extract IP address from config and set it
                logger.info(f"Step 3: Setting IP address")
                with open(config_path, 'r') as f:
                    for line in f:
                        if line.strip().startswith('Address'):
                            address = line.split('=')[1].strip()
                            addr_result = run_command(f"ip addr add {address} dev {interface}", check=False)
                            if not addr_result['success']:
                                logger.warning(f"Failed to add IP address: {addr_result['stderr']}")
                            break
                
                # Bring interface up
                logger.info(f"Step 4: Bringing interface up")
                result = run_command(f"ip link set {interface} up", check=False)
                
                if not result['success']:
                    raise Exception(f"Failed to bring up interface: {result['stderr']}")
            
            # Verify interface is actually up
            verify_result = run_command(f"ip link show {interface}", check=False)
            if verify_result['success']:
                logger.info(f"Interface {interface} verified on host")
            else:
                logger.error(f"Interface {interface} not found after creation!")
            
            action = 'created'
        else:
            # Interface exists, reload configuration
            logger.info(f"Reloading existing interface: {interface}")
            
            # wg syncconf only accepts peer configuration, not interface settings
            # Extract only the peer sections from the config
            peers_config_path = f"/etc/wireguard/{interface}_peers.conf"
            with open(config_path, 'r') as f:
                lines = f.readlines()
            
            # Write peers-only config (skip [Interface] section)
            with open(peers_config_path, 'w') as f:
                in_interface_section = False
                for line in lines:
                    if line.strip().startswith('[Interface]'):
                        in_interface_section = True
                        continue
                    elif line.strip().startswith('[Peer]'):
                        in_interface_section = False
                    
                    if not in_interface_section:
                        f.write(line)
            
            os.chmod(peers_config_path, 0o600)
            logger.info(f"Peers-only config written to {peers_config_path}")
            
            # Use wg syncconf with peers-only config
            result = run_command(f"wg syncconf {interface} {peers_config_path}", check=False)
            
            if not result['success']:
                # If syncconf fails, try full reload with wg-quick
                logger.warning(f"syncconf failed, trying full reload for {interface}")
                
                # Use wg-quick strip to safely reload
                result = run_command(f"wg-quick strip {interface} | wg syncconf {interface} /dev/stdin", check=False)
                
                if not result['success']:
                    logger.warning(f"Strip/syncconf failed, trying down/up for {interface}")
                    down_result = run_command(f"wg-quick down {interface}", check=False)
                    
                    # Try wg-quick up
                    result = run_command(f"wg-quick up {interface}", check=False)
                    
                    if not result['success']:
                        raise Exception(f"Failed to reload interface: {result['stderr']}")
            
            action = 'reloaded'
        
        # Get interface status
        status_result = run_command(f"wg show {interface}", check=False)
        
        logger.info(f"Interface {interface} {action} successfully")
        
        return jsonify({
            'status': 'success',
            'interface': interface,
            'action': action,
            'output': status_result['stdout']
        })
        
    except Exception as e:
        logger.error(f"Failed to apply config for {interface}: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/vpn/status/<interface>', methods=['GET'])
def get_status(interface):
    """Get WireGuard interface status"""
    if not verify_api_key():
        return jsonify({'error': 'Unauthorized'}), 401
    
    try:
        # Check if interface exists
        check_result = run_command(f"ip link show {interface}", check=False)
        
        if check_result['returncode'] != 0:
            return jsonify({
                'status': 'down',
                'interface': interface,
                'exists': False
            }), 404
        
        # Get WireGuard status
        wg_result = run_command(f"wg show {interface}", check=False)
        
        # Get interface details
        ip_result = run_command(f"ip addr show {interface}", check=False)
        
        return jsonify({
            'status': 'up',
            'interface': interface,
            'exists': True,
            'wireguard_info': wg_result['stdout'],
            'ip_info': ip_result['stdout']
        })
        
    except Exception as e:
        logger.error(f"Failed to get status for {interface}: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/vpn/down/<interface>', methods=['POST'])
def bring_down(interface):
    """Bring down WireGuard interface"""
    if not verify_api_key():
        return jsonify({'error': 'Unauthorized'}), 401
    
    try:
        logger.info(f"Bringing down interface: {interface}")
        result = run_command(f"wg-quick down {interface}", check=False)
        
        return jsonify({
            'status': 'success',
            'interface': interface,
            'action': 'down',
            'output': result['stdout']
        })
        
    except Exception as e:
        logger.error(f"Failed to bring down {interface}: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/vpn/peer/add', methods=['POST'])
def add_peer():
    """Add peer to WireGuard interface"""
    if not verify_api_key():
        return jsonify({'error': 'Unauthorized'}), 401
    
    data = request.json
    interface = data.get('interface')
    public_key = data.get('public_key')
    allowed_ips = data.get('allowed_ips')
    persistent_keepalive = data.get('persistent_keepalive', 25)
    
    if not all([interface, public_key, allowed_ips]):
        return jsonify({'error': 'Missing required fields'}), 400
    
    try:
        cmd = f"wg set {interface} peer {public_key} allowed-ips {allowed_ips} persistent-keepalive {persistent_keepalive}"
        result = run_command(cmd)
        
        if not result['success']:
            raise Exception(f"Failed to add peer: {result['stderr']}")
        
        # Save config
        run_command(f"wg-quick save {interface}", check=False)
        
        logger.info(f"Peer added to {interface}: {public_key}")
        
        return jsonify({
            'status': 'success',
            'interface': interface,
            'peer': public_key,
            'action': 'added'
        })
        
    except Exception as e:
        logger.error(f"Failed to add peer: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/vpn/peer/remove', methods=['POST'])
def remove_peer():
    """Remove peer from WireGuard interface"""
    if not verify_api_key():
        return jsonify({'error': 'Unauthorized'}), 401
    
    data = request.json
    interface = data.get('interface')
    public_key = data.get('public_key')
    
    if not all([interface, public_key]):
        return jsonify({'error': 'Missing required fields'}), 400
    
    try:
        cmd = f"wg set {interface} peer {public_key} remove"
        result = run_command(cmd)
        
        if not result['success']:
            raise Exception(f"Failed to remove peer: {result['stderr']}")
        
        # Save config
        run_command(f"wg-quick save {interface}", check=False)
        
        logger.info(f"Peer removed from {interface}: {public_key}")
        
        return jsonify({
            'status': 'success',
            'interface': interface,
            'peer': public_key,
            'action': 'removed'
        })
        
    except Exception as e:
        logger.error(f"Failed to remove peer: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/vpn/list', methods=['GET'])
def list_interfaces():
    """List all WireGuard interfaces"""
    if not verify_api_key():
        return jsonify({'error': 'Unauthorized'}), 401
    
    try:
        result = run_command("wg show interfaces", check=False)
        
        if result['success']:
            interfaces = result['stdout'].strip().split()
        else:
            interfaces = []
        
        interface_details = []
        for iface in interfaces:
            status_result = run_command(f"wg show {iface}", check=False)
            interface_details.append({
                'name': iface,
                'info': status_result['stdout'] if status_result['success'] else None
            })
        
        return jsonify({
            'status': 'success',
            'count': len(interfaces),
            'interfaces': interface_details
        })
        
    except Exception as e:
        logger.error(f"Failed to list interfaces: {str(e)}")
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    logger.info("Starting WireGuard Controller Service")
    logger.info(f"API Key configured: {'Yes' if API_KEY != 'change-me-in-production' else 'No (using default)'}")
    
    # Run with Gunicorn in production
    app.run(host='0.0.0.0', port=8080, debug=False)
