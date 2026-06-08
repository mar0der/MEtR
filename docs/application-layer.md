# Application Layer — PHP-FPM & nginx

> Part of [[scaling-plan|MEtR Scaling Plan]].
> Controls: [[scaling-progress|Progress Tracker]]

---

## Current Configuration

### PHP-FPM (`www.conf`)
```ini
pm.max_children = 5          # ← BOTTLENECK
pm = dynamic                  # (implied default)
```

With `max_children = 5`, only 5 concurrent PHP requests can be processed. A single dashboard page load fires 3–4 parallel requests (HTML + AJAX). Effective capacity: **~2 concurrent users**.

### nginx (`default.conf`)
```nginx
worker_processes auto;       # → 8 on this CPU
worker_connections 1024;     # → 8192 total connections (8 × 1024)
```

nginx itself is fine. The bottleneck is PHP-FPM upstream capacity.

---

## Target Configuration (100 Users)

### PHP-FPM
```ini
pm = dynamic
pm.max_children = 30
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 500
```

**Rationale:**
- `max_children = 30` — Each Laravel worker uses ~50–80MB RAM. 30 × 80MB = 2.4GB. The server has 13GB available.
- `pm.max_requests = 500` — Restart workers periodically to prevent memory leaks.
- `dynamic` — Scale up/down based on load.

### nginx
```nginx
worker_processes auto;
worker_connections 4096;
keepalive_timeout 65;
keepalive_requests 1000;

# Upstream keepalive to PHP-FPM
upstream php {
    server php:9000;
    keepalive 32;
}
```

**Changes:**
- `worker_connections 4096` — More per-worker connections
- `keepalive 32` — Reuse PHP-FPM connections instead of reconnecting every request

---

## Deployment Notes

PHP-FPM config lives inside the Docker image. To apply changes, rebuild the PHP container after editing `sync-server/backend/docker/php/www.conf`.

For hot-patching without a rebuild, run equivalent `sed` and `kill -USR2` commands inside the running PHP container. This is environment-specific and should be done according to your own operational runbooks.

---

## Related

- [[database-tuning]] — SQL optimization
- [[caching-layer]] — Reduce PHP-FPM load via cache
- [[scaling-progress]] — Track completion
