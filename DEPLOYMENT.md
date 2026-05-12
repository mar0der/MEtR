# MEtR Deployment Guide

This document explains how to build, release, and deploy new versions of the MEtR desktop app and sync server.

---

## Versioning Scheme

MEtR uses `YY.WW.CONSECUTIVE` format:
- `YY` = last two digits of year (e.g., `26` for 2026)
- `WW` = ISO week number (e.g., `20` for week 20)
- `CONSECUTIVE` = build number within that week (starts at 1)

Example: `26.20.3` = year 2026, week 20, 3rd build of that week.

The **single source of truth** for the version is `src-tauri/tauri.conf.json`. The build script syncs it to `package.json` and `Cargo.toml` automatically.

---

## Prerequisites

- macOS with Xcode Command Line Tools
- Apple Developer ID Application certificate installed
- Tauri signing private key at `~/.tauri/metr.key`
- Apple ID credentials for notarization (stored at `~/.metr-certs/apple_pass.txt`)
- SSH access to `the18th` server

---

## Building a New Release

### 1. Bump the version

Edit `src-tauri/tauri.conf.json` and change the `version` field. Use the `YY.WW.CONSECUTIVE` format.

```bash
# Example: bump to 26.20.4
sed -i '' 's/"version": "26.20.3"/"version": "26.20.4"/' src-tauri/tauri.conf.json
```

### 2. Build

```bash
./scripts/build-release.sh
```

This will:
- Read version from `tauri.conf.json`
- Sync it to `package.json` and `Cargo.toml`
- Build the frontend (Vite)
- Build the Rust binary
- Sign the `.app` bundle with your Developer ID certificate
- Create `.dmg` installer and `.tar.gz` updater archive
- Sign the updater archive

Build artifacts appear in:
- `src-tauri/target/release/bundle/macos/MEtR.app.tar.gz` — updater archive
- `src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig` — updater signature
- `src-tauri/target/release/bundle/dmg/MEtR_{VERSION}_aarch64.dmg` — fresh install DMG

### 3. Test locally (optional)

```bash
# Install the new build without going through the updater
cp -R src-tauri/target/release/bundle/macos/MEtR.app /Applications/
open /Applications/MEtR.app
```

---

## Publishing to the Update Server

### 1. Copy build artifacts to the server

```bash
scp src-tauri/target/release/bundle/macos/MEtR.app.tar.gz \
    src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig \
    src-tauri/target/release/bundle/dmg/MEtR_{VERSION}_aarch64.dmg \
    the18th:/tmp/
```

### 2. Move artifacts into the updates directory

```bash
ssh the18th "cp /tmp/MEtR.app.tar.gz /tmp/MEtR.app.tar.gz.sig /tmp/MEtR_{VERSION}_aarch64.dmg /opt/metr-sync/site/backend/storage/app/updates/"
```

### 3. Publish the release via Artisan

```bash
ssh the18th "cd /opt/metr-sync/site && docker compose exec php php artisan metr:release:publish \
    --release-version={VERSION} \
    --notes='Release notes here' \
    --darwin-tgz=/var/www/html/storage/app/updates/MEtR.app.tar.gz \
    --darwin-sig=/var/www/html/storage/app/updates/MEtR.app.tar.gz.sig \
    --darwin-dmg=/var/www/html/storage/app/updates/MEtR_{VERSION}_aarch64.dmg"
```

> **IMPORTANT:** Verify the files are non-zero bytes after publishing! The Laravel `Storage::putFileAs()` can occasionally create empty files when source and destination overlap.
>
> ```bash
> ssh the18th "ls -la /opt/metr-sync/site/backend/storage/app/updates/MEtR.app.tar.gz"
> ```

### 4. Verify the update endpoint

```bash
curl -s https://metr.petarpetkov.com/api/v1/update/darwin/aarch64/{OLDER_VERSION}
```

Should return JSON with `version`, `url`, `signature` when an update is available, or **HTTP 204 No Content** when on latest.

---

## Backend Deployment

If you changed backend code (PHP, routes, views):

```bash
# Copy changed files to server
rsync -av --exclude='.env' --exclude='storage/logs/' --exclude='storage/framework/cache/' \
    sync-server/backend/ the18th:/opt/metr-sync/site/backend/

# Clear route cache and restart
ssh the18th "cd /opt/metr-sync/site && docker compose exec php php artisan route:cache"
```

---

## Public Download Page

The download page is at `https://metr.petarpetkov.com/download`. It automatically shows the latest release and download links for:
- **macOS Apple Silicon** — DMG installer
- **Windows** — MSI installer

The page reads from the `update_releases` and `update_assets` tables, so it stays in sync with the updater automatically.

---

## Troubleshooting

### "the `url` field was not set on the updater response"

The server returned JSON without `url` and `signature`. The Tauri v2 updater expects:
- **HTTP 204 No Content** for "no update" (our current behavior)
- **Full manifest** with `version`, `url`, `signature` for available updates

It does **not** support `{"available": false}`.

### Infinite update loop after restart

1. Check that the `.tar.gz` on the server is not 0 bytes
2. Check the app console logs (right-click → Inspect Element → Console)
3. The updater cooldown (1 hour) and skip-version features should prevent most loops

### Notarization skipped

Set the environment variables before building:

```bash
export APPLE_ID="your-apple-id@email.com"
export APPLE_PASSWORD="$(cat ~/.metr-certs/apple_pass.txt)"
export APPLE_TEAM_ID="TG94VNPLAA"
./scripts/build-release.sh
```

---

## File Reference

| File | Purpose |
|------|---------|
| `src-tauri/tauri.conf.json` | **Version source of truth**, updater endpoint config |
| `src-tauri/Cargo.toml` | Rust package version (auto-synced by build script) |
| `package.json` | NPM version (auto-synced by build script) |
| `scripts/build-release.sh` | Build script with signing and versioning |
| `src/updater.ts` | Frontend update check logic (cooldown, skip version, restart detection) |
| `sync-server/backend/app/Http/Controllers/Api/V1/UpdateController.php` | Server update endpoint (returns 204 or full manifest) |
| `sync-server/backend/app/Console/Commands/PublishUpdateRelease.php` | Artisan command to publish releases |
