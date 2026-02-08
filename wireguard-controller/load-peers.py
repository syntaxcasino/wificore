#!/usr/bin/env python3
"""
Load WireGuard peers from config file into running interface
"""
import sys
import subprocess
import re
import tempfile
import os

def load_peers_from_config(config_file, interface):
    """Parse config file and load peers into WireGuard interface"""
    
    if not os.path.exists(config_file):
        print(f"Config file not found: {config_file}")
        return 0
    
    with open(config_file, 'r') as f:
        content = f.read()
    
    # Split into sections
    sections = re.split(r'\n\[Peer\]', content)
    
    # Skip the [Interface] section
    peer_sections = sections[1:] if len(sections) > 1 else []
    
    peers_loaded = 0
    
    for peer_section in peer_sections:
        # Parse peer attributes
        public_key = None
        preshared_key = None
        allowed_ips = None
        persistent_keepalive = None
        endpoint = None
        
        for line in peer_section.strip().split('\n'):
            line = line.strip()
            if line.startswith('PublicKey'):
                public_key = line.split('=', 1)[1].strip()
            elif line.startswith('PresharedKey'):
                preshared_key = line.split('=', 1)[1].strip()
            elif line.startswith('AllowedIPs'):
                allowed_ips = line.split('=', 1)[1].strip()
            elif line.startswith('PersistentKeepalive'):
                persistent_keepalive = line.split('=', 1)[1].strip()
            elif line.startswith('Endpoint'):
                endpoint = line.split('=', 1)[1].strip()
        
        # Skip if no public key
        if not public_key:
            continue
        
        # Build wg set command
        cmd = ['wg', 'set', interface, 'peer', public_key]
        
        # Handle preshared key via temp file
        psk_file = None
        if preshared_key:
            psk_fd, psk_file = tempfile.mkstemp(suffix='.key')
            try:
                os.write(psk_fd, preshared_key.encode())
                os.close(psk_fd)
                os.chmod(psk_file, 0o600)
                cmd.extend(['preshared-key', psk_file])
            except Exception as e:
                print(f"Error creating preshared key file: {e}")
                if psk_file and os.path.exists(psk_file):
                    os.unlink(psk_file)
                continue
        
        if allowed_ips:
            cmd.extend(['allowed-ips', allowed_ips])
        
        if persistent_keepalive:
            cmd.extend(['persistent-keepalive', persistent_keepalive])
        
        if endpoint:
            cmd.extend(['endpoint', endpoint])
        
        # Execute command
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, check=True)
            peers_loaded += 1
            print(f"✓ Loaded peer: {public_key[:16]}...")
        except subprocess.CalledProcessError as e:
            print(f"✗ Failed to load peer {public_key[:16]}...: {e.stderr}")
        finally:
            # Clean up preshared key file
            if psk_file and os.path.exists(psk_file):
                try:
                    os.unlink(psk_file)
                except:
                    pass
    
    return peers_loaded

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print(f"Usage: {sys.argv[0]} <config_file> <interface>")
        sys.exit(1)
    
    config_file = sys.argv[1]
    interface = sys.argv[2]
    
    count = load_peers_from_config(config_file, interface)
    print(f"Loaded {count} peer(s) from {config_file}")
    sys.exit(0)
