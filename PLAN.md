# METR plan (PO: Claude Code on the20th)

Owner: METR PO (Claude Code, `metr-po`). Started 2026-10-29. Side quest: normal pace.
Inputs: PO brief (`~/Developer/_po/metr-po.md`), Guardian sweep (Oct 29) + addendum
`_po/metr/inbox/20261029-1640-sweep-addendum.md`.

## Goals
- **(a)** Own METR development on the20th (moved from the Mac).
- **(b)** Linux edition: headless daemon (`systemd --user`) + CLI (`metr usage|sync|status|self-update`)
  + local web UI bound only to 127.0.0.1 and the Tailscale IP (100.115.64.43), never 0.0.0.0.
  Runs on the20th as its own device. Reuses the Rust scanner via a shared core crate.
- **(c)** Measure tokens squeezed out of each subscription: every source scanned, no double
  counting, usage attributed to the right provider account.

## Facts established (Oct 29, read-only checks on the20th + Guardian Mac sweep)
| # | Gap | Finding | Fix |
|---|-----|---------|-----|
| A | **Codex rollouts zstd-compressed** (top priority) | Mac: 255 `*.jsonl.zst` + 2 `*.jsonl` in sessions, 6 `.zst` in archived. METR reads no `.zst`. Mac openai events 31k (Aug) → 9.6k (Sep) → 639 (Oct). None on the20th yet. | Decode `.jsonl.zst` (zstd crate, streaming). Local event id currently hashes the **file path** → `x.jsonl` vs `x.jsonl.zst` would duplicate locally. Normalize path for identity (strip `.zst`; treat sessions/archived_sessions moves as the same file) and reconcile by `raw_record_hash` like `find_legacy_event_id`. Server already dedupes per device on `source_event_hash` (= raw line hash) so re-reads become "updated", not new. |
| 1 | Multiple Codex homes | the20th: `~/.codex` (296 jsonl), `~/.codex-acct2` (138), `~/.codex-accounts/acct2` (full home, 1 jsonl; `acct1` holds only auth). `CODEX_HOME` ignored. | Candidates: `~/.codex`, `$CODEX_HOME`, `~/.codex-*` and `~/.codex-accounts/*` dirs that look like a Codex home (`sessions/` or `state_5.sqlite`). Inside Codex homes scan only `sessions/`, `archived_sessions/`, `handoffs/` (`acct2/log` and other `.log/.md` are noise). |
| 1b | Account attribution | `~/.codex` mixes 2 accounts (codex-switch swaps auth). Every rollout's `session_meta.payload.creator_account_id` names the account. Server already accepts `events[].account_hint_hash` and maps it to `provider_accounts.external_account_hint_hash` (exact match), but **the client never sends it**. | Parser records `creator_account_id` → `account_hint_hash = sha256("metr-acct|openai|"+id)` (never send the raw id). Store on local events, sync it. UI/CLI lists distinct hints so Petar can bind each to a ProviderAccount/subscription. No server migration needed. |
| 2 | Codex app threads w/o jsonl | the20th: all 294+138 threads have their rollout. `thread_history_1.sqlite` items hold **no token data**; only `state_5.threads.tokens_used` (total). Mac "missing" rollouts are most likely the `.zst` files (A). | After A ships, re-check Mac. If threads still lack a rollout, add a low-confidence fallback event from `threads.tokens_used` (total only, `unknown_tokens`), read-only sqlite, only for threads whose rollout (plain or .zst) is absent. |
| 3 | Codex handoffs | Path is inside `~/.codex`, depth ok, not in skip list. | Fixture test to lock it in (plus `.zst`). |
| B/5 | Claude desktop app (Mac) | `local-agent-mode-sessions` jsonl have `usage` records (11–16/file), not scanned. `claude-code-sessions/*.json` (1953) are metadata; transcripts likely in `~/.claude/projects`. | Add `~/Library/Application Support/Claude/local-agent-mode-sessions` as a Claude source; ask Guardian for one redacted sample line (structure only) to build a fixture. Verify claude-code-sessions transcripts land in `~/.claude/projects` (Guardian check). |
| 4/C | Cursor | Branch `worktree-cursor-claude-session-scan` is **already merged** into main. Server: 833 Mac Cursor events, last Aug 28. Cursor state is in `state.vscdb` (8.3 GB) + workspaceStorage. | Investigation (needs Mac samples via Guardian): do any local Cursor stores hold per-request token counts? If not, document "Cursor tokens only via the usage CSV import" (server already has `CursorUsageCsvParser`). Not a blocker for M1. |
| 6 | Mac `~/.claude-accounts/account-{1,2,3}/projects` → symlinks to `~/.claude/projects` | Scanner only follows `projects` symlink inside its own root, and request-id dedupe protects. | Test: account dirs added as sources do not double count. Attribution per Claude account: check whether transcripts carry any account marker; otherwise time-window attribution rules on the server. |
| 7 | the20th untracked | No device. | Solved by M1 install. |
| 8/D | Devices / Kimi | Kimi unused since Jun 24 (not a gap). | — |
| E | Device alias holds an API-key-like string (windows-DESKTOP-PG7TRK4) | needs-petar | Propose clearing the alias (STATUS.md); Petar rotates that Kimi key. Never print it. |
| F | Toolchain | `cargo`/`rustc` now present in `~/.cargo/bin`; `zstd` CLI present (for fixtures). | Tauri GUI build deps (webkit2gtk etc.) not needed for the CLI; ask before any apt install. |

## Milestones

### M0 — Setup (today)
- PLAN/STATUS/BUGS/TEST-FINDINGS/REVIEW-FINDINGS files. Reply to Guardian outbox.
- `cargo test` baseline for current scanner on the20th (CLI-only build path, no webkit).
- Push check: if deploy key missing → commit locally, "push blocked" in STATUS.md.

### M1 — Scanner fixes + Linux CLI/daemon on the20th
1. **Core crate split** (mechanical, no behavior change): Cargo workspace `crates/metr-core`
   (db, migrations, scanner, parsers, pricing, sync client) ← `src-tauri` (Tauri commands only)
   and `crates/metr-cli`. Keyring optional: CLI uses a 0600 token file in `~/.config/metr/` when no
   Secret Service. All existing tests pass unchanged.
2. **zstd** (A) with fixtures `fixtures/codex/*.jsonl.zst` + tests: compressed parse equals plain;
   plain→zst rename does not add local events; sessions→archived move does not add events.
3. **Codex homes + account hint** (1, 1b, 3): candidate discovery, scan-dir restriction,
   `account_hint_hash` stored + synced. Tests with two fake homes + mixed-account `~/.codex`.
4. **Claude desktop local-agent-mode-sessions** (B/5) — once sample structure arrives.
5. **CLI** `metr`: `status`, `usage [--since --until --provider --account --by day|model|project] --json`,
   `scan`, `sync [--full]`, `login` (interactive; Petar types the password, agents never do),
   `self-update`, `daemon` (scan + sync loop), `serve` (web UI).
6. **Web UI**: read-only dashboard + JSON API; binds explicit addresses from config
   (default `127.0.0.1` + Tailscale IP discovered via `tailscale ip -4`); refuses `0.0.0.0`/`::`.
7. **Install on the20th**: `~/.local/bin/metr`, `systemd --user` units `metr.service` (daemon+serve).
   Device name `linux-the20th`. Verify events reach the server and Mac/Windows keep syncing.
8. **Self-update channel for Linux CLI**: release.yml builds `metr-cli-linux-x86_64.tar.gz` + signature;
   server UpdateController serves target `linux-cli` (additive only; Mac/Windows targets untouched).
- Before deploy: review + test by Codex (if quota) else Claude subagents → REVIEW-FINDINGS.md / TEST-FINDINGS.md.

### M2 — Mac/Windows releases with scanner fixes
- Release via release.yml (version bump `26.4x`), update channel unchanged.
- After release: Guardian re-sweeps Mac (Codex Oct counts should jump; no duplicates), then (2) fallback decision.
- Windows: Guardian email sweep results → new gaps folded in here.

### M3 — Subscription yield
- Bind account hints to ProviderAccounts/subscriptions; per-subscription "tokens squeezed" view
  (tokens and API-equivalent cost per billing cycle) in web UI, CLI, and server dashboard.
- Cursor decision (C), Codex sqlite fallback (2) if still needed.

## Safety rules (from brief)
No DB restarts or destructive migrations without Petar; additive migrations only. Never expose
ports publicly. Never print/commit secrets, tokens, or conversation content (inspect key names and
counts only). No kill-by-name on the20th. Don't break Mac/Windows update channel. Cleanup per
`~/Developer/AGENT-CLEANUP-RULE.md`.
