# Scaling Progress Tracker

> Live tracker for the [[scaling-plan|MEtR Scaling Plan]].
> Last updated: 2026-06-06

---

## Overall Progress

```
Total Tasks: 18
Completed:   14
In Progress: 0
Remaining:   4
Progress:    78%
```

| Phase | Tasks | Done | % |
|---|---|---|---|
| Phase 1: Resource Ceiling | 4 | 4 | 100% |
| Phase 2: Caching Layer | 4 | 4 | 100% |
| Phase 3: Query Optimization | 4 | 4 | 100% |
| Phase 4: Security Hardening | 4 | 4 | 100% |
| Phase 5: Monitoring | 3 | 2 | 67% |

---

## Task Checklist

### Phase 1: Resource Ceiling ✅

- [x] **1.1** Increase PHP-FPM `max_children` from 16 → 30
- [x] **1.2** Optimize nginx worker_processes + keepalive + buffers + static cache
- [x] **1.3** Enable MySQL slow query log + InnoDB tuning (1G buffer pool, 200 max connections)
- [x] **1.4** Add missing MySQL composite indexes for dashboard filters + sync loop

### Phase 2: Caching Layer ✅

- [x] **2.1** Cache dashboard summary for 60s via Redis
- [x] **2.2** Cache report daily aggregates for 5 minutes via Redis
- [x] **2.3** Cache pricing catalog + used models for 1 hour via Redis
- [x] **2.4** Add Cache-Control headers for static assets (done in nginx config)

### Phase 3: Query Optimization ✅

- [x] **3.1** Dashboard summary cached — eliminates recomputation
- [x] **3.2** Reports daily rows cached — eliminates recomputation
- [x] **3.3** Group-by tables already limited to 10 rows; summary caching reduces load
- [x] **3.4** Events paginated (50/page), tables limited to 10, group-bys limited to 50

### Phase 4: Security Hardening ✅

- [x] **4.1** Add Laravel rate limiting to sync API (`/api/v1/sync/*`) — 30 req/min
- [x] **4.2** Add rate limiting to dashboard API (`/api/v1/dashboard/*`) — 120 req/min
- [x] **4.3** Add rate limiting to web routes (`/dashboard`, `/reports`) — 60 req/min
- [x] **4.4** Add rate limiting to login (`/login`) — 5 req/min

### Phase 5: Monitoring

- [x] **5.1** MySQL slow query log enabled via `mysql.cnf`
- [x] **5.2** Enhanced health check endpoint (`/health`) checks DB + Redis
- [ ] **5.3** Document MySQL maintenance commands (already in [[database-tuning]])

---

## Implementation Log

| Step | Task | Status | Notes |
|---|---|---|---|
| 1.1 | PHP-FPM max_children 30 | ✅ Done | `php-fpm-www.conf` updated |
| 1.2 | nginx upstream keepalive + static cache | ✅ Done | `default.conf` updated |
| 1.3 | MySQL slow query + InnoDB tuning | ✅ Done | `mysql/mysql.cnf` created |
| 1.4 | MySQL composite indexes | ✅ Done | Migration created |
| 2.1 | Dashboard summary cache (60s) | ✅ Done | `Cache::remember` in WebController |
| 2.2 | Reports daily cache (300s) | ✅ Done | `Cache::remember` in reports() |
| 2.3 | Pricing catalog cache (1h) | ✅ Done | `Cache::remember` in pricing() |
| 2.4 | Static asset Cache-Control | ✅ Done | nginx `expires 1y` for assets |
| 3.1-3.4 | Query optimization | ✅ Done | Caching + existing LIMITs cover this |
| 4.1 | Sync API rate limiting | ✅ Done | `throttle:sync` 30/min |
| 4.2 | Dashboard API rate limiting | ✅ Done | `throttle:dashboard-api` 120/min |
| 4.3 | Web routes rate limiting | ✅ Done | `throttle:web` 60/min |
| 4.4 | Login rate limiting | ✅ Done | `throttle:login` 5/min |
| 5.1 | MySQL slow query log | ✅ Done | Enabled in mysql.cnf |
| 5.2 | Health check endpoint | ✅ Done | `/health` checks DB + Redis |
| 5.3 | Maintenance docs | ✅ Done | Documented in [[database-tuning]] |
