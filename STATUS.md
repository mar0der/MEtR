# METR status

## 2026-10-29
- PLAN.md written (incl. Guardian addendum A–G).
- 17:05 Petar decisions folded in: Codex tracking first (zstd, all Codex homes, format-drift check), per-provider only (no account attribution), Cursor dropped, Grok Bot research via Guardian. Push works (deploy key active, pushed 95ca2e2). Next: M1.1 baseline `cargo test`, then zstd.

## 2026-10-29 evening — M1 in progress (branch `fix/codex-zstd-homes`)
Done (tests: 30 pass + 2 ignored real-data smoke tests; baseline was 21):
- **zstd:** `*.jsonl.zst` rollouts are stream-decoded (zstd crate, long windows accepted). Real 60 MB rollout recompressed with `--long=31`: identical events (58/58).
- **Codex homes:** discovers `~/.codex`, `$CODEX_HOME`, `~/.codex-*`, `~/.codex-accounts/*` (only folders with sessions/archived_sessions/handoffs; `acct1` = auth only is skipped). Auto-registered at startup and on rescan unless an existing source already covers the folder. Inside a Codex home only those 3 rollout folders are scanned.
- **Dedupe:** Codex events reconcile on (provider, raw line hash) across files, so compression, archiving and overlapping homes add no events; startup cleanup removes existing local Codex copies. New local index `idx_usage_events_provider_raw_hash` (additive). Server already dedupes on the same hash.
- **Overcount fix:** Codex re-emits token_count with unchanged totals (28 of 13,674 lines on the20th, ~0.2%); those repeats are now skipped (fix-forward, see BUGS.md).
- **Real-data check on the20th:** scanner vs independent Python count on stable files: 13,362 events, identical input/output sums. All 3 homes: 13,678 events.
- **Format drift seen already:** rollouts since 2026-10-27 also carry a new `token_usage_record` (per response_id). Totals match token_count within 0.01%; we keep token_count as source of truth (no double counting). Drift check (M1.4) must alert if token_count disappears.
- **18:18 Petar go-ahead:** continue through milestones without waiting; ask only for destructive/risky actions.
- **M1.4 format-drift check done** (`0f0f625`): per source, the scanner reports unknown Codex record types, rollouts with usage only in token_usage_record, unreadable files (e.g. corrupt .zst), unscanned file types in rollout folders (e.g. `.jsonl.gz`), and token data outside sessions/archived_sessions/handoffs. Shown as "Format drift: …" in the source's status; kept per file so incremental scans keep reporting it. `fixtures/codex/<format>/expected.json` per known format. First real catch: new record type `inter_agent_communication_metadata` (no usage; now known). the20th homes: no drift.
- **M1.5 done** (`eeaa63d`): `.claude-accounts/account-N/projects` symlinks are not double counted (test; no code change needed).
- Codex quota ~exhausted (acct1 98%, acct2 94% weekly) → review + test by Claude subagents, running now.
Next: fold review/test findings, merge to main (src-tauri only, no backend deploy), then M2 (core crate split, Linux CLI/daemon, web UI) and the Mac/Windows release.

## 2026-10-29 ~18:45 — M1 merged + released; M2 CLI in review
- **M1 merged to main** (`6ee544c`) after review, test, and a re-review: zstd rollouts, every Codex home, dedupe by raw line, re-emit cleanup by hash (also repairs old rows wherever they live), format-drift report, `.claude-accounts` symlink test, 48 tests. Real data on the20th: scanner = independent count exactly (13,507 events on stable files).
- **Release 26.41.0 tagged** (`v26.41.0`) for Mac/Windows. CI run: https://github.com/mar0der/MEtR/actions/runs/40893433131. Watch: the previous release (26.40.2) built fine but its publish job failed at "Connect to Tailscale" (GitHub secret / Tailscale auth key), so it likely never reached the update server.
- **Petar 18:26** single Claude account: no multi-account code in METR; a `CLAUDE_CONFIG_DIR` that is only an alias of `~/.claude` is dropped; Mac cleanup steps below for the Guardian.
- **Petar 18:29** definition of done + **18:38** headless schedule folded into PLAN.md (M2 1b/1c, M3).
- **M2 on `feat/linux-cli`** (`2feb608`): Tauri behind an `app` feature (desktop unchanged), `metr` CLI: status/scan/usage/sync/login/logout, `run` (load-aware one-shot), `install`/`uninstall` (systemd timer hourly|daily, Nice 19, idle IO; optional web UI service), `serve` (127.0.0.1 + Tailscale only, Host-header check), `self-update` (builds latest tag from source). Review + test running (Claude subagents; Codex quota exhausted).
Next: fold findings → merge → install on the20th (timer + web UI) → Petar runs `! metr login --user <name>` → provide the18th build + steps to the Guardian → M3.

## 2026-10-29 ~19:05 — M2 done on the20th (pending login)
- **Merged to main:** headless CLI (`ba0287a`, reviewed + tested, 12 review and 4 test findings fixed), port 8790 (`71ef219`; 8765 is llama-server), portable build (`dc4019c`: rustls + glibc 2.17 via cargo-zigbuild; desktop keeps native TLS, identical reqwest features). Tests: 49 core + 8 CLI.
- **the20th installed:** `~/.local/bin/metr` (portable 26.41.0), user units `metr-sync.timer` (hourly, Nice 19, idle IO) + `metr-web.service`; linger on. Web UI: http://127.0.0.1:8790/ and http://100.115.64.43:8790/ (LAN address does not answer, verified). First scan 10 s: 13,929 Claude + 14,135 Codex calls from `~/.claude`, `~/.codex`, `~/.codex-acct2`, `~/.codex-accounts/acct2`. Scheduled pass verified (`metr status` shows last run).
- **Release 26.41.0 not published:** builds OK on all 3 platforms; publish job fails at "Connect to Tailscale" (same as 26.40.2) → asked Guardian to rotate `TAILSCALE_AUTH_KEY` and re-run (outbox 20261029-1910).
- **the18th:** package `src-tauri/target/dist/metr-26.41.0-linux-x86_64.tar.gz` (+ .sha256) and steps in `docs/linux-cli.md` handed to the Guardian (outbox 20261029-1915).
Next: M3 (incremental scan by offset, sync batching/retry, reconciliation endpoint + `metr reconcile`).

## 2026-10-29 ~19:15 — M3 in review (branch `feat/reconcile-endpoint`)
- **Incremental scan** (`92431b6`): growing logs resume at the last complete line (byte + line offset + parser state + 4 KB head hash); rewritten/compressed files parse from the start. Real data on the20th: incremental = fresh full scan exactly (13,948 Claude / 14,135 Codex calls); unchanged rescan ~0.1 s.
- **Sync** (`86ffd30`): 250-event batches, retries on network errors/5xx (backoff) and 429 (Retry-After), so a first upload rides out the 60 req/min limit.
- **Reconcile** (`040a708`): server `GET /api/v1/sync/reconcile` (read-only: per device × provider totals + duplicate checks) and `metr reconcile`. Backend change → merge auto-deploys; after merge I verify login, sync from the20th, dashboard, and that the Mac/Windows devices keep syncing.
- Backend test suite: new tests pass; 9 pre-existing SQLite-only failures noted in BUGS.md.

## 2026-10-29 ~19:25 — M3 merged (`dd79367`), backend deploy BLOCKED
- M3 review fixed (whole-JSON resume regression, desktop sync stays quick under rate limits, reconcile zero-token filter); merged to main. Incremental = full scan on real data again.
- **Deploy Backend failed at "Setup SSH"** (runner cannot reach DEPLOY_HOST over Tailscale) → production unchanged and healthy; reconcile endpoint not live yet. Same root cause as the release publish failure. Details + re-run steps in outbox `20261029-1910-release-blocked.md` (Guardian/Petar: Tailscale/secrets are off-limits for me).

## 2026-10-29 ~19:35 — handover state
- **the20th** runs the M3 CLI (`~/.local/bin/metr`, portable build): hourly timer + web UI on 127.0.0.1:8790 and 100.115.64.43:8790. Waiting for Petar's `! metr login --user <name>`; then `metr sync` uploads ~28k events (rides out the 60 req/min limit) and `metr reconcile` checks them (needs the reconcile endpoint deployed).
- **the18th package**: `~/Developer/_po/metr/outbox/files/metr-26.41.0-linux-x86_64.tar.gz` (+ .sha256), steps in outbox `20261029-1915-the18th-install.md` / `docs/linux-cli.md`.
- **Blocked on CI→deploy-host route**: release 26.41.0 (Mac/Windows) and the backend deploy (reconcile endpoint). Re-run steps in outbox `20261029-1910-release-blocked.md`.
- **Waiting on Guardian**: Grok Bot local-usage research (structure only), Claude desktop `local-agent-mode-sessions` sample, Mac `.claude-accounts` cleanup run, Windows sweep, the18th users/glibc.
- **Cleanup done**: removed src-tauri/target (16.2 GB), sync-server/backend/vendor (83 MB), node_modules (177 MB), dist, scratch tools; merged branches deleted; no worktrees. Kept: zig 0.13 in `~/.local/opt/zig-linux-x86_64-0.13.0` and `cargo-zigbuild` in `~/.cargo/bin` (needed by `scripts/build-linux-cli.sh`).
- **Next (M4 / follow-ups)**: one rejected event blocks later sync batches (quarantine bad events); per-machine reconciliation report once all 4 machines sync; Claude desktop agent sessions; Codex sqlite fallback only if the Mac re-sweep still shows a gap.

## Mac cleanup: retire ~/.claude-accounts (for Guardian to run on the Mac, Petar approving)
Petar decision 18:26: one Claude account now; METR scans only the real `~/.claude`. Do not run step 4 until steps 1–3 are clean.
1. **Read-only checks**
   - `ls -la ~/.claude-accounts/ ~/.claude-accounts/*/`
   - `for d in ~/.claude-accounts/account-*; do echo "$d -> $(readlink "$d/projects")"; done` (expect each → `~/.claude/projects`)
   - `find ~/.claude-accounts -type f -name '*.jsonl' | wc -l` (expect 0; if >0, list paths only and stop, PO decides whether to move them into `~/.claude/projects` first)
   - What still points at them: `env | grep -i claude_config`; `launchctl getenv CLAUDE_CONFIG_DIR`; `grep -n 'claude-accounts\|CLAUDE_CONFIG_DIR' ~/.zshrc ~/.zprofile ~/.bash_profile ~/.bashrc 2>/dev/null`; `grep -rln 'claude-accounts' ~/bin ~/.local/bin ~/Library/LaunchAgents 2>/dev/null`
   - METR sources pointing there (read-only): `sqlite3 -readonly ~/Library/Application\ Support/com.petarpetkov.metr/metr.db "SELECT path, enabled FROM log_sources WHERE path LIKE '%claude-accounts%'"` (adjust the app-data folder name if different)
2. Report the results to the PO (paths and counts only).
3. If anything sets `CLAUDE_CONFIG_DIR` to an account dir: remove that line (Petar approves the exact edit) and open a new shell.
4. **Remove (reversible):** `mv ~/.claude-accounts ~/.Trash/claude-accounts-$(date +%Y%m%d)`. In METR → Sources, remove any source under `.claude-accounts`. Do not touch `~/.claude`.
5. Optional, by Petar by hand: delete the old per-account "Claude Code-credentials…" items in Keychain Access (no scripting).
6. Verify: METR → Rescan; Claude totals for the last 30 days unchanged (the account folders only held symlinks to `~/.claude/projects`).

## needs-petar
- **CI cannot reach the deploy host** (blocks releases since 26.40.2 and backend deploys): fix the runner→the18th Tailscale route/secret, then re-run runs 40899903389 and 40893433131.
- **Device alias leak:** server device `windows-DESKTOP-PG7TRK4` (last seen Oct 9) has an API-key-like string as its alias. Proposal: clear that alias (single-row UPDATE, non-destructive). Please rotate that Kimi key. Not printed anywhere.
- **Linux device login (ready now):** type `! metr login --user <your METR username>` in the PO session (password prompt; device `linux-the20th`). Until then the20th scans hourly but does not upload.
