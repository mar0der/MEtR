# Security Hardening — Rate Limiting & Limits

> Part of [[scaling-plan|MEtR Scaling Plan]].
> Controls: [[scaling-progress|Progress Tracker]]

---

## Threats at Scale

1. **Sync API abuse** — A single desktop client uploading 45k events in rapid batches can exhaust PHP-FPM workers.
2. **Dashboard scraping** — External users hitting `/dashboard` with heavy date ranges.
3. **Brute force login** — No protection on `/login` endpoint.

---

## Rate Limits (Target)

| Route | Limit | Window | Notes |
|---|---|---|---|
| `POST /api/v1/sync/events` | 10 req/min | 60s | One batch per 6s is plenty |
| `POST /api/v1/sync/*` | 30 req/min | 60s | Subscriptions, pricing, etc. |
| `GET /api/v1/dashboard/*` | 60 req/min | 60s | Summary, by-device, etc. |
| `GET /dashboard` | 30 req/min | 60s | Web dashboard HTML |
| `GET /reports` | 30 req/min | 60s | Reports page |
| `POST /login` | 5 req/min | 60s | Brute force protection |

---

## Laravel Implementation

In `RouteServiceProvider` or `bootstrap/app.php`:
```php
RateLimiter::for('sync', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('dashboard', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('web', function (Request $request) {
    return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
});
```

Route middleware:
```php
Route::middleware(['auth', 'throttle:sync'])->group(function () {
    Route::post('/api/v1/sync/events', [SyncController::class, 'events']);
});

Route::middleware(['auth', 'throttle:dashboard'])->group(function () {
    Route::get('/api/v1/dashboard/summary', [DashboardController::class, 'summary']);
});
```

---

## Request Size Limits

Current nginx config: `client_max_body_size 20m;` — fine for sync batches.

PHP-FPM should also limit:
```ini
php_admin_value[post_max_size] = 20M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[max_execution_time] = 60
```

---

## Related

- [[application-layer]] — PHP-FPM worker exhaustion prevention
- [[scaling-progress]] — Track completion
