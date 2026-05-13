# MEtR — Agent Guidelines

## Project Overview

MEtR is a Tauri desktop app (Rust + React/TypeScript) that tracks local LLM usage, subscriptions, and API-equivalent costs. It syncs to a Laravel backend at `https://metr.petarpetkov.com`.

## Version Numbering Convention

**Format: `YY.WW.PATCH`**

- `YY` = last two digits of the year (e.g., `26` for 2026)
- `WW` = **ISO week number** (NOT a sequential feature counter)
- `PATCH` = increment within the same week

**Examples:**
- `26.20.10` → week 20, patch 10
- `26.20.11` → same week 20, patch 11
- `26.21.0`  → week 21, patch 0 (only when the calendar week actually changes)

**Rule:** The middle number is the week number. Do NOT increment it unless the actual calendar week has changed.

## Deployment Workflow

### No Local Testing Environment

There is **no local dev environment** for running the desktop app during development. Builds are deployed straight to the update server and tested from there.

**DO NOT** try to:
- Run `cargo tauri dev` for local testing
- Start the app locally to "verify" changes
- Set up a local test loop

**DO** build the release bundle, sign it, upload to server, and test via the actual update mechanism.

### Build & Deploy Steps

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

8. **Upload all artifacts to the server:**
   ```bash
   scp src-tauri/target/release/bundle/dmg/MEtR_VERSION_aarch64.dmg \
       root@the18th:/opt/metr-sync/site/storage/releases/
   scp src-tauri/target/release/bundle/macos/MEtR.app.tar.gz \
       root@the18th:/opt/metr-sync/site/storage/releases/MEtR_VERSION_aarch64.app.tar.gz
   scp src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig \
       root@the18th:/opt/metr-sync/site/storage/releases/MEtR_VERSION_aarch64.app.tar.gz.sig
   scp /tmp/windows-msi/MEtR_VERSION_x64_en-US.msi \
       root@the18th:/opt/metr-sync/site/storage/releases/
   scp /tmp/windows-sig/MEtR_VERSION_x64_en-US.msi.sig \
       root@the18th:/opt/metr-sync/site/storage/releases/
   ```

9. **Copy to backend storage and publish:**
   ```bash
   ssh root@the18th "cp /opt/metr-sync/site/storage/releases/MEtR_VERSION* \
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

## Key Technical Details

### Tauri Invoke Naming

Arguments passed to Tauri commands must match Rust **snake_case** exactly:
- ✅ `provider_id: provider || null`
- ❌ `providerId: provider || null`

### Database

- **Local DB path:** `~/Library/Application Support/com.metr.local/metr.db`
- **Lock behavior:** The app holds an SQLite lock while running. Close the app before running external SQL queries.
- **Schema migrations:** Handled in `migrate()` in `src-tauri/src/lib.rs`

### Parser Version

`PARSER_VERSION = "0.1.6"` in `src-tauri/src/lib.rs`. Bumping this triggers a full re-parse on next scan because `indexed_file_is_current()` checks parser version.

### Signature Format Rule

- **macOS `.sig`**: Raw minisign multi-line text → must be `base64_encode()`'d before storing in DB
- **Windows `.sig`**: Already base64 single-line from GitHub Actions → store as-is

The `PublishUpdateRelease.php` `normalizeSignature()` method handles this automatically.

### Kimi Project Detection

Kimi stores sessions at `~/.kimi/sessions/<md5(workdir)>/<conv>/wire.jsonl`. The parser reads `~/.kimi/kimi.json` `work_dirs` to map session MD5 hashes to real project paths. **Do NOT** use text scraping from message content for project detection.

### Token Counting Semantics

Different providers report input tokens differently:
- **OpenAI/Codex:** `input_tokens` includes cached → display as `input - cached_input`
- **Anthropic:** `input_tokens` is uncached only → display as-is
- **Kimi:** `input_other` is uncached only → display as-is

The frontend should always display `input_tokens - cached_input_tokens` as "Input" and `cached_input + cache_write + cache_read` as "Cached".

### Stale Closure Lesson

`useEffect(() => { setInterval(refresh, 30000) }, [])` captures the **initial** `refresh` function. If `refresh` reads state from closures (not refs), it uses stale values forever. Use React refs (`activeTabRef`, `sessionPageRef`) for state read inside intervals.

## Server Details

- **Server:** `the18th` (SSH as `root`)
- **Deploy path:** `/opt/metr-sync/site`
- **Backend:** `/opt/metr-sync/site/backend` (mounted at `/var/www/html` in Docker container `metr-sync-php`)
- **Releases storage:** `/opt/metr-sync/site/backend/storage/app/updates`
- **Public releases URL:** `https://metr.petarpetkov.com/updates/`
- **Update API:** `/api/v1/update/{target}/{arch}/{current_version}`

## Apple Developer

- **Developer:** Petar Petkov
- **Team ID:** `TG94VNPLAA`
- **Signing identity:** `Developer ID Application: Petar Petkov (TG94VNPLAA)`
- **Private key:** `~/.tauri/metr.key` (base64-encoded minisign secret key)
- **Notarization:** Skipped (no `APPLE_ID`/`APPLE_PASSWORD` env vars set)
