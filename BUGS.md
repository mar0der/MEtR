# METR bugs

## Fixed on `fix/codex-zstd-homes` (not yet released)
- **Codex `.jsonl.zst` rollouts never scanned** → Mac Codex events dropped 31k (Aug) → 639 (Oct). Fixed: zstd decoding.
- **Extra Codex homes not scanned** (`~/.codex-acct2`, `~/.codex-accounts/*`, `$CODEX_HOME`). Fixed: discovery + auto-registration.
- **Local double count when Codex archives/compresses a rollout** (event id hashed the file path). Fixed: raw-line reconciliation + one-time local cleanup. Server was already safe.
- **Codex repeated token_count counted twice** (~0.2% overcount). Fixed forward; already-imported repeats stay in history (not worth a full reparse).

## Fixed on `fix/pricing-realism` (2026-10-30)
- **Daily duplicate price rows** (36.5k rows, ~440/day; float noise 3e-06*1e6 != 3.0 and "current" picked by effective_from only) → every nightly run repriced ~all 564k events (~2 h). Fixed: rounding + tolerant compare, newest open row is current, other open rows closed; `metr:prices:prune-duplicates` removes unused copies.
- **Overlapping price jobs** (manual + scheduled run at once) → shared lock for update/reprice; `onOneServer()`.
- **Claude 1-hour cache writes uploaded before 2026-10-30 03:00 priced at the 5-minute rate** (server column is new) → devices with logs: Full Rescan + Full Resync; devices without: `metr:prices:estimate-1h-cache` (90% 1-hour, flagged, undoable).

## Fixed on `fix/desktop-auto-sync` (26.44.1, 2026-10-30)
- **Desktop app never uploaded on its own** (all versions so far): the 5-minute rescan and Rescan/Full Rescan only import into the local DB; upload happened only on a Sync click. Seen on the Mac: app running on 26.44.0, last sync attempt Oct 29 11:31 UTC. Fixed: auto-sync after scans (pending calls, or hourly heartbeat).
- **"Imported 0" was ambiguous** (nothing new vs broken scanner). Fixed: scan result shows the newest call per provider and what was uploaded.

## Open
- **Desktop: newest-call line can show the scan time** for records without a timestamp (they fall back to now()). Display only.
- **Claude Code inside WSL is not scanned** by the Windows app (only `%USERPROFILE%` and `%LOCALAPPDATA%\ClaudeCode`). Unknown whether NX64 uses WSL.
- **Codex `exec --json` handoff outputs** (`~/.codex/handoffs/*`, `turn.completed` usage) are not counted; Mac has 33 such files from 2026-09-01. Probably the same calls as rollouts; check before counting.
- **Backend test suite has 9 pre-existing failures on SQLite** (found 2026-10-29 while adding the reconcile endpoint; not caused by it): DashboardTest ×3 (`GREATEST()` is MySQL-only, tests run on in-memory SQLite), RenewSubscriptionsTest ×3, SubscriptionsPageTest ×2, SyncTest::test_subscription_sync_is_idempotent (likely date-dependent). Production runs MySQL. Run with: `docker run --rm -v $PWD:/app -w /app -e CACHE_STORE=array php:8.4-cli php vendor/bin/phpunit` in sync-server/backend after `composer install`.
