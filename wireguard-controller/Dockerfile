FROM python:3.11-alpine

# Install WireGuard and network tools
RUN apk add --no-cache \
    wireguard-tools \
    iptables \
    iproute2 \
    bash \
    curl

WORKDIR /app

# Copy requirements first for better caching
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY controller.py .
COPY config/ /app/config/

# Create WireGuard config directory
RUN mkdir -p /etc/wireguard \
    && chmod 755 /etc/wireguard

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose API port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["python", "-u", "controller.py"]
