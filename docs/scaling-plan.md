# MEtR Scaling Plan — 100 Active Users

> **Goal:** Evolve the sync-server backend from a personal single-user setup to a platform capable of serving 100 concurrent active users with sub-500ms dashboard load times.

---

## Current State Snapshot

| Metric | Value | Status |
|---|---|---|
| Total Events | 83,760 | [[Database Tuning]] |
| DB Size | 65 MB | [[Database Tuning]] |
| PHP-FPM Max Children | 5 | [[Application Layer]] |
| nginx Worker Connections | 1024 | [[Application Layer]] |
| Query Speed (GROUP BY) | 0.2s | [[Database Tuning]] |
| Caching | None | [[Caching Layer]] |
| Rate Limiting | None | [[Security Hardening]] |
| Active Users | 1 | [[Monitoring & Observability]] |

---

## The Bottlenecks

1. **PHP-FPM `max_children = 5`** — Hard caps concurrent requests at 5. One user loading the dashboard fires 3–4 parallel requests. Real capacity ≈ 2 concurrent users.
2. **No Caching** — Every dashboard hit re-runs aggregate SQL. At 100 users × 5 req/s = 500 queries/sec hitting MySQL.
3. **Shared Server** — 40+ Docker containers compete for CPU/RAM on the same bare metal.
4. **No Rate Limiting** — Sync API could be DDoS'd by a single misbehaving client.
5. **Missing MySQL Indexes** — Dashboard filters use `(user_id, timestamp)` and `(user_id, provider_id, timestamp)` but only have partial index coverage.

---

## Implementation Phases

### Phase 1: Resource Ceiling — Remove Hard Limits

| # | Task | Document | Est. Time |
|---|---|---|---|
| 1.1 | Increase PHP-FPM `max_children` from 5 → 30 | [[Application Layer]] | 10 min |
| 1.2 | Optimize nginx worker_processes + keepalive + buffers | [[Application Layer]] | 15 min |
| 1.3 | Enable MySQL slow query log + inspect existing queries | [[Database Tuning]] | 10 min |
| 1.4 | Add missing MySQL composite indexes for dashboard filters | [[Database Tuning]] | 15 min |

### Phase 2: Caching Layer — Stop Recomputing Everything

| # | Task | Document | Est. Time |
|---|---|---|---|
| 2.1 | Add Laravel Redis cache config + cache dashboard summary for 60s | [[Caching Layer]] | 30 min |
| 2.2 | Cache report aggregates for 5 minutes | [[Caching Layer]] | 20 min |
| 2.3 | Cache pricing catalog in Redis (rarely changes) | [[Caching Layer]] | 15 min |
| 2.4 | Add Cache-Control headers for static assets | [[Caching Layer]] | 10 min |

### Phase 3: Query Optimization — Make SQL Fast at Scale

| # | Task | Document | Est. Time |
|---|---|---|---|
| 3.1 | Optimize `WebController::dashboard()` summary query | [[Database Tuning]] | 30 min |
| 3.2 | Optimize `WebController::reports()` daily aggregation | [[Database Tuning]] | 30 min |
| 3.3 | Add database query result caching for heavy group-bys | [[Database Tuning]] | 20 min |
| 3.4 | Add pagination + limits to all unbounded queries | [[Database Tuning]] | 20 min |

### Phase 4: Security Hardening — Protect the Platform

| # | Task | Document | Est. Time |
|---|---|---|---|
| 4.1 | Add Laravel rate limiting to sync API (`/api/v1/sync/*`) | [[Security Hardening]] | 20 min |
| 4.2 | Add rate limiting to dashboard API (`/api/v1/dashboard/*`) | [[Security Hardening]] | 15 min |
| 4.3 | Add rate limiting to web routes (`/dashboard`, `/reports`) | [[Security Hardening]] | 15 min |
| 4.4 | Add request size limits + timeout configs | [[Security Hardening]] | 10 min |

### Phase 5: Monitoring — Know When Things Break

| # | Task | Document | Est. Time |
|---|---|---|---|
| 5.1 | Add Laravel Telescope or basic query log monitoring | [[Monitoring & Observability]] | 30 min |
| 5.2 | Add health check endpoint for Docker/docker-compose | [[Monitoring & Observability]] | 15 min |
| 5.3 | Document MySQL maintenance commands (ANALYZE, OPTIMIZE) | [[Monitoring & Observability]] | 10 min |

---

## Target Architecture (100 Users)

```
┌─────────────────┐
│   nginx         │  ← worker_processes auto, keepalive, gzip, static cache
│   (reverse proxy)│
└────────┬────────┘
         │
┌────────▼────────┐
│   PHP-FPM       │  ← max_children 30, pm dynamic, opcache
│   (Laravel)     │
└────────┬────────┘
         │
┌────────▼────────┐     ┌─────────────────┐
│   MySQL 8.0     │◄────┤   Redis         │
│   (indexed)     │     │   (sessions +   │
│                 │     │    query cache) │
└─────────────────┘     └─────────────────┘
```

---

## Related Documents

- [[scaling-progress]] — Live tracker with completion %
- [[product_specs]] — Product requirements
- [[sync_backend_laravel_implementation_and_deployment]] — Deployment procedures
