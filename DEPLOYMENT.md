# MEtR Deployment Guide

This document explains how to build, release, and deploy new versions of the MEtR desktop app and sync server. **Follow these steps exactly** — skipping any step causes failed updates.

---

## Versioning Scheme

MEtR uses `YY.WW.CONSECUTIVE` format:
- `YY` = last two digits of year (e.g., `26` for 2026)
- `WW` = ISO week number (e.g., `20` for week 20)
- `CONSECUTIVE` = build number within that week (starts at 1)

Example: `26.20.6` = year 2026, week 20, 6th build of that week.

---

## Prerequisites

- macOS with Xcode Command Line Tools
- Apple Developer ID Application certificate installed
- Tauri signing private key at `~/.tauri/metr.key` (base64-encoded minisign secret key)
- `rsign` installed: `cargo install rsign2`
- GitHub CLI (`gh`) authenticated
- SSH access to `the18th` server (`~/.ssh/id_the18th_root`)
- Docker running on `the18th` with `metr-sync-php` container

---

## Step 1: Bump Version in ALL Three Files

**Do not skip any file.** The versions must match exactly across all three.

```bash
NEW_VERSION="26.20.6"

# 1. tauri.conf.json (source of truth for the updater)
sed -i '' "s/\"version\": \"[0-9]\+\.[0-9]\+\.[0-9]\+\"/\"version\": \"$NEW_VERSION\"/" src-tauri/tauri.conf.json

# 2. package.json
sed -i '' "s/\"version\": \"[0-9]\+\.[0-9]\+\.[0-9]\+\"/\"version\": \"$NEW_VERSION\"/" package.json

# 3. Cargo.toml
sed -i '' "s/^version = \"[0-9]\+\.[0-9]\+\.[0-9]\+\"/version = \"$NEW_VERSION\"/" src-tauri/Cargo.toml

# Verify
grep '"version"' src-tauri/tauri.conf.json package.json
grep '^version' src-tauri/Cargo.toml
```

Also bump `PARSER_VERSION` in `src-tauri/src/lib.rs` if parser logic changed:
```bash
# Only if you changed parsing logic
sed -i '' 's/"0.1.4"/"0.1.5"/' src-tauri/src/lib.rs
```

---

## Step 2: Build macOS Release

The Tauri CLI cannot decrypt the signing key due to a macOS keychain/ring issue, so **updater signing will fail during build**. This is expected. We sign manually afterward.

```bash
cd /Users/petarpetkov/Developer/MEtR
npm run tauri build
```

Build artifacts appear in:
- `src-tauri/target/release/bundle/macos/MEtR.app.tar.gz` — updater archive
- `src-tauri/target/release/bundle/dmg/MEtR_{VERSION}_aarch64.dmg` — fresh install DMG

The build will print:
```
failed to decode secret key: incorrect updater private key password: Device not configured (os error 6)
```
**This is expected.** The `.sig` file was NOT created. We create it in Step 3.

---

## Step 3: Sign macOS Updater Archive Manually

The key file at `~/.tauri/metr.key` is base64-encoded. Decode it first, then sign with `rsign`:

```bash
# Decode the minisign secret key
echo "$(cat ~/.tauri/metr.key)" | base64 -d > /tmp/metr_minisign.key

# Sign the updater archive
rsign sign \
  --secret-key-file /tmp/metr_minisign.key \
  --sig-file src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig \
  --passwordless \
  --untrusted-comment "signature from tauri secret key" \
  --trusted-comment "timestamp:$(date +%s)\tfile:MEtR.app.tar.gz" \
  src-tauri/target/release/bundle/macos/MEtR.app.tar.gz
```

Verify the signature file exists and is ~300 bytes:
```bash
ls -la src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig
```

---

## Step 4: Trigger Windows GitHub Actions Build

```bash
cd /Users/petarpetkov/Developer/MEtR
git add .
git commit -m "v$NEW_VERSION: <description of changes>"
git push origin main

# Trigger the workflow
gh workflow run "Build Windows Release" --repo mar0der/MEtR
```

Wait for completion (takes ~5-10 minutes):
```bash
gh run list --repo mar0der/MEtR --workflow="Build Windows Release" --limit 1
```

---

## Step 5: Download Windows Artifacts

```bash
# Get the latest run ID
RUN_ID=$(gh run list --repo mar0der/MEtR --workflow="Build Windows Release" --limit 1 --json databaseId -q '.[0].databaseId')

# Download both artifacts
cd /tmp
gh run download $RUN_ID --repo mar0der/MEtR --name windows-msi
gh run download $RUN_ID --repo mar0der/MEtR --name windows-sig

# Verify files
ls -la /tmp/MEtR_${NEW_VERSION}_x64_en-US.msi /tmp/MEtR_${NEW_VERSION}_x64_en-US.msi.sig
```

**CRITICAL:** Windows `.sig` files from GitHub Actions are **already base64-encoded** (single line). macOS `.sig` files are **raw minisign text** (multi-line). The server handles this automatically, but you must verify both files exist.

---

## Step 6: Upload Artifacts to Server

```bash
scp -i ~/.ssh/id_the18th_root \
  src-tauri/target/release/bundle/macos/MEtR.app.tar.gz \
  src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig \
  src-tauri/target/release/bundle/dmg/MEtR_${NEW_VERSION}_aarch64.dmg \
  /tmp/MEtR_${NEW_VERSION}_x64_en-US.msi \
  /tmp/MEtR_${NEW_VERSION}_x64_en-US.msi.sig \
  root@the18th:/opt/metr-sync/site/backend/storage/app/
```

---

## Step 7: Publish Release on Server

```bash
ssh -i ~/.ssh/id_the18th_root root@the18th "docker exec metr-sync-php php artisan metr:release:publish \
  --release-version=$NEW_VERSION \
  --darwin-tgz=/var/www/html/storage/app/MEtR.app.tar.gz \
  --darwin-sig=/var/www/html/storage/app/MEtR.app.tar.gz.sig \
  --darwin-dmg=/var/www/html/storage/app/MEtR_${NEW_VERSION}_aarch64.dmg \
  --windows-msi=/var/www/html/storage/app/MEtR_${NEW_VERSION}_x64_en-US.msi \
  --windows-sig=/var/www/html/storage/app/MEtR_${NEW_VERSION}_x64_en-US.msi.sig \
  --force"
```

Expected output:
```
Uploaded macOS updater archive: MEtR.app.tar.gz
Uploaded macOS installer DMG: MEtR_{VERSION}_aarch64.dmg
Uploaded Windows artifact: MEtR_{VERSION}_x64_en-US.msi
Release {VERSION} published successfully.
```

---

## Step 8: Verify BOTH Update Endpoints

**Never skip verification.** Check both macOS and Windows endpoints return valid JSON with properly base64-encoded signatures.

### macOS endpoint
```bash
curl -s https://metr.petarpetkov.com/api/v1/update/darwin/aarch64/{OLDER_VERSION} | python3 -c "
import sys, json, base64
d = json.load(sys.stdin)
sig = d['signature']
decoded = base64.b64decode(sig).decode('utf-8')
assert 'untrusted comment' in decoded, 'macOS signature malformed!'
print('macOS OK — signature decodes to valid minisign text')
"
```

### Windows endpoint
```bash
curl -s https://metr.petarpetkov.com/api/v1/update/windows/x86_64/{OLDER_VERSION} | python3 -c "
import sys, json, base64
d = json.load(sys.stdin)
sig = d['signature']
decoded = base64.b64decode(sig).decode('utf-8')
assert 'untrusted comment' in decoded, 'Windows signature malformed!'
print('Windows OK — signature decodes to valid minisign text')
"
```

Both should print `OK` and return **HTTP 200**. If either returns HTTP 204, the asset is missing — re-run Step 7.

---

## Step 9: Test Actual Update (Manual QA)

1. Open an older version of MEtR on macOS → Settings → Check for Updates → should find and install new version
2. Open an older version of MEtR on Windows → Settings → Check for Updates → should find and install new version
3. **If you see "Invalid symbol 32, offset 9" or "invalid encoding"** — the signature is not properly base64-encoded. Go back to Step 7.

---

## What Changed in the Server (Why Old Process Broke)

### Signature Format Hell

Tauri's updater plugin (`tauri-plugin-updater`) expects the `signature` field in the JSON response to be **base64-encoded**. It base64-decodes it internally before verifying with minisign.

However, the **source `.sig` files are in two different formats**:

| Platform | `.sig` file format | Server handling |
|----------|-------------------|-----------------|
| macOS | Raw minisign text (multi-line with comments) | `base64_encode()` the raw text |
| Windows | Already base64-encoded (single line from GitHub Actions) | Use as-is |

The server command `PublishUpdateRelease.php` has a `normalizeSignature()` helper that auto-detects which format it received and handles it correctly. **Do not modify this logic unless you fully understand the Tauri updater's expectations.**

### Historical Failures

| Version | What Broke | Root Cause |
|---------|-----------|------------|
| 26.20.5 | macOS update failed with "Invalid symbol 32, offset 9" | Server stored raw `.sig` text instead of base64-encoding it |
| 26.20.6 | Windows update failed with "invalid encoding in missing data" | Server double-base64-encoded Windows `.sig` (was already base64) |
| 26.20.6 | macOS still worked | macOS `.sig` was raw text, so single base64_encode was correct |

---

## Backend Deployment (If PHP Changed)

If you changed backend code (PHP, routes, views):

```bash
# Copy changed files to server
rsync -av --exclude='.env' --exclude='storage/logs/' --exclude='storage/framework/cache/' \
    sync-server/backend/ root@the18th:/opt/metr-sync/site/backend/

# Clear route cache
ssh -i ~/.ssh/id_the18th_root root@the18th "docker exec metr-sync-php php artisan route:cache"
```

---

## File Reference

| File | Purpose |
|------|---------|
| `src-tauri/tauri.conf.json` | **Version source of truth**, updater endpoint config |
| `src-tauri/Cargo.toml` | Rust package version (must match tauri.conf.json) |
| `package.json` | NPM version (must match tauri.conf.json) |
| `src-tauri/src/lib.rs` | `PARSER_VERSION` constant — bump when parser logic changes |
| `~/.tauri/metr.key` | Base64-encoded minisign secret key for updater signing |
| `sync-server/backend/app/Console/Commands/PublishUpdateRelease.php` | Server release publisher with signature normalization |
| `sync-server/backend/app/Http/Controllers/Api/V1/UpdateController.php` | Update endpoint that returns 204 or manifest |
