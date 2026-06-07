# Caching Layer — Redis

> Part of [[scaling-plan|MEtR Scaling Plan]].
> Controls: [[scaling-progress|Progress Tracker]]

---

## Current State

- Redis is running (`metr-sync-redis` on port 6381)
- Used **only** for Laravel sessions (`SESSION_DRIVER=redis`)
- No application caching configured
- No HTTP cache headers for static assets

---

## Target: Multi-Level Cache

### 1. Laravel Query Result Cache
Cache expensive dashboard aggregates for short TTLs.

```php
// Dashboard summary — 60 seconds
$summary = Cache::store('redis')->remember(
    "dashboard:summary:{$userId}:" . md5(serialize($filters)),
    60,
    fn() => $this->calculateSummary($userId, $filters)
);

// Reports daily aggregates — 5 minutes
$dailyRows = Cache::store('redis')->remember(
    "reports:daily:{$userId}:{$metric}:" . md5(serialize($dateRange)),
    300,
    fn() => $this->calculateDailyRows(...)
);
```

### 2. Pricing Catalog Cache
Model prices change rarely (only when litellm updates). Cache for 1 hour.

```php
$prices = Cache::store('redis')->remember(
    "pricing:catalog:{$providerId}:{$model}",
    3600,
    fn() => ModelPrice::where(...)->first()
);
```

### 3. HTTP Static Asset Caching
Add `Cache-Control` headers for CSS, JS, images.

```nginx
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

---

## Redis Connection

Already configured in `.env`:
```env
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0
```

Cache store config in `config/cache.php`:
```php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],
```

---

## Cache Invalidation Strategy

| Cache Key Pattern | TTL | Invalidation Trigger |
|---|---|---|
| `dashboard:summary:{userId}:{hash}` | 60s | Any sync upload |
| `reports:daily:{userId}:{metric}:{hash}` | 300s | New sync data |
| `pricing:catalog:{providerId}:{model}` | 3600s | Price update |
| `sync:status:{userId}` | 30s | Natural expiry |

---

## Related

- [[application-layer]] — Reduce PHP-FPM load
- [[database-tuning]] — Cache replaces heavy queries
- [[scaling-progress]] — Track completion
