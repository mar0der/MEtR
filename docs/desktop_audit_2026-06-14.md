# MEtR Desktop App — Adversarial Audit Report

**Date:** 2026-06-14  
**Version audited:** v26.24.8  
**Scope:** `src-tauri/src/lib.rs`, `src-tauri/src/main.rs`, Tauri config/capabilities, React frontend (`src/main.tsx`), build tooling, CI/CD (`release.yml`, `deploy-backend.yml`), and deployment pipeline.

---

## Executive Summary

The desktop app is well-structured in several areas: SQL queries are parameterized, JSON parsing uses `serde`, and there are no `unsafe` blocks or hard-coded secrets in source. However, the audit found **one Critical** and several **High** issues that should be addressed before the next release, plus a number of Medium/Low robustness improvements.

The biggest risks are:

1. **Arbitrary code/URL execution via “Open project folder”** — a malicious log file can turn a benign UI click into launching an attacker-controlled app or URL.
2. **Main-thread blocking / async runtime stalls** — heavy DB/file/network work runs synchronously, freezing the UI and risking deadlocks.
3. **CI/CD pipeline weaknesses** — long-lived SSH/Tailscale keys, floating action tags, `--force` release overwrites, and missing artifact integrity checks expose the release process to supply-chain and server-compromise risks.
4. **Plaintext sync token and debug-info leakage** — the bearer token is stored unencrypted in SQLite, and the debug export copies PII/local paths to the clipboard.

---

## Critical

### C1 — `open_project_path` can launch arbitrary apps or URLs
- **Location:** `src-tauri/src/lib.rs` (`open_project_path`), invoked from `src/main.tsx` “Open project folder”
- **Issue:** The backend receives a `path: String` from the frontend and passes it straight to `open` / `explorer` / `xdg-open` without validating that it is an existing directory under the configured project root. The value originates from untrusted log fields (`cwd`, `workspace`, etc.).
- **Impact:** A malicious JSONL log can set `cwd` to `/Applications/Malware.app`, `file:///etc/passwd`, `https://attacker.com`, or a UNC share. When the user clicks the folder icon, the attacker’s payload runs.
- **Fix:** Canonicalize the path, verify it exists and is a directory under the configured project root or the user's home, reject URLs and values starting with `-`, and consider using the `opener` crate after validation.

---

## High

### H1 — Long-running commands block the Tauri main thread / async runtime
- **Location:** `rescan_all`, `rescan_all_full`, `rebuild_projects`, `login_sync`, `pull/push_pricing`, `add_pricing`, `sync_now` / `full_resync`
- **Issue:** Most heavy I/O and database operations are synchronous `#[tauri::command]` functions. `sync_now` / `full_resync` are `async` but lock a `std::sync::Mutex<Connection>` inside async code, blocking the runtime worker.
- **Impact:** UI freezes during scans; concurrent operations can deadlock; startup `recalculate_event_costs` can white-screen large DBs.
- **Fix:** Move blocking work into `tauri::async_runtime::spawn_blocking`; replace `std::sync::Mutex<Connection>` with `tokio::sync::Mutex` or a dedicated blocking DB actor.

### H2 — Path traversal / arbitrary file access through `add_source`
- **Location:** `src-tauri/src/lib.rs` (`add_source`, `scan_source`)
- **Issue:** Any filesystem path can be added as a source and recursively walked/ read. Symlinks are not restricted.
- **Impact:** A compromised frontend, malicious webview, or user mistake can add `/`, `/etc`, `C:\Windows`, etc., causing sensitive file reads and path leakage.
- **Fix:** Restrict sources to paths selected via the Tauri dialog plugin, canonicalize, enforce an allowed root, and disable symlink following outside the root.

### H3 — Unbounded memory and recursion in log parsing
- **Location:** `fs::read_to_string`, `parse_content`, `parse_file_streaming`, `collect_json_events`
- **Issue:** Files < 100 MB are read fully into memory; JSONL lines and recursive JSON collection have no length/depth limits.
- **Impact:** A 99 MB single-line file or deeply nested JSON can exhaust RAM or overflow the stack (DoS).
- **Fix:** Stream with `BufReader`, cap line length and JSON depth, and enforce per-file size limits.

### H4 — Sync auth token stored in plaintext in SQLite
- **Location:** `login_sync`, `logout_sync`, `sync_config` schema
- **Issue:** The server bearer token is stored directly in the unencrypted SQLite DB under app data.
- **Impact:** Backups, other apps, or copied DBs expose the account token.
- **Fix:** Store the token in the OS keychain (e.g., `keyring` crate) or encrypt the DB with a keychain-derived key.

### H5 — Data loss in pricing sync and project unmerge
- **Location:** `pull_pricing_from_server`, `unmerge_project`, `apply_project_management`
- **Issue:** `pull_pricing_from_server` deletes all non-user-override pricing rows not returned by the server, so an empty/truncated server response wipes the catalog. `unmerge_project` cannot restore the source project row because it was deleted.
- **Impact:** Broken cost calculations; permanent loss of project identity on unmerge.
- **Fix:** Use soft deletes / tombstones for merged projects; only update/delete pricing entries explicitly present in the response; validate response non-emptiness.

### H6 — CI/CD uses long-lived SSH + Tailscale keys and disables host verification
- **Location:** `.github/workflows/release.yml`, `.github/workflows/deploy-backend.yml`
- **Issue:** A reusable Tailscale authkey and long-lived SSH private key are written to disk in CI; `StrictHostKeyChecking=no` in backend deploy; `ssh-keyscan` is used without verification.
- **Impact:** A compromised repo or action can pivot straight into the production server or MITM the deploy.
- **Fix:** Use short-lived Tailscale OAuth clients, pin known host keys, remove `StrictHostKeyChecking=no`, and require deployment-environment approvals.

### H7 — macOS notarization missing; Windows/Linux installers unsigned
- **Location:** `src-tauri/tauri.conf.json`, `.github/workflows/release.yml`
- **Issue:** macOS is signed but not notarized; Windows MSI and Linux `.deb`/AppImage have no OS-level signing. Fresh installers rely only on transport security and updater Ed25519 signatures.
- **Impact:** Compromised web server or MITM can serve malicious installers that the OS will not detect.
- **Fix:** Add macOS notarization; acquire a Windows code-signing cert and sign the MSI; publish GPG-signed `.deb` metadata and `SHA256SUMS`.

### H8 — `--force` release publish allows silent overwrite
- **Location:** `.github/workflows/release.yml`, `PublishUpdateRelease.php`
- **Issue:** The publish command is always invoked with `--force`, deleting and recreating the release record.
- **Impact:** A retagged or compromised CI run can overwrite a published version with attacker-controlled artifacts.
- **Fix:** Remove `--force`; gate re-publishing behind manual approval and artifact attestation; keep immutable release records.

### H9 — No artifact checksums / attestation / provenance
- **Location:** `.github/workflows/release.yml` release job
- **Issue:** The pipeline does not generate SHA-256 checksums, SLSA provenance, or GitHub artifact attestations.
- **Impact:** Users cannot independently verify installer integrity; server compromise is harder to detect.
- **Fix:** Generate `SHA256SUMS` and sign it; include artifact SHA-256 in the updater manifest; enable GitHub artifact attestations.

### H10 — Floating / unpinned third-party action tags
- **Location:** `.github/workflows/release.yml`, `.github/workflows/deploy-backend.yml`
- **Issue:** `dtolnay/rust-toolchain@stable`, `softprops/action-gh-release@v2`, `tailscale/github-action@v3`, and major-version-pinned GitHub actions can change without review.
- **Impact:** Supply-chain compromise of an action immediately affects every build.
- **Fix:** Pin all actions to full-length commit SHAs and verify with `pin-github-action`; use Dependabot/Renovate for updates.

### H11 — Workflow dispatch can deploy without a tag/GitHub Release
- **Location:** `.github/workflows/release.yml`
- **Issue:** The workflow triggers on tags **and** `workflow_dispatch`. The deploy/publish steps are not gated on tags, while the GitHub Release step is.
- **Impact:** A manual run from any branch can push untagged artifacts to the public update server.
- **Fix:** Gate all publish/deploy steps on tag pushes only, or disable deploy on `workflow_dispatch`.

---

## Medium

| ID | Issue | Location | Recommended Fix |
|----|-------|----------|-----------------|
| M1 | Token counts wrap negative via `u64 → i64` cast | `int_field` | Saturate at `i64::MAX` or use `u64`/`i128` |
| M2 | `merge_projects` lacks target validation | `merge_projects`, `apply_project_management` | Validate target exists, same provider, not already merged; use a transaction |
| M3 | `debug_sync_state` exposes device UUID + file paths | `src-tauri/src/lib.rs`, `src/main.tsx` | Restrict to debug builds or redact `device_uuid` and `source_file_path` |
| M4 | Any `server_url` accepted for sync | `configure_sync_server`, `login_sync` | Validate HTTPS URL; pin official domain by default; require explicit confirmation to change |
| M5 | Destructive commands lack backend confirmation | `clear_parsed_data`, `remove_source`, `delete_subscription` | Add “type DELETE” challenge or move confirmation into the command |
| M6 | Vite `envPrefix` exposes `TAURI_*` build secrets to client | `vite.config.ts` | Restrict `envPrefix` to `VITE_` only |
| M7 | Background scan/refresh intervals overlap and ignore visibility | `src/main.tsx` | Use recursive `setTimeout`, pause when `document.hidden`, surface errors |
| M8 | Subscription/source handlers unguarded (loading + errors) | `src/main.tsx` | Add loading states and `try/catch` with `setStatus(message(error))` |
| M9 | Project root persisted on every keystroke | `src/main.tsx` | Debounce (e.g., 500 ms) and/or persist on blur/Enter |
| M10 | `contents: write` granted to all jobs | `.github/workflows/release.yml` | Move `contents: write` to the `release` job only |
| M11 | Build environment not reproducible | `.github/workflows/release.yml` | Pin runner images, Node patch, and Rust toolchain |
| M12 | Version source-of-truth can drift | `scripts/build-release.sh`, `release.yml` | Single source of truth + CI validation that all three files match |
| M13 | Release artifact steps silently ignore failures | `.github/workflows/release.yml` | Remove `|| true`; validate expected artifacts before publish |
| M14 | Redis/MySQL exposed on host ports with weak defaults | `sync-server/docker-compose.yml`, `.env.example` | Bind only to Docker network; strong passwords; Redis auth |
| M15 | `SESSION_ENCRYPT=false` in backend example | `sync-server/backend/.env.example` | Set `SESSION_ENCRYPT=true` in production example |

---

## Low / Info

- **L1 — Dynamic DDL in `add_column_if_missing`:** currently internal-only and safe; keep it that way.
- **L2 — DB path exposed via `get_app_status`:** consider removing unless UI needs it.
- **L3 — Sync error paths store/print raw HTTP bodies:** sanitize stored errors in release builds.
- **L4 — Broad Tauri capabilities + `style-src 'unsafe-inline'`:** restrict capabilities to the minimum set; avoid inline scripts.
- **L5 — `PRAGMA foreign_keys = ON` with no declared FKs:** either add FKs or remove the pragma.
- **L6 — `list_pricing_catalog` omits reasoning/tool prices:** include all stored pricing fields in the response.
- **L7 — Browser APIs used instead of Tauri plugins:** use native dialog/process/clipboard plugins.
- **L8 — Date/duration helpers don’t validate input:** guard invalid dates and `NaN` durations.
- **L9 — `sync-progress` payload cast without validation:** add `typeof` checks.
- **L10 — Error boundary does not wrap the full app shell:** wrap root `<App />`.
- **L11 — Large tables not virtualized:** consider `@tanstack/react-virtual` for long lists.
- **L12 — `ProjectManager` path display assumes Unix separators:** use cross-platform split.
- **L13 — Hardcoded sync/update URL:** make build-time configurable.
- **L14 — Build tools in `dependencies`:** move `typescript` and `@vitejs/plugin-react` to `devDependencies`.
- **L15 — `Tab` type collapses to `string`:** use a closed union.
- **L16 — No post-publish health check:** add a smoke test hitting the update endpoint.
- **L17 — Disabled `build-windows.yml.disabled` still in repo:** delete or archive outside `.github/workflows`.

---

## Recommended Next Steps

### Immediate (next patch / hotfix)
1. Fix **C1** — validate `open_project_path` on the backend and disable the button for non-directories on the frontend.
2. Fix **H4** — move the sync token out of SQLite (keychain).
3. Fix **H5** — protect pricing pull and project unmerge from data loss.
4. Fix **M6** — remove `TAURI_` from Vite `envPrefix`.
5. Add loading/error guards to subscriptions, source removal, and project-root save (**M8**, **M9**).

### Short-term (next 1–2 releases)
6. Move blocking DB/file/network work off the main thread (**H1**).
7. Cap file/JSON parsing sizes and depth (**H3**).
8. Restrict and validate `add_source` paths (**H2**).
9. Redact `debug_sync_state` output (**M3**).
10. Validate `server_url` and require confirmation to change it (**M4**).

### Infrastructure / process
11. Remove `--force` from release publish (**H8**) and add artifact SHA-256 + attestation (**H9**).
12. Pin CI actions to SHA, pin runners/toolchains, and version-sync validation (**H10**, **H11**, **M11**, **M12**).
13. Harden CI secrets: short-lived Tailscale OAuth, pinned host keys, no `StrictHostKeyChecking=no`, scoped `contents: write` (**H6**, **M10**).
14. Add Windows code-signing and macOS notarization (**H7**).

---

## Positive Findings

- No SQL injection: all user-facing queries use parameterized statements.
- No `unsafe` blocks or raw pointer use in Rust.
- No hard-coded API keys or passwords in source (updater public key is by design).
- React does not use dangerous `innerHTML`/`eval`; user strings render as text nodes, so XSS is not currently exploitable from the frontend alone.
- Lockfiles (`package-lock.json`, `Cargo.lock`) are tracked, giving supply-chain traceability.
