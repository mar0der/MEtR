# Monitoring & Observability

> Part of [[scaling-plan|MEtR Scaling Plan]].
> Controls: [[scaling-progress|Progress Tracker]]

---

## Current State

- No application monitoring
- No health checks
- MySQL slow query log: OFF
- No query performance tracking
- Docker containers run without restart policy verification

---

## Targets

### 1. Health Check Endpoint
```php
// GET /health
public function health(): JsonResponse
{
    $checks = [
        'database' => $this->checkDatabase(),
        'redis' => $this->checkRedis(),
        'disk' => $this->checkDisk(),
    ];
    $healthy = !in_array(false, $checks, true);
    return response()->json(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks], $healthy ? 200 : 503);
}
```

### 2. Query Performance Monitoring
Enable slow query log and review weekly:
```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1.0;
```

### 3. Docker Health Checks
Add to `docker-compose.yml`:
```yaml
services:
  php:
    healthcheck:
      test: ["CMD", "php", "artisan", "about"]
      interval: 30s
      timeout: 10s
      retries: 3
  db:
    healthcheck:
      test: ["CMD", "mysqladmin", "ping"]
      interval: 10s
      timeout: 5s
      retries: 3
```

---

## Maintenance Commands

```bash
# Weekly
docker exec metr-sync-db mysql -u root -p -e "ANALYZE TABLE metr_sync.usage_events;"
docker exec metr-sync-db mysql -u root -p -e "OPTIMIZE TABLE metr_sync.usage_events;"

# Check table size growth
docker exec metr-sync-db mysql -u root -p -e "SELECT table_name, ROUND(data_length/1024/1024,2) as mb FROM information_schema.tables WHERE table_schema = 'metr_sync' ORDER BY mb DESC;"
```

---

## Related

- [[scaling-progress]] — Track completion
- [[application-layer]] — PHP-FPM tuning
- [[database-tuning]] — Index maintenance
