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
        return {
            'success': True,
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
                run_command(f"ip link add dev {interface} type wireguard", check=False)
                run_command(f"wg setconf {interface} {config_path}", check=False)
                
                # Extract IP address from config and set it
                with open(config_path, 'r') as f:
                    for line in f:
                        if line.strip().startswith('Address'):
                            address = line.split('=')[1].strip()
                            run_command(f"ip addr add {address} dev {interface}", check=False)
                            break
                
                # Bring interface up
                result = run_command(f"ip link set {interface} up", check=False)
                
                if not result['success']:
                    raise Exception(f"Failed to bring up interface: {result['stderr']}")
            
            action = 'created'
        else:
            # Interface exists, reload configuration
            logger.info(f"Reloading existing interface: {interface}")
            result = run_command(f"wg syncconf {interface} {config_path}")
            
            if not result['success']:
                # If syncconf fails, try down/up
                logger.warning(f"syncconf failed, trying down/up for {interface}")
                run_command(f"wg-quick down {interface}", check=False)
                
                # Try wg-quick up
                result = run_command(f"wg-quick up {interface}", check=False)
                
                if not result['success']:
                    # Manual recreation
                    run_command(f"ip link del {interface}", check=False)
                    run_command(f"ip link add dev {interface} type wireguard", check=False)
                    run_command(f"wg setconf {interface} {config_path}", check=False)
                    
                    with open(config_path, 'r') as f:
                        for line in f:
                            if line.strip().startswith('Address'):
                                address = line.split('=')[1].strip()
                                run_command(f"ip addr add {address} dev {interface}", check=False)
                                break
                    
                    result = run_command(f"ip link set {interface} up", check=False)
                    
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
