# MEtR — Agent Quick Reference

## Deployment Rules (READ THIS FIRST)

### Two Workflows, Two Triggers

| Workflow | File | Trigger | Purpose |
|---|---|---|---|
| **Build and Release** | `.github/workflows/release.yml` | Push tag `v*.*.*` OR manual `workflow_dispatch` | Builds and deploys desktop apps (macOS/Windows/Linux) |
| **Deploy Backend** | `.github/workflows/deploy-backend.yml` | Push to `main` changing `sync-server/backend/**` | Deploys Laravel backend to `the18th` |

### How to Release Desktop Apps

```bash
# 1. Make sure version is synced across:
#    - package.json
#    - src-tauri/Cargo.toml
#    - src-tauri/tauri.conf.json
#
# 2. Push a tag:
git tag v26.24.0
git push origin v26.24.0
```

The workflow will:
- Build macOS (Apple-signed), Windows (MSI), Linux (`.deb` + `.AppImage`)
- Create a GitHub Release with auto-generated changelog
- Deploy artifacts to `the18th` via Tailscale
- Activate the updater via `php artisan metr:release:publish`

### How to Deploy Backend Changes

```bash
# Edit sync-server/backend/...
git add sync-server/backend/
git commit -m "fix: backend thing"
git push origin main
```

Backend deploy automatically rsyncs code and clears caches. **Migrations are never auto-run.**

### Hard Rules

- **NEVER** manually SCP/RSYNC desktop artifacts (`*.dmg`, `*.msi`, `*.deb`, `*.tar.gz`, `*.AppImage`) to the server.
- **NEVER** run local `npx tauri bundle` and upload the result.
- **NEVER** run `php artisan metr:release:publish` manually unless the GitHub workflow is broken.
- **ALWAYS** keep `package.json`, `src-tauri/Cargo.toml`, and `src-tauri/tauri.conf.json` versions in sync.
- **ALWAYS** review Laravel migrations before running them manually on the server.

### Required Secrets

- `TAURI_SIGNING_PRIVATE_KEY` + `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`
- `APPLE_CERTIFICATE` + `APPLE_CERTIFICATE_PASSWORD`
- `TAILSCALE_AUTH_KEY`
- `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`

### Server Info

- Host: `the18th.taild48c09.ts.net` (Tailscale)
- Path: `/opt/metr-sync/site/backend`
- Container: `metr-sync-php`
- Public URL: `https://metr.petarpetkov.com`
