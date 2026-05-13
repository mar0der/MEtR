# MEtR — Agent Guidelines

> **Last updated:** 2026-05-13  
> **Applies to:** `/Users/petarpetkov/Developer/MEtR` and all subdirectories  
> **Purpose:** Project conventions, deployment rules, and agent behavior guidelines for the MEtR codebase.

---

## Table of Contents

1. [Agent Philosophy & Clarification Rules](#1-agent-philosophy--clarification-rules)
2. [Project Overview](#2-project-overview)
3. [Technology Stack](#3-technology-stack)
4. [Project Structure](#4-project-structure)
5. [Build Commands](#5-build-commands)
6. [Version Numbering Convention](#6-version-numbering-convention)
7. [Deployment Workflow](#7-deployment-workflow)
8. [Database & Migrations](#8-database--migrations)
9. [Parser & Token Logic](#9-parser--token-logic)
10. [Code Style & Conventions](#10-code-style--conventions)
11. [Testing](#11-testing)
12. [Security Considerations](#12-security-considerations)
13. [Server Details](#13-server-details)
14. [Apple Developer](#14-apple-developer)
15. [Known Pitfalls & Lessons Learned](#15-known-pitfalls--lessons-learned)

---

## 1. Agent Philosophy & Clarification Rules

### 1.1 Core Principle

**If uncertainty could realistically break production, violate architecture, introduce hidden regressions, or remove important behavior — ASK FIRST instead of assuming.**

The goal is NOT maximum autonomy at all costs. The goal IS safe long-term architectural evolution. A small clarification is preferable to hidden architectural drift, accidental regressions, broken deployments, or irreversible refactors.

### 1.2 The Agent MUST Ask Questions When

| Situation | Why |
|-----------|-----|
| **Multiple valid interpretations exist** | e.g., two different architectural patterns in the repo — ask which is preferred or legacy |
| **Project rules are missing** | e.g., coding standards, deployment rules, subsystem boundaries, testing requirements are undocumented |
| **The change could break production** | e.g., deployment changes, auth changes, billing changes, DB schema changes, infrastructure rewrites, API contract changes |
| **Legacy code appears strange** | Strange code often exists because of hidden integrations, production edge cases, historical bugs, vendor limitations, or deployment constraints. Do NOT assume it is incorrect. |
| **Contradictions detected** | e.g., docs say one thing, implementation says another, tests imply different behavior |

### 1.3 Anti-Hallucination Rule

The agent MUST NOT invent:
- Undocumented architecture
- Fake deployment assumptions
- Nonexistent business rules
- Inferred API guarantees
- Guessed infrastructure behavior

Unknown information should remain **explicitly unknown** until clarified.

### 1.4 Safe Default Behavior

If clarification is unavailable, the agent SHOULD:
1. Preserve existing architecture
2. Minimize changes
3. Avoid broad refactors
4. Avoid deleting unclear code
5. Document uncertainty explicitly in `AGENTS.md` or `/docs/pitfalls.md`

### 1.5 Preferred Question Style

- Ask concise, targeted questions
- Explain why clarification is needed
- Provide possible interpretations
- Suggest the safest default

Example:
> I found two different database access patterns: (1) Repository pattern and (2) Direct query builder access. Which should be considered canonical for new code?

### 1.6 Long-Term Memory Rule

If the user clarifies architectural rules, deployment assumptions, preferred patterns, or dangerous constraints, the agent MUST document them in:
- `AGENTS.md` (this file)
- `/docs/current-state.md` (if it exists; create if needed)
- `/docs/pitfalls.md` (if it exists; create if needed)
- Relevant subsystem docs

This reduces future ambiguity after context resets.

---

## 2. Project Overview

MEtR is a local-first desktop application that tracks LLM token usage from local log/history files and compares subscription spend against API-equivalent pricing. It is built as a **Tauri 2** app (Rust backend + React/TypeScript frontend) and bundles its own SQLite database. An optional cloud sync feature uploads anonymized usage events to a **Laravel** backend at `https://metr.petarpetkov.com`.

The app targets **macOS** (Apple Silicon, primary dev platform) and **Windows** (x64, built via GitHub Actions). All parsed data stays in a local SQLite database; raw log files are never uploaded.

---

## 3. Technology Stack

| Layer | Technology |
|-------|-----------|
| Desktop framework | Tauri 2 |
| Frontend | React 19 + TypeScript |
| Build tool | Vite 8 |
| Backend language | Rust (edition 2021) |
| Local database | SQLite via `rusqlite` (bundled feature) |
| HTTP client | `reqwest` (blocking) |
| Sync server | Laravel (PHP) + Docker + nginx |
| Tests (backend) | PHPUnit |

Key crates: `tauri`, `rusqlite`, `chrono`, `serde`, `walkdir`, `reqwest`, `uuid`, `sha2`, `md5`.  
Key npm packages: `@tauri-apps/api`, `@tauri-apps/plugin-updater`, `@tauri-apps/plugin-dialog`, `lucide-react`, `clsx`.

---

## 4. Project Structure

```
├── src/                      # Frontend (React/TS)
│   ├── main.tsx              # Entire UI — single 1300-line component
│   ├── updater.ts            # Auto-update logic (cooldowns, skip versions, restart)
│   ├── styles.css            # All app styles (no Tailwind — hand-written CSS)
│   └── vite-env.d.ts
├── src-tauri/                # Rust Tauri app
│   ├── src/
│   │   ├── main.rs           # Entry point (calls metr_lib::run)
│   │   └── lib.rs            # ALL backend logic — 3100+ lines
│   ├── Cargo.toml
│   ├── tauri.conf.json       # Tauri config (window, security, updater, bundle)
│   └── capabilities/
├── sync-server/              # Laravel backend + Docker deployment
│   ├── backend/              # Laravel application
│   ├── nginx/
│   ├── docker-compose.yml
│   └── scripts/
├── fixtures/                 # Synthetic parser test data
│   ├── claude/
│   ├── codex/
│   ├── generic-jsonl/
│   └── malformed/
├── docs/
│   ├── product_specs.md      # Full product specification (1620 lines)
│   └── sync_backend_laravel_implementation_and_deployment.md
├── scripts/
│   └── build-release.sh      # macOS release build + version sync
└── .github/workflows/
    └── build-windows.yml     # Windows CI build
```

**Important:** The codebase is intentionally concentrated in two files:
- `src/main.tsx` — all React components, hooks, types, and UI logic.
- `src-tauri/src/lib.rs` — all Tauri commands, DB schema/migrations, parsing, pricing, sync, and queries.

---

## 5. Build Commands

```bash
# Install dependencies
npm install

# Frontend-only build
npm run build

# Run desktop app locally (dev mode)
npm run tauri:dev

# Build release installers (macOS)
npm run tauri:build
```

**Windows build** is handled by `.github/workflows/build-windows.yml` on push to `main`. It requires `TAURI_SIGNING_PRIVATE_KEY` and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` GitHub secrets.

---

## 6. Version Numbering Convention

**Format: `YY.WW.PATCH`**

- `YY` = last two digits of the year (e.g., `26` for 2026)
- `WW` = **ISO week number** (NOT a sequential feature counter)
- `PATCH` = increment within the same week

**Rule:** The middle number is the week number. Do NOT increment it unless the actual calendar week has changed.

Version must be kept in sync across three files:
- `package.json`
- `src-tauri/Cargo.toml`
- `src-tauri/tauri.conf.json`

The helper script `scripts/build-release.sh` syncs the version from `tauri.conf.json` to the other two files automatically.

---

## 7. Deployment Workflow

### 7.1 No Local Testing Environment

There is **no local dev environment** for running the desktop app during development. Builds are deployed straight to the update server and tested from there.

**DO NOT** try to:
- Run `cargo tauri dev` for local testing
- Start the app locally to "verify" changes
- Set up a local test loop

**DO** build the release bundle, sign it, upload to server, and test via the actual update mechanism.

### 7.2 Build & Deploy Steps

1. **Bump version** in:
   - `src-tauri/Cargo.toml`
   - `package.json`
   - `src-tauri/tauri.conf.json`

2. **Build frontend:** `npm run build`

3. **Build Tauri release bundle:** `npx tauri bundle --bundles dmg,app`

4. **Manually sign the updater archive** (auto-signing fails with "Device not configured"):
   ```bash
   # Decode the secret key (stored as base64 in ~/.tauri/metr.key)
   cat ~/.tauri/metr.key | base64 -d > /tmp/metr.key

   # Decode the public key from tauri.conf.json
   echo "PUBKEY_FROM_TAURI_CONF" | base64 -d > /tmp/metr.pubkey

   # Sign the updater archive
   rsign sign -p /tmp/metr.pubkey -s /tmp/metr.key -W \
     -t "MEtR vVERSION" -c "Update archive for MEtR" \
     src-tauri/target/release/bundle/macos/MEtR.app.tar.gz

   # Rename .minisig to .sig
   mv src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.minisig \
      src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig
   ```

5. **Push to GitHub** to trigger the Windows build workflow (`.github/workflows/build-windows.yml`)

6. **Wait for Windows build** to complete on GitHub Actions

7. **Download Windows artifacts:**
   ```bash
   gh run download RUN_ID --name windows-msi --dir /tmp/windows-msi
   gh run download RUN_ID --name windows-sig --dir /tmp/windows-sig
   ```

8. **Upload artifacts to the server:**
   
   Only `.dmg` and `.msi` (human installers) go to `/storage/releases/`. The `.tar.gz` updater archives go **straight to Laravel storage** — do NOT leave them in the public releases directory.
   
   ```bash
   # Human installers → public releases dir
   scp src-tauri/target/release/bundle/dmg/MEtR_VERSION_aarch64.dmg \
       root@the18th:/opt/metr-sync/site/storage/releases/
   scp /tmp/windows-msi/MEtR_VERSION_x64_en-US.msi \
       root@the18th:/opt/metr-sync/site/storage/releases/
   
   # Updater archives → Laravel storage directly
   scp src-tauri/target/release/bundle/macos/MEtR.app.tar.gz \
       root@the18th:/opt/metr-sync/site/backend/storage/app/updates/MEtR_VERSION_aarch64.app.tar.gz
   scp src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig \
       root@the18th:/opt/metr-sync/site/backend/storage/app/updates/MEtR_VERSION_aarch64.app.tar.gz.sig
   scp /tmp/windows-sig/MEtR_VERSION_x64_en-US.msi.sig \
       root@the18th:/opt/metr-sync/site/backend/storage/app/updates/MEtR_VERSION_x64_en-US.msi.sig
   ```

9. **Copy installer to backend storage and publish:**
   ```bash
   ssh root@the18th "cp /opt/metr-sync/site/storage/releases/MEtR_VERSION_aarch64.dmg \
       /opt/metr-sync/site/backend/storage/app/updates/ && \
    cp /opt/metr-sync/site/storage/releases/MEtR_VERSION_x64_en-US.msi \
       /opt/metr-sync/site/backend/storage/app/updates/"

   ssh root@the18th "docker exec metr-sync-php php artisan metr:release:publish \
       --release-version=VERSION \
       --notes='Release notes' \
       --darwin-tgz=/var/www/html/storage/app/updates/MEtR_VERSION_aarch64.app.tar.gz \
       --darwin-sig=/var/www/html/storage/app/updates/MEtR_VERSION_aarch64.app.tar.gz.sig \
       --darwin-dmg=/var/www/html/storage/app/updates/MEtR_VERSION_aarch64.dmg \
       --windows-msi=/var/www/html/storage/app/updates/MEtR_VERSION_x64_en-US.msi \
       --windows-sig=/var/www/html/storage/app/updates/MEtR_VERSION_x64_en-US.msi.sig \
       --force"
   ```

10. **Verify update endpoint:**
    ```bash
    curl -s "https://metr.petarpetkov.com/api/v1/update/darwin/aarch64/PREVIOUS_VERSION"
    curl -s "https://metr.petarpetkov.com/api/v1/update/windows/x86_64/PREVIOUS_VERSION"
    ```

---

## 8. Database & Migrations

- **Local DB path:** `~/Library/Application Support/com.metr.local/metr.db` (macOS); `%APPDATA%\MEtR\metr.db` (Windows)
- **Lock behavior:** The app holds an SQLite lock while running. Close the app before running external SQL queries.
- **Schema migrations:** Handled in `migrate()` in `src-tauri/src/lib.rs`. Uses `CREATE TABLE IF NOT EXISTS` plus `add_column_if_missing()` for additive migrations.
- **Default providers seeded** on first run via `seed_defaults()`.

### Key Tables

- `providers` — known LLM providers (openai, anthropic, cursor, google, cline, continue, aider, kimi, ollama, lmstudio, generic)
- `log_sources` — configured source folders with parser assignment
- `indexed_files` — tracks scanned files (size, mtime, parser version) for incremental scanning
- `usage_events` — normalized token usage events with de-dupe SHA256 IDs
- `projects` — detected project roots
- `conversations` — chat/session groupings
- `pricing_catalogs` — model pricing per provider (USD per 1M tokens)
- `subscriptions` — user subscription costs and billing anchors
- `sync_config` — singleton row (id = 1) for server URL, auth token, device info

---

## 9. Parser & Token Logic

### Parser Version

`PARSER_VERSION = "0.1.6"` in `src-tauri/src/lib.rs`. Bumping this triggers a full re-parse on next scan because `indexed_file_is_current()` checks parser version.

### Supported Providers

| Provider ID | Display Name | Default Parser | Detection Paths |
|-------------|-------------|----------------|-----------------|
| `openai` | OpenAI / Codex | `codex` | `~/.codex` |
| `anthropic` | Claude | `claude` | `~/.claude` |
| `cursor` | Cursor | `generic_jsonl` | `~/Library/Application Support/Cursor` |
| `google` | Gemini | `gemini` | `~/.gemini` |
| `cline` | Cline / Roo Code | `generic_jsonl` | `~/Library/Application Support/Code/User/globalStorage` |
| `continue` | Continue | `continue` | `~/.continue` |
| `kimi` | Kimi / Moonshot | `generic_jsonl` | `~/.kimi`, `~/.moonshot`, `~/Library/Application Support/Kimi` |
| `ollama` | Ollama | `generic_jsonl` | `~/.ollama` |
| `lmstudio` | LM Studio | `generic_jsonl` | `~/.lmstudio` |
| `generic` | Generic JSONL | `generic_jsonl` | manual only |

### Token Counting Semantics

Different providers report input tokens differently:
- **OpenAI/Codex:** `input_tokens` includes cached → cost calculation subtracts cached from input
- **Anthropic:** `input_tokens` is uncached only → display as-is, no subtraction
- **Kimi:** `input_other` is uncached only → display as-is

The frontend displays `input_tokens - cached_input_tokens` as "Input" and `cached_input + cache_write + cache_read` as "Cached".

### Kimi Project Detection

Kimi stores sessions at `~/.kimi/sessions/<md5(workdir)>/<conv>/wire.jsonl`. The parser reads `~/.kimi/kimi.json` `work_dirs` to map session MD5 hashes to real project paths. **Do NOT** use text scraping from message content for project detection.

---

## 10. Code Style & Conventions

### Tauri Invoke Naming

Top-level arguments passed to `#[tauri::command]` functions use Tauri's default **camelCase** conversion unless the Rust command explicitly sets `#[tauri::command(rename_all = "snake_case")]`:
- ✅ `providerId: provider || null` for Rust parameter `provider_id`
- ❌ `provider_id: provider || null` silently deserializes as `None` for `Option<String>` direct parameters

Nested struct payloads still use Serde's field names unless the struct has its own rename attributes, so payloads like `{ input: { provider_id: "openai" } }` are valid for `Deserialize` structs with `provider_id` fields.

### React Patterns

- **Stale closure prevention:** `useEffect(() => { setInterval(refresh, 30000) }, [])` captures the **initial** `refresh` function. The app uses React refs (`activeTabRef`, `sessionPageRef`) for state read inside intervals.
- **Deferred initial load:** Data fetching is deferred with `setTimeout(..., 100)` so the UI renders first and avoids a white screen on slow DB ops.

### Rust Patterns

- All DB access goes through `AppState { db: Mutex<Connection> }`.
- Errors are converted to strings with `to_string()` (a generic `|e| e.to_string()` closure).
- Timestamps are stored as RFC 3339 strings via `Utc::now().to_rfc3339()`.
- SHA256 hashes are used for IDs (events, projects, conversations, indexed files).

---

## 11. Testing

### Frontend / Desktop
There is **no automated test suite** for the frontend or Rust desktop layer. Testing is done manually by:
1. Adding `fixtures/` folders as sources in Settings
2. Running Scan/Rescan to verify parser output
3. Checking dashboard metrics and session tables

### Backend (Laravel)
The sync-server backend has PHPUnit tests:
```bash
cd sync-server/backend
php artisan test
```

Test files:
- `tests/Feature/AuthTest.php`
- `tests/Feature/SyncTest.php`
- `tests/Feature/DashboardTest.php`
- `tests/Feature/DeviceTest.php`
- `tests/Feature/AttributionRuleTest.php`
- `tests/Feature/ProjectMergeTest.php`
- `tests/Unit/NormalizeProjectRootTest.php`
- `tests/Unit/ResolveModelPriceTest.php`

---

## 12. Security Considerations

- **Local-first:** Raw log files never leave the device. Only parsed, anonymized usage events are optionally synced.
- **CSP:** Configured in `tauri.conf.json` with restrictive policy. `connect-src` is limited to `'self'` and `https://metr.petarpetkov.com`.
- **Signing:** macOS app is signed with `Developer ID Application: Petar Petkov (TG94VNPLAA)`. Windows MSI is signed in CI via Tauri's updater signing key.
- **Updater signatures:**
  - **macOS `.sig`:** Raw minisign multi-line text → must be `base64_encode()`'d before storing in DB
  - **Windows `.sig`:** Already base64 single-line from GitHub Actions → store as-is
  - The `PublishUpdateRelease.php` `normalizeSignature()` method handles this automatically.
- **Auth tokens:** Stored in local SQLite (`sync_config.auth_token`). Sent as `Authorization: Bearer <token>` to the sync server.

---

## 13. Server Details

- **Server:** `the18th` (SSH as `root`)
- **Deploy path:** `/opt/metr-sync/site`
- **Backend:** `/opt/metr-sync/site/backend` (mounted at `/var/www/html` in Docker container `metr-sync-php`)
- **Releases storage:** `/opt/metr-sync/site/backend/storage/app/updates`
- **Public releases URL:** `https://metr.petarpetkov.com/updates/`
- **Update API:** `/api/v1/update/{target}/{arch}/{current_version}`

---

## 14. Apple Developer

- **Developer:** Petar Petkov
- **Team ID:** `TG94VNPLAA`
- **Signing identity:** `Developer ID Application: Petar Petkov (TG94VNPLAA)`
- **Private key:** `~/.tauri/metr.key` (base64-encoded minisign secret key)
- **Notarization:** Skipped (no `APPLE_ID`/`APPLE_PASSWORD` env vars set)

---

## 15. Known Pitfalls & Lessons Learned

### Stale Closures in React
`useEffect(() => { setInterval(refresh, 30000) }, [])` captures the initial `refresh` function. State must be read via refs (`activeTabRef`, `sessionPageRef`) inside the interval callback.

### Stale Session Responses Across Provider Tabs
Provider-tab session requests can race with older unfiltered `All` refresh requests. Guard paginated session responses by request id before updating UI state; otherwise a late `All` response can repaint Claude/OpenAI tabs with Kimi-heavy global results.

### Tauri camelCase Silent Failure
Tauri converts direct `#[tauri::command]` parameters to **camelCase** by default. Sending `provider_id` from the frontend to a Rust parameter named `provider_id: Option<String>` silently deserializes as `None` because Tauri expects the key `providerId`. Always match the frontend key to Tauri’s camelCase expectation, or explicitly add `#[tauri::command(rename_all = "snake_case")]` to the handler.

### `putFileAs` Self-Destruct Bug
Laravel's `Storage::putFileAs` opens the destination in write mode (truncating) before reading the source. If paths are identical, the file becomes 0 bytes. **Guard:** compare `realpath($source)` with `$disk->path($filename)` before calling `putFileAs`.

### Tauri Signing on macOS
`rsign` fails with "Device not configured" when trying to prompt for a password on macOS. The workaround is to decode the base64 key files and use `expect` or set `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` as an env var before building.

### Token Semantics: OpenAI vs Anthropic
OpenAI/Codex reports `input_tokens` that **includes** cached tokens. Effective non-cached input = `input_tokens - cached_input_tokens`. Anthropic reports `input_tokens` as **only** uncached tokens. The cost calculation and display logic must handle both.

### Version Convention Trap
The middle digit is the **ISO week number**, not a sequential feature counter. Bumping it incorrectly (e.g., `26.21.0` when it's week 20) breaks consistency. Always check the actual calendar week.

### DB Path on macOS
The local DB is at `~/Library/Application Support/com.metr.local/metr.db`. The app must be closed before running external SQLite queries because it holds a file lock.

### Server Cost Double-Counting
The server's `CalculateUsageCost` must subtract `cached_input` from `input` for OpenAI-style providers (where input includes cached). Otherwise cached tokens are charged at both the full input rate and the cached rate. The desktop app already handles this correctly; the server must match.

### macOS Startup Hang
`recalculate_event_costs` iterates through ALL usage_events on startup. For large databases (8000+ events), this blocks the main thread and causes a white screen. **Fix:** run expensive maintenance in a background thread so the UI loads first.

---

*If you discover a new pitfall, architectural rule, or deployment constraint, add it to this file immediately.*
