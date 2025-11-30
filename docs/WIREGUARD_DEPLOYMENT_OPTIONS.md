# WireGuard Deployment Options

## Recommendation: Host-Based (Preferred)

Run WireGuard directly on the host machine for best performance and reliability.

## Comparison

| Feature | Host-Based | Docker-Based |
|---------|-----------|--------------|
| Performance | Excellent | Good (overhead) |
| Reliability | Very stable | Container dependent |
| Setup | Simple | More complex |
| Network | Direct access | Needs host network |
| Recommendation | RECOMMENDED | Only if necessary |

## Option 1: Host-Based Setup (RECOMMENDED)

### Architecture

```
HOST MACHINE
├─ WireGuard (wg0) - 10.10.10.1/24 - Port 51820
└─ Docker Containers
   ├─ FreeRADIUS (listens on 10.10.10.1:1812)
   ├─ PostgreSQL
   ├─ Laravel Backend
   └─ Nginx
```

### Installation Steps

#### 1. Install WireGuard on Host

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install wireguard wireguard-tools

# Verify
wg --version
```

#### 2. Enable IP Forwarding

```bash
sudo sysctl -w net.ipv4.ip_forward=1
echo "net.ipv4.ip_forward=1" | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
```

#### 3. Generate Server Keys

```bash
sudo mkdir -p /etc/wireguard
cd /etc/wireguard
wg genkey | sudo tee server_private.key | wg pubkey | sudo tee server_public.key
sudo chmod 600 server_private.key
```

#### 4. Create WireGuard Config

File: /etc/wireguard/wg0.conf

```ini
[Interface]
PrivateKey = YOUR_SERVER_PRIVATE_KEY
Address = 10.10.10.1/24
ListenPort = 51820

PostUp = iptables -A FORWARD -i wg0 -j ACCEPT
PostUp = iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT
PostDown = iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE
```

#### 5. Start WireGuard

```bash
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0
sudo wg show wg0
```

#### 6. Configure Firewall

```bash
sudo ufw allow 51820/udp
sudo ufw allow from 10.10.10.0/24 to any port 1812 proto udp
sudo ufw allow from 10.10.10.0/24 to any port 1813 proto udp
sudo ufw reload
```

#### 7. Update Docker Compose

Use host network mode for FreeRADIUS:

```yaml
services:
  freeradius:
    network_mode: "host"
```

### Benefits

- Native kernel module (best performance)
- Direct network access
- Stable and reliable
- Easy to manage
- No container overhead

## Option 2: Docker-Based Setup (Alternative)

Only use if you cannot install on host.

### Docker Compose Addition

```yaml
services:
  wireguard:
    image: linuxserver/wireguard
    container_name: traidnet-wireguard
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Africa/Nairobi
      - SERVERPORT=51820
      - PEERS=router1,router2,router3
      - PEERDNS=auto
      - INTERNAL_SUBNET=10.10.10.0/24
    volumes:
      - ./wireguard:/config
      - /lib/modules:/lib/modules
    ports:
      - 51820:51820/udp
    sysctls:
      - net.ipv4.conf.all.src_valid_mark=1
    restart: unless-stopped
    network_mode: host
```

### Drawbacks

- Container dependency
- More complex networking
- Potential performance overhead
- Harder to troubleshoot

## Recommended: Host-Based

For production hotspot billing system, use HOST-BASED setup for reliability and performance.

## Quick Start Commands

```bash
# Install on host
sudo apt install wireguard wireguard-tools

# Generate keys
cd /etc/wireguard
wg genkey | sudo tee server_private.key | wg pubkey | sudo tee server_public.key

# Create config
sudo nano /etc/wireguard/wg0.conf

# Start service
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0

# Verify
sudo wg show
```

## Summary

- Host-Based: RECOMMENDED
- Docker-Based: Only if necessary
- Performance: Host is better
- Reliability: Host is more stable
- Setup: Host is simpler

Use host-based WireGuard for production!
