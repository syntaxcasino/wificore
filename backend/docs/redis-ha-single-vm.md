# Redis HA On A Single VM

## Goal
Reduce Redis outages and slowdowns in production without changing the application-facing Redis hostname.

## Topology
- `wificore-redis-primary`: writable Redis node
- `wificore-redis-replica`: hot replica
- `wificore-redis-sentinel-{1,2,3}`: failover quorum on one host
- `wificore-redis`: HAProxy endpoint that keeps the existing app target stable at `wificore-redis:6379`

## What This Improves
- Process/container-level Redis failover on one VM
- Persistent AOF-backed Redis data on both primary and replica
- Stable client endpoint for Laravel workers, web, SSE, and scheduler
- Faster Redis failure detection via shorter client timeouts in Laravel

## What This Does Not Solve
- Host failure still takes the entire Redis stack down
- Disk failure on the VM can still take Redis down
- Network loss to the VM still takes Redis down

## Failover Model
1. Primary becomes unhealthy.
2. Sentinel quorum promotes the replica.
3. HAProxy health checks detect which node is `role:master`.
4. App traffic continues through `wificore-redis` without changing `REDIS_HOST`.

## Deployment Order
1. Build and deploy the updated Redis image and Redis proxy image.
2. Start `wificore-redis-primary` and `wificore-redis-replica`.
3. Start the three Sentinel containers.
4. Start `wificore-redis` proxy.
5. Restart Laravel web, SSE, scheduler, and queue containers so they reconnect cleanly.

## Validation
- `docker compose -f docker-compose.production.yml config`
- Verify `wificore-redis` responds on port `6379`
- Kill `wificore-redis-primary` and confirm Sentinel promotes the replica
- Confirm the app recovers without changing `REDIS_HOST`

## Recommended Follow-up
- Keep scheduler mutexes on database cache as already patched
- Use separate Redis DBs or separate future instances for cache vs queues if load grows
- If budget allows later, move Redis primary and replica to separate VMs for real host-level HA
