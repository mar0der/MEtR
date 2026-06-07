# Database Tuning — MySQL 8.0

> Part of [[scaling-plan|MEtR Scaling Plan]].
> Controls: [[scaling-progress|Progress Tracker]]

---

## Current State

| Table | Rows | Size | Notes |
|---|---|---|---|
| `usage_events` | 83,760 | 47 MB | Main table, all aggregates |
| `conversations` | ~12K | 12 MB | Grows with events |
| `model_prices` | ~2K | 2.5 MB | Small, lookup table |
| `sync_batches` | 111 | 0.09 MB | Tiny |

**Query performance:** Full-table `GROUP BY` across all events completes in **0.2s**.

**Slow query log:** Disabled.

---

## Existing Indexes

From `usage_events` migration:
```sql
INDEX(user_id, timestamp)
INDEX(user_id, provider_id, timestamp)
INDEX(user_id, project_id, timestamp)
INDEX(user_id, provider_account_id, timestamp)
INDEX(model)
```

Plus unique: `UNIQUE(device_id, source_event_id)`

---

## Missing Indexes

Dashboard filters that are NOT fully covered:

1. **Date-range only queries** (Reports page) — `(timestamp)` is covered by `(user_id, timestamp)` but queries without `user_id` in WHERE (e.g., admin views) would table-scan.

2. **Model filter + date range** — `INDEX(model, timestamp)` would speed up "filter by model" + date range.

3. **Sync status queries** — The sync loop queries `WHERE synced_at IS NULL ORDER BY timestamp ASC LIMIT 500`. Currently this uses the `(user_id, timestamp)` index but may not be optimal for `synced_at IS NULL`.

---

## Recommended Indexes

```sql
-- For sync loop (already partially covered but explicit helps)
CREATE INDEX idx_usage_events_synced_null_timestamp
  ON usage_events(user_id, synced_at, timestamp);

-- For model-filtered reports
CREATE INDEX idx_usage_events_user_model_timestamp
  ON usage_events(user_id, model, timestamp);

-- For conversation lookups during sync ingestion
CREATE INDEX idx_conversations_device_provider_external
  ON conversations(user_id, provider_id, device_id, external_conversation_id);
```

---

## MySQL Config Tuning

### Slow Query Log
```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1.0;
SET GLOBAL log_queries_not_using_indexes = 'ON';
```

### InnoDB Buffer Pool
Current default is likely 128MB. For 100 users:
```ini
innodb_buffer_pool_size = 1G        # Cache entire DB + headroom
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2   # Slightly less durable, much faster writes
```

---

## Maintenance

```sql
-- Run weekly
ANALYZE TABLE usage_events;
OPTIMIZE TABLE usage_events;
```

---

## Related

- [[application-layer]] — PHP-FPM concurrency
- [[caching-layer]] — Cache expensive queries
- [[scaling-progress]] — Track completion
