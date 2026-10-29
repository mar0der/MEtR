# METR plan (PO: Claude Code on the20th)

Owner: METR PO (Claude Code, `metr-po`). Started 2026-10-29. Side quest: normal pace.
Inputs: PO brief (`~/Developer/_po/metr-po.md`), Guardian sweep (Oct 29) + addendum
`_po/metr/inbox/20261029-1640-sweep-addendum.md`, **Petar decisions**
`_po/metr/inbox/20261029-1705-petar-decisions.md` (these override earlier items).

## Petar decisions (Oct 29, 17:04 Dubai)
1. **Top priority: Codex tracking.** Read `.jsonl.zst` (sessions + archived_sessions), honour `CODEX_HOME`
   and every Codex home (`~/.codex`, `~/.codex-acct2`, `~/.codex-accounts/*`). Add a **format-drift
   check** (scanner reports unknown/unparsed files and new layouts; one fixture per known format).
   Watching for drift is an **ongoing duty**.
2. **Track per provider only** (Anthropic/Claude, OpenAI/Codex). **No per-account/subscription
   attribution.** Dedupe so Mac `~/.claude-accounts` symlinks are not double counted.
3. **Cursor dropped.** No further work. Existing Cursor scanner code and historical data stay as they are.
4. **New research: Grok Bot** (desktop app, Petar's main tool). Does it store token usage locally on
   the Mac? Read-only, via the Guardian. Report findings, do not guess.
5. **the20th must be tracked** (Claude + both Codex homes): Linux CLI/daemon + local web UI
   (127.0.0.1 and Tailscale only).
6. **GitHub:** Guardian will add the deploy key; keep committing locally until then.

## Definition of done (Petar, Oct 29 18:29 Dubai)
A correct, **deduplicated total of ALL Claude (Anthropic) and Codex (OpenAI) usage across every
machine**, with optimized scanning and app → server sync, proven by a **reconciliation report per
machine and per provider** (files found, events, tokens locally vs what the server holds; zero
double counts).

| Machine | Sources | How |
|---|---|---|
| the20th | `~/.claude`, `~/.codex`, `~/.codex-acct2` (+ `~/.codex-accounts/*`) | Linux CLI/daemon (M2) |
| the18th (server) | `/root/.codex` (254 session files, codex CLI at `/usr/local/bin/codex`), no Claude | Same Linux build, run as root (root-readable scan), syncing to local metr-sync. **Guardian installs; PO provides build + steps.** |
| Mac | `~/.claude` (single account), Codex incl. `.zst` + Codex app DBs if they hold usage | Desktop release (M1 fixes) |
| Windows | later, via Windows-side agent sweep (Guardian drafts email for Petar) | Desktop release |

## Goals
- **(a)** Own METR development on the20th.
- **(b)** Linux edition: headless daemon (`systemd --user`) + CLI (`metr usage|sync|status|self-update`)
  + local web UI bound only to 127.0.0.1 and the Tailscale IP (100.115.64.43), never 0.0.0.0.
  Runs on the20th as its own device. Shares the Rust scanner via a core crate.
- **(c)** Complete, non-duplicated **per-provider** token counts for Claude and Codex on every machine.

## Facts established (Oct 29, read-only checks on the20th + Guardian Mac sweep)
| # | Item | Finding | Action |
|---|------|---------|--------|
| A | **Codex `.zst` rollouts** | Mac: 255 `*.jsonl.zst` + 2 `*.jsonl` in sessions, 6 `.zst` in archived. METR reads no `.zst`. Mac openai events 31k (Aug) → 9.6k (Sep) → 639 (Oct). None on the20th yet. | Decode `.jsonl.zst` (zstd crate, streaming). The local event id hashes the **file path**, so `x.jsonl` vs `x.jsonl.zst` would duplicate locally: normalize identity path (strip `.zst`, treat sessions ↔ archived_sessions as the same file), reconcile by `raw_record_hash`. The server already dedupes per device on `source_event_hash` (raw line hash). |
| 1 | **Codex homes** | the20th: `~/.codex` (296 jsonl), `~/.codex-acct2` (138), `~/.codex-accounts/acct2` (full home, 1 jsonl); `acct1` holds only auth. `CODEX_HOME` ignored. | Discover `~/.codex`, `$CODEX_HOME`, `~/.codex-*`, `~/.codex-accounts/*` that look like a Codex home (`sessions/` or `state_5.sqlite`). Scan only `sessions/`, `archived_sessions/`, `handoffs/` in them. All map to provider `openai`. |
| A2 | **Codex format drift already** | Since 2026-10-27 rollouts also contain `token_usage_record` (per `response_id`, `usage`/`turn_token_usage`/`thread_token_usage`). Totals equal token_count within 0.01%. New key `cache_write_input_tokens` (0 today). | Keep token_count as source; read `cache_write_input_tokens`. Drift check flags rollouts that have token_usage_record but no token_count (then switch). |
| 3 | Codex handoffs | Inside `~/.codex`, depth OK, not skipped. | Fixture test (plain + `.zst`). |
| 2 | Codex app threads without a rollout | the20th: every thread has its rollout. `thread_history_1` items hold no tokens; only `state_5.threads.tokens_used` (total). Mac "missing" rollouts are most likely the `.zst` files. | Re-check Mac after A ships. Only if still short: low-confidence total-only fallback from `threads.tokens_used` (read-only sqlite). |
| 6 | Mac `~/.claude-accounts/account-{1,2,3}/projects` → symlinks to `~/.claude/projects` (**18:26 decision: one Claude account; retire `.claude-accounts`; scan only real `~/.claude`**) | Scanner follows `projects` symlink only inside its own root; request-id dedupe exists. | Regression test: account dirs as extra sources never double count (canonical-path dedupe). |
| B | Claude desktop app (Mac) `local-agent-mode-sessions` | jsonl with `usage` records (11–16/file), not scanned. | Anthropic source; needs structure-only sample from Guardian for a fixture. Lower priority than A/1. |
| G | **Grok Bot** (Mac desktop app) | Unknown. | Research via Guardian (asked in outbox): app support dirs, logs, sqlite; structure only. Decide scanner work only on evidence. |
| E | Device alias holds API-key-like string | needs-petar (STATUS.md). | Never print it. |
| — | Dropped | Per-account attribution (`account_hint_hash`), Cursor, Kimi (unused since Jun 24). | — |

## Milestones

### M1 — Codex tracking fixed (top priority; in current `src-tauri/src/lib.rs`)
Done before the core split so the Mac/Windows fix ships fastest (rollouts stay on disk, so the lag
costs reporting, not data).
1. M0 baseline: `cargo test` for the scanner on the20th.
2. **zstd** read for `.jsonl.zst` + identity normalization. Tests: compressed parse == plain;
   plain → `.zst` rename adds 0 local events; sessions → archived move adds 0 events.
3. **Codex home discovery** + `CODEX_HOME` + scan-dir restriction. Tests with two fake homes, handoffs.
4. **Format-drift check:** per source, the scanner counts files that are skipped, fail to parse,
   or parse with 0 usage events while they look like rollouts, plus unknown top-level record types
   and unexpected layouts (e.g. new extensions/dirs in a Codex home). Shown in source status, CLI `metr status`,
   and synced as a device health note if cheap. `fixtures/codex/<format>/` holds one fixture per
   known format (plain jsonl, `.jsonl.zst`, archived, handoff, legacy shapes); a test asserts each parses.
5. Claude `.claude-accounts` double-count test.
6. Review + test (Codex if quota, else Claude subagents) → REVIEW-FINDINGS.md / TEST-FINDINGS.md.
7. **Mac/Windows release** via release.yml (update channel unchanged). Guardian re-sweeps the Mac:
   Codex Oct counts should jump, no duplicates. Then decide item 2.

### M2 — the20th tracked: Linux CLI/daemon + web UI
1. **Shared core** (mechanical): Tauri commands and `run()` behind an `app` cargo feature (default on);
   tauri crates optional. Workspace member `src-tauri/cli` (binary `metr`) depends on the core with
   `default-features = false`, so it builds without webkit and Mac/Windows builds are unchanged. CLI token in a 0600 file under
   `~/.config/metr/` when no Secret Service.
2. **CLI** `metr`: `status` (incl. drift report), `usage [--since --until --provider --by day|model|project] --json`,
   `scan`, `sync [--full]`, `login` (Petar types the password), `self-update`, `daemon`, `serve`.
3. **Web UI**: read-only per-provider dashboard + JSON API. Binds explicit addresses only
   (127.0.0.1 + Tailscale IP via `tailscale ip -4`); refuses `0.0.0.0`/`::`.
4. **Install on the20th**: `~/.local/bin/metr`, `systemd --user` `metr.service`. Sources: `~/.claude`
   + all Codex homes. Device `linux-the20th`. Verify server receives events; Mac + Windows keep syncing.
4b. **the18th**: release build + install steps for the Guardian (root, `/root/.codex`, system or root
   user unit, sync to the local metr-sync URL; web UI off or localhost-only).
5. **Linux self-update:** stage 1 `metr self-update` rebuilds from the local git checkout and swaps the
   binary atomically (the20th). Stage 2 (later): signed tarball via release.yml + server target
   `linux-cli` (needs the18th's `/usr/local/sbin/metr-release-publish` wrapper changed: Guardian).
6. Review + test before deploy.

### M3 — Fast scan + sync, reconciliation (definition of done)
1. **Incremental scan by offset:** append-only JSONL (Claude, plain Codex) resumes at the last parsed
   byte offset when the file only grew (prefix check), with the Codex parse context (session, cwd,
   model, last total) saved per file. No full rescans in normal operation; `.zst` files are immutable,
   parsed once.
2. **Sync:** larger batches, idempotent event ids (already: server dedupes on request/message/raw hash),
   retry with backoff on 429/5xx, resume after failure without resending synced rows.
3. **Reconciliation report:** `metr reconcile` (CLI) and a desktop view: per provider, files found,
   events, input/output/cache tokens locally vs server totals for this device; flags mismatches and any
   duplicate identities. Needs an additive read-only server endpoint
   (`GET /api/v1/sync/summary`, per device + provider), on a branch, reviewed, deploy verified.
4. Cross-machine report (server side): per device × provider totals, plus a duplicate-identity check
   (same raw hash from two devices = expected only for copied logs).
5. Run on all 4 machines; publish the report (STATUS.md + artifact for Petar).

### M4 — Follow-ups
- Claude desktop `local-agent-mode-sessions` (B) once sample arrives.
- Grok Bot scanner, only if research finds local token data.
- Codex sqlite fallback (2) only if the Mac re-sweep still shows a gap.

## Ongoing duties
- **Codex format drift:** check drift reports from all devices after each Codex update; add a
  fixture + parser change for every new format; release promptly.
- Cleanup per `~/Developer/AGENT-CLEANUP-RULE.md`.

## Safety rules (from brief)
No DB restarts or destructive migrations without Petar; additive migrations only. Never expose
ports publicly. Never print/commit secrets, tokens, or conversation content (inspect key names and
counts only). No kill-by-name on the20th. Don't break Mac/Windows update channel.

## Branch, deploy and release rules (Guardian, Oct 29)
- Push to `main` touching `sync-server/backend/**` (or `deploy-backend.yml`) **auto-deploys live metr-sync**.
  All code work on feature branches; merge to `main` only after review + test.
- Right after any backend merge: verify metr-sync (login, dashboard, `/api/v1/sync/events` still
  accepted from the Mac and Windows devices).
- Tag `vX.Y.Z` triggers app releases (release.yml): tag only when a release is reviewed and ready.
- Docs-only commits may go straight to `main`.
