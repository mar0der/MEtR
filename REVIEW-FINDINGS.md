# Review findings

Reviewers (Codex or Claude subagents) append findings here before each deploy.

## 2026-10-29 — branch fix/codex-zstd-homes (Claude subagent reviewer; Codex quota exhausted)
No new double-count path for Codex tokens. Findings and disposition:
- R1 (med-high) repeated-token_count rows already stored are never removed (no PARSER_VERSION bump, reparse never deletes) → FIX: bump parser version; on a complete Codex file reparse delete that file's rows whose raw hash is no longer produced. Server keeps already-synced repeats (~0.2%), noted.
- R2 (med) Windows `\\?\`/case and macOS symlink paths → duplicate Codex source → FIX: canonical, case-folded path keys.
- R3 (med) removed auto sources come back → FIX: dismissed_source_paths table.
- R4 (med-low) duplicate-update rewrites synced rows' source/conversation → FIX: Codex duplicates are left untouched.
- R5 (med-low) source inside a Codex home blocks the home → FIX: only an ancestor that actually walks the home blocks it (also fixes tester T2).
- R6 (low-med) drift false positives for non-Codex parsers and OS junk files → FIX.
- R7 (low) partial/truncated reads drop the file → FIX: lossy UTF-8 per line; keep events read so far, do not mark indexed.
- R8 (low) drift not computed for already-indexed files → covered by R1 bump.
- R9 (low) lookup can match generic rows → FIX: `AND parser_id = 'codex'`.
- R10 (low) Windows `~\` CODEX_HOME, `\\?\` compare → FIX; macOS GUI apps don't see CODEX_HOME (noted, default homes cover Petar's setup).
- Pre-existing: update_codex_context takes session_id from any payload.id → FIX: only from session_meta.
- Perf: startup cleanup scans table each start (ms) → acceptable.

## 2026-10-29 — re-review of d770938 (Claude subagent)
Confirmed fine: hash stability, byte line reader offsets (= BufRead::lines), incomplete-file handling, early return, path keys, dismissed sources, unchanged Claude standard paths.
- RR1 (med) path-based stale cleanup misses rollouts that moved since first scan → FIX: delete rows by the raw hash of skipped re-emits, any path.
- RR2 (med-low) path-based cleanup could delete newer rows of a plain twin → FIX: same (only re-emit hashes are ever deleted); regression test with twins.
- RR3 (low) session-id fix did not repair stored rows → FIX: re-parse updates conversation_id on existing Codex rows; orphan conversations are removed by the startup cleanup.
- RR4 (low) manual add of a ~/.claude symlink alias fell back to the generic parser → FIX: raw CLAUDE_CONFIG_DIR still counts in is_configured_claude_path.
- RR5 (low, accepted) a generic source over a Codex folder can now hold a second copy of a line the Codex source also stores; not a supported setup.

## 2026-10-29 — branch feat/linux-cli (Claude subagent reviewer)
Desktop move verified clean (35 commands identical, build/feature/workspace paths unchanged for CI).
- C1 (med) self-update compared against cli/Cargo.toml version → FIX: `metr_lib::VERSION` (core version, bumped by releases); release script bumps cli too.
- C2 (med) concurrent writers can hit "database is locked" → FIX: exclusive lock file for writing commands + IMMEDIATE scan transactions.
- C3 (med, root) predictable /tmp build dir in self-update → FIX: fresh 0700 dir under ~/.cache/metr (create, not create_all).
- C4 (med) slow client hangs web UI → FIX: own std HTTP/1.0 server, 10 s read/write timeouts, 32-connection cap, close after each response (tiny_http dropped).
- C5 (med) desktop app + CLI on one machine double count (two devices) → FIX: `login`/`install` refuse when the Linux desktop DB exists (`--allow-with-desktop` to override).
- C6 (low-med) `[Sync]` logs on stdout break `--json` → FIX: stderr.
- C7 (low-med) constantly busy server never syncs → FIX: run anyway (still niced) when the last ok run is older than `--force-after-hours` (24).
- C8 (low) root running a user-writable binary; no hardening → FIX: `--system` requires root-owned, non-writable binary path; system units sandboxed (NoNewPrivileges, ProtectSystem=strict, PrivateTmp, ReadWritePaths = METR dirs; web: ProtectHome=read-only).
- C9 (low) any 100.64/10 address accepted → FIX: must be one of `tailscale ip`.
- C10 (low) maintenance never called → FIX: after login and each run.
- C11 (low) the18th: `--system` scans root's home only → open question for Guardian (which users run Codex there).
- C12 (low) stale web unit on reinstall, `%` in path, non-atomic token write, CLI/desktop binary name collision → FIXED (`metr-cli` build name, installed as `metr`). `--locked` kept: release commits include Cargo.lock.

## 2026-10-29 — branch feat/reconcile-endpoint (incremental scan, sync retry, reconcile; Claude subagent)
Confirmed sound: Codex/Claude resume (state, re-emit hashes, CRLF offsets, zst never resumed), retry idempotency, reconcile endpoint (ONLY_FULL_GROUP_BY-safe, user-scoped, auth + throttle, no migrations).
- M1 (high) whole-JSON sources (Gemini, Cline, Continue) stopped importing after the first scan (resume point saved, fallback skipped) → FIX: resume only codex/claude `.jsonl`; fallback whenever parsing starts at byte 0; regression test.
- M2 (med) desktop sync held the DB lock through 429/backoff sleeps (minutes) → FIX: `SyncPatience::ReturnQuickly` for the desktop (stop at 429/5xx as before, short network retries); CLI timer keeps `WaitForServer`.
- M3 (low) `metr reconcile` counted zero-token events the server skips → FIX: same zero-token filter locally.
- Not new: one rejected event blocks later batches (now 250 per batch) → noted for M4.

## 2026-10-29 — branch fix/price-parity (Claude subagent)
Cost math matches the client in every case checked; nothing breaks before the migration; re-sent events update cost.
- P1 (med) before the migration, events priced with the 1-hour split but stored without it → a later repricing lowers them → FIX: until the column exists, price without the split (stored tokens and cost stay consistent). Auto-migrate on deploy NOT added (policy: migrations need Petar's OK).
- P2 (med) existing server rows only get the split after a full resync per device → in STATUS needs-petar / handover.
- P3 (low) client repriced twice per run → FIX: rely on the reprice inside the price pull.

## 2026-10-29 — branch feat/reprice-command (Claude subagent)
Fine: isDirty with decimal:10, chunkById + whereNull paging, price cache per run, UTC timestamps, web price edit path.
- R1 (med-low) case-insensitive MySQL DISTINCT could drop unpriced mixed-case models from the nightly run → FIX: lowercased keys.
- R2 (med) --dry-run wrote and rolled back, holding row locks that can stall syncs → FIX: dry run computes without writing.
- R3 (med, pre-existing) same effective_from on old/new price rows → resolver could pick the old price → FIX: tie-break newest created_at, id.
- R4 (low) alias-only changes missed → FIX: --changed-since uses updated_at.
- minor: --only-missing + --changed-since now rejected; dry-run report labels "before".
