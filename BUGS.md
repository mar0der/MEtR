# METR bugs

## Fixed on `fix/codex-zstd-homes` (not yet released)
- **Codex `.jsonl.zst` rollouts never scanned** → Mac Codex events dropped 31k (Aug) → 639 (Oct). Fixed: zstd decoding.
- **Extra Codex homes not scanned** (`~/.codex-acct2`, `~/.codex-accounts/*`, `$CODEX_HOME`). Fixed: discovery + auto-registration.
- **Local double count when Codex archives/compresses a rollout** (event id hashed the file path). Fixed: raw-line reconciliation + one-time local cleanup. Server was already safe.
- **Codex repeated token_count counted twice** (~0.2% overcount). Fixed forward; already-imported repeats stay in history (not worth a full reparse).

## Open
- **Backend test suite has 9 pre-existing failures on SQLite** (found 2026-10-29 while adding the reconcile endpoint; not caused by it): DashboardTest ×3 (`GREATEST()` is MySQL-only, tests run on in-memory SQLite), RenewSubscriptionsTest ×3, SubscriptionsPageTest ×2, SyncTest::test_subscription_sync_is_idempotent (likely date-dependent). Production runs MySQL. Run with: `docker run --rm -v $PWD:/app -w /app -e CACHE_STORE=array php:8.4-cli php vendor/bin/phpunit` in sync-server/backend after `composer install`.
