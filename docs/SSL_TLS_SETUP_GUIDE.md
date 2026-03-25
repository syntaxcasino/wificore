# SSL/TLS Configuration Guide

**Priority:** P3 (Low Priority)  
**Status:** Configuration Ready  
**Date:** January 1, 2026

---

## Overview

This guide provides instructions for configuring SSL/TLS certificates for the WiFiCore application to enable HTTPS and enhance security.

---

## Prerequisites

- Domain name configured and pointing to your server
- Root/sudo access to the server
- Certbot installed (for Let's Encrypt certificates)

---

## Option 1: Let's Encrypt (Recommended for Production)

### Step 1: Install Certbot

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install certbot python3-certbot-nginx

# CentOS/RHEL
sudo yum install certbot python3-certbot-nginx
```

### Step 2: Obtain Certificate

```bash
# Stop nginx temporarily
docker compose -f docker-compose.production.yml stop wificore-nginx

# Obtain certificate
sudo certbot certonly --standalone -d yourdomain.com -d *.yourdomain.com

# Start nginx
docker compose -f docker-compose.production.yml start wificore-nginx
```

### Step 3: Configure Nginx for SSL

Create `nginx/conf.d/ssl.conf`:

```nginx
# SSL Configuration
ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

# SSL protocols and ciphers
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384';
ssl_prefer_server_ciphers off;

# SSL session cache
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 10m;
ssl_session_tickets off;

# OCSP stapling
ssl_stapling on;
ssl_stapling_verify on;
ssl_trusted_certificate /etc/letsencrypt/live/yourdomain.com/chain.pem;

# DNS resolver for OCSP
resolver 8.8.8.8 8.8.4.4 valid=300s;
resolver_timeout 5s;
```

### Step 4: Update docker-compose.production.yml

```yaml
services:
  wificore-nginx:
    volumes:
      - ./nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./nginx/conf.d:/etc/nginx/conf.d:ro
      - /etc/letsencrypt:/etc/letsencrypt:ro  # Add SSL certificates
    ports:
      - "80:80"
      - "443:443"  # Add HTTPS port
```

### Step 5: Update nginx.conf

```nginx
server {
    listen 80;
    server_name yourdomain.com *.yourdomain.com;
    
    # Redirect HTTP to HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com *.yourdomain.com;
    
    # Include SSL configuration
    include /etc/nginx/conf.d/ssl.conf;
    
    # Enable HSTS (uncomment after testing)
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    
    # ... rest of your configuration
}
```

### Step 6: Set Up Auto-Renewal

```bash
# Test renewal
sudo certbot renew --dry-run

# Add cron job for auto-renewal
sudo crontab -e

# Add this line (runs twice daily)
0 0,12 * * * certbot renew --quiet --deploy-hook "docker compose -f /opt/wificore/docker-compose.production.yml restart wificore-nginx"
```

---

## Option 2: Self-Signed Certificate (Development Only)

### Generate Self-Signed Certificate

```bash
# Create directory for certificates
mkdir -p nginx/ssl

# Generate certificate (valid for 365 days)
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout nginx/ssl/selfsigned.key \
  -out nginx/ssl/selfsigned.crt \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=yourdomain.com"

# Generate dhparam
openssl dhparam -out nginx/ssl/dhparam.pem 2048
```

### Configure Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name localhost;
    
    ssl_certificate /etc/nginx/ssl/selfsigned.crt;
    ssl_certificate_key /etc/nginx/ssl/selfsigned.key;
    ssl_dhparam /etc/nginx/ssl/dhparam.pem;
    
    # ... rest of configuration
}
```

---

## Option 3: Commercial Certificate

### Step 1: Generate CSR

```bash
openssl req -new -newkey rsa:2048 -nodes \
  -keyout yourdomain.com.key \
  -out yourdomain.com.csr
```

### Step 2: Submit CSR to Certificate Authority

Submit the generated CSR to your chosen CA (DigiCert, GlobalSign, etc.)

### Step 3: Install Certificate

```bash
# Copy certificates to nginx/ssl/
cp yourdomain.com.crt nginx/ssl/
cp yourdomain.com.key nginx/ssl/
cp ca-bundle.crt nginx/ssl/

# Update nginx configuration with certificate paths
```

---

## Security Best Practices

### 1. Strong SSL Configuration

```nginx
# Only use TLS 1.2 and 1.3
ssl_protocols TLSv1.2 TLSv1.3;

# Strong cipher suites
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384';

# Prefer server ciphers
ssl_prefer_server_ciphers off;
```

### 2. Enable HSTS

```nginx
# Only enable after confirming HTTPS works
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```

### 3. OCSP Stapling

```nginx
ssl_stapling on;
ssl_stapling_verify on;
ssl_trusted_certificate /path/to/chain.pem;
```

### 4. Certificate Monitoring

Set up monitoring to alert before certificate expiration:

```bash
# Check certificate expiration
echo | openssl s_client -servername yourdomain.com -connect yourdomain.com:443 2>/dev/null | openssl x509 -noout -dates
```

---

## Testing SSL Configuration

### 1. Test with OpenSSL

```bash
openssl s_client -connect yourdomain.com:443 -tls1_2
openssl s_client -connect yourdomain.com:443 -tls1_3
```

### 2. Test with SSL Labs

Visit: https://www.ssllabs.com/ssltest/analyze.html?d=yourdomain.com

Target Grade: A or A+

### 3. Test HSTS

```bash
curl -I https://yourdomain.com | grep -i strict
```

### 4. Test Certificate Chain

```bash
openssl s_client -connect yourdomain.com:443 -showcerts
```

---

## Troubleshooting

### Certificate Not Found

```bash
# Check certificate files exist
ls -la /etc/letsencrypt/live/yourdomain.com/

# Check nginx can read certificates
docker compose exec wificore-nginx ls -la /etc/letsencrypt/live/yourdomain.com/
```

### Mixed Content Warnings

Update all internal URLs to use HTTPS:
- Frontend API calls
- WebSocket connections
- Asset URLs

### Certificate Renewal Failed

```bash
# Check certbot logs
sudo tail -f /var/log/letsencrypt/letsencrypt.log

# Manually renew
sudo certbot renew --force-renewal
```

### HSTS Issues

If you need to disable HSTS:
1. Remove HSTS header from nginx
2. Wait for max-age to expire
3. Clear browser HSTS cache

---

## Environment Configuration

### Update .env.production

```env
# Enable HTTPS
APP_URL=https://yourdomain.com
FRONTEND_URL=https://yourdomain.com
SANCTUM_STATEFUL_DOMAINS=yourdomain.com,*.yourdomain.com
SESSION_DOMAIN=.yourdomain.com
SESSION_SECURE_COOKIE=true

# WebSocket over SSL
PUSHER_SCHEME=https
PUSHER_PORT=443

# M-Pesa callback (must be HTTPS)
MPESA_CALLBACK_URL=https://yourdomain.com/api/mpesa/callback
```

---

## Certificate Rotation Checklist

- [ ] Backup old certificates
- [ ] Obtain new certificate
- [ ] Update nginx configuration
- [ ] Test configuration: `nginx -t`
- [ ] Reload nginx: `docker compose restart wificore-nginx`
- [ ] Verify certificate: `openssl s_client -connect domain:443`
- [ ] Test application functionality
- [ ] Monitor logs for SSL errors
- [ ] Update monitoring/alerting

---

## Wildcard Certificate Setup

For multi-tenant subdomains:

```bash
# Obtain wildcard certificate
sudo certbot certonly --manual --preferred-challenges dns \
  -d yourdomain.com -d *.yourdomain.com

# Add DNS TXT record as instructed by certbot
# Wait for DNS propagation
# Complete verification
```

---

## Performance Optimization

### 1. Enable HTTP/2

```nginx
listen 443 ssl http2;
```

### 2. SSL Session Caching

```nginx
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 10m;
```

### 3. OCSP Stapling

```nginx
ssl_stapling on;
ssl_stapling_verify on;
```

---

## Compliance

### PCI DSS Requirements

- ✅ TLS 1.2 or higher
- ✅ Strong cipher suites
- ✅ Certificate from trusted CA
- ✅ Regular certificate rotation

### GDPR Requirements

- ✅ Data in transit encryption
- ✅ Secure cookie transmission
- ✅ HSTS enabled

---

## Monitoring & Alerts

### Certificate Expiration Monitoring

```bash
# Add to cron (daily check)
0 8 * * * /opt/wificore/scripts/check-ssl-expiry.sh
```

### Create check-ssl-expiry.sh

```bash
#!/bin/bash
DOMAIN="yourdomain.com"
DAYS_BEFORE_EXPIRY=30

EXPIRY_DATE=$(echo | openssl s_client -servername $DOMAIN -connect $DOMAIN:443 2>/dev/null | openssl x509 -noout -enddate | cut -d= -f2)
EXPIRY_EPOCH=$(date -d "$EXPIRY_DATE" +%s)
CURRENT_EPOCH=$(date +%s)
DAYS_LEFT=$(( ($EXPIRY_EPOCH - $CURRENT_EPOCH) / 86400 ))

if [ $DAYS_LEFT -lt $DAYS_BEFORE_EXPIRY ]; then
    echo "WARNING: SSL certificate expires in $DAYS_LEFT days!"
    # Send alert (email, Slack, etc.)
fi
```

---

## Rollback Procedure

If SSL causes issues:

```bash
# 1. Revert nginx configuration
git checkout HEAD~1 nginx/nginx.conf

# 2. Restart nginx
docker compose -f docker-compose.production.yml restart wificore-nginx

# 3. Update DNS if needed (remove HTTPS)

# 4. Investigate logs
docker compose logs wificore-nginx
```

---

## Additional Resources

- [Mozilla SSL Configuration Generator](https://ssl-config.mozilla.org/)
- [Let's Encrypt Documentation](https://letsencrypt.org/docs/)
- [SSL Labs Best Practices](https://github.com/ssllabs/research/wiki/SSL-and-TLS-Deployment-Best-Practices)
- [OWASP TLS Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Protection_Cheat_Sheet.html)

---

**Status:** Ready for Implementation  
**Estimated Time:** 2-4 hours  
**Risk Level:** Low (with proper testing)
