# Redis HA On A Single VM

## Goal
Reduce Redis outages and slowdowns in production without changing the application-facing Redis hostname.

## Topology
- `wificore-redis-primary`: writable Redis node
- `wificore-redis-replica`: hot replica
- `wificore-redis-sentinel-{1,2,3}`: failover quorum on one host
- `wificore-redis`: HAProxy endpoint that keeps the existing app target stable at `wificore-redis:6379`

## What This Improves
- Process and container-level Redis failover on one VM
- Persistent AOF-backed Redis data on both primary and replica
- Stable client endpoint for Laravel web, queue, SSE, and scheduler traffic
- Faster Redis failure detection via shorter Laravel client timeouts

## What This Does Not Solve
- Host failure still takes the entire Redis stack down
- Disk failure on the VM can still take Redis down
- Network loss to the VM still takes Redis down

## Required Host Tuning
Redis warns and can behave poorly under memory pressure unless the VM is tuned.

### Enable memory overcommit
```bash
sudo sysctl vm.overcommit_memory=1
echo 'vm.overcommit_memory = 1' | sudo tee /etc/sysctl.d/99-wificore-redis.conf
sudo sysctl --system
```

### Disable Transparent Huge Pages
```bash
echo never | sudo tee /sys/kernel/mm/transparent_hugepage/enabled
echo never | sudo tee /sys/kernel/mm/transparent_hugepage/defrag
```

Optional persistent unit:
```ini
# /etc/systemd/system/disable-thp.service
[Unit]
Description=Disable Transparent Huge Pages
After=network.target

[Service]
Type=oneshot
ExecStart=/bin/sh -c 'echo never > /sys/kernel/mm/transparent_hugepage/enabled'
ExecStart=/bin/sh -c 'echo never > /sys/kernel/mm/transparent_hugepage/defrag'
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
```

Then:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now disable-thp.service
```

## Failover Model
1. `wificore-redis-primary` is the writable master.
2. `wificore-redis-replica` follows it.
3. Sentinel quorum promotes the replica if the primary fails.
4. HAProxy health checks route `wificore-redis:6379` only to the node that reports `role:master`.
5. Laravel keeps using `REDIS_HOST=wificore-redis`.

## Production Issue Fixed On 2026-05-27
The original proxy health check used unauthenticated `PING`:
```text
printf 'PING\r\n' | nc -w 2 localhost 6379
```
That fails with `NOAUTH Authentication required.` whenever `REDIS_PASSWORD` is set, which causes `wificore-redis` to go unhealthy or restart even when the backend Redis nodes are fine.

The health check now authenticates first when `REDIS_PASSWORD` is present.

## Deployment Order
1. Build and deploy the updated Redis image and Redis proxy image.
2. Start `wificore-redis-primary` and `wificore-redis-replica`.
3. Start the three Sentinel containers.
4. Start the `wificore-redis` proxy.
5. Restart Laravel web, SSE, scheduler, and queue containers so they reconnect cleanly.

## Correct Validation Commands
Run these from `/opt/wificore`.

### Compose status
```bash
docker compose -f docker-compose.production.yml ps
```

### Primary replication status
Use the password from `.env.production`, not the current host shell unless it is exported there.
```bash
REDIS_PASSWORD="$(grep '^REDIS_PASSWORD=' .env.production | cut -d= -f2-)"
docker compose -f docker-compose.production.yml exec -T wificore-redis-primary \
  redis-cli --no-auth-warning -a "$REDIS_PASSWORD" INFO replication
```
Expected: `role:master`

### Replica replication status
```bash
REDIS_PASSWORD="$(grep '^REDIS_PASSWORD=' .env.production | cut -d= -f2-)"
docker compose -f docker-compose.production.yml exec -T wificore-redis-replica \
  redis-cli --no-auth-warning -a "$REDIS_PASSWORD" INFO replication
```
Expected: `role:slave` or `role:replica`

### Sentinel view of the master
```bash
docker compose -f docker-compose.production.yml exec -T wificore-redis-sentinel-1 \
  redis-cli -p 26379 SENTINEL master wificore-redis
```
Expected:
- `flags` contains `master`
- `num-slaves` is at least `1`
- `num-other-sentinels` is `2`

### Proxy liveness
```bash
REDIS_PASSWORD="$(grep '^REDIS_PASSWORD=' .env.production | cut -d= -f2-)"
docker compose -f docker-compose.production.yml exec -T wificore-redis sh -lc '
if [ -n "$REDIS_PASSWORD" ]; then
  printf "AUTH %s\r\nPING\r\n" "$REDIS_PASSWORD" | nc -w 2 localhost 6379
else
  printf "PING\r\n" | nc -w 2 localhost 6379
fi'
```
Expected output includes `+PONG`.

## Reading The Errors You Saw
- `Command 'ocker' not found` means the first command was typed without the leading `d`.
- `NOAUTH Authentication required` means Redis is password protected and the probe skipped `AUTH`.
- `AUTH failed: WRONGPASS invalid username-password pair or user is disabled` means the shell variable used in that command did not match the password loaded by Compose from `.env.production`.
- `service "wificore-redis" is not running` means the proxy container was unhealthy or restarting; the unauthenticated proxy health check was the application-level cause fixed here.

## Safe Failover Test
```bash
docker compose -f docker-compose.production.yml stop wificore-redis-primary
watch -n 2 'docker compose -f docker-compose.production.yml exec -T wificore-redis-sentinel-1 redis-cli -p 26379 SENTINEL master wificore-redis'
```
Wait until Sentinel reports the new master on the replica IP, then verify:
```bash
REDIS_PASSWORD="$(grep '^REDIS_PASSWORD=' .env.production | cut -d= -f2-)"
docker compose -f docker-compose.production.yml exec -T wificore-redis \
  sh -lc 'printf "AUTH %s\r\nPING\r\n" "$REDIS_PASSWORD" | nc -w 2 localhost 6379'
```
Bring the old primary back afterward:
```bash
docker compose -f docker-compose.production.yml start wificore-redis-primary
```

## Recommended Follow-up
- Keep scheduler mutexes on database cache as already patched.
- Keep noncritical Laravel cache paths fail-open.
- If load grows, split queues, cache, and pubsub onto separate Redis instances or roles.
- If budget allows later, move Redis primary and replica to separate VMs for real host-level HA.
