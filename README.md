# MEtR

**Local-first LLM usage tracker.** MEtR indexes your local conversation logs from Claude, Kimi, OpenAI Codex, Cursor, Cline, Continue, Ollama, and LM Studio — then calculates what that usage would have cost you at API pricing. Compare subscription spend against real usage, track projects over time, and optionally sync across devices.

[![Download](https://img.shields.io/badge/Download-metr.petarpetkov.com-blue)](https://metr.petarpetkov.com)
[![GitHub Release](https://img.shields.io/github/v/release/mar0der/MEtR)](https://github.com/mar0der/MEtR/releases/latest)

---

## Download

- **macOS** (Apple Silicon) — [Download DMG](https://metr.petarpetkov.com) | [GitHub Releases](https://github.com/mar0der/MEtR/releases/latest)
- **Windows** (x64) — [Download MSI](https://metr.petarpetkov.com) | [GitHub Releases](https://github.com/mar0der/MEtR/releases/latest)
- **Linux** (Ubuntu/Debian x64) — [Download .deb](https://metr.petarpetkov.com) | [GitHub Releases](https://github.com/mar0der/MEtR/releases/latest)

The app includes an auto-updater. New versions are published to the update server automatically — no manual reinstall needed.

---

## What MEtR Does

### Track Real Usage from Local Logs
MEtR reads your existing LLM conversation files — no copy-paste, no API keys, no telemetry. It parses token usage metadata from logs created by:

- **Claude Code** (`~/.claude`)
- **OpenAI / Codex CLI** (`~/.codex`)
- **Cursor** (`~/Library/Application Support/Cursor`)
- **Kimi / Moonshot** (`~/.kimi`)
- **Cline / Roo Code** (`~/Library/Application Support/Code/User/globalStorage`)
- **Continue** (`~/.continue`)
- **Ollama** (`~/.ollama`)
- **LM Studio** (`~/.lmstudio`)

### Calculate API-Equivalent Cost
For every model call, MEtR looks up live pricing (input, cached input, output tokens) and calculates what you would have paid if you had used the API directly. See at a glance whether your subscription is saving you money or costing you more.

### Project-Level Breakdown
MEtR detects project roots from your conversation paths and aggregates usage per project. See which repositories or workspaces are driving the most token spend.

### Optional Cloud Sync
MEtR is **local-first** — all data lives in a local SQLite database. Raw log files never leave your device.

If you want to sync anonymized usage events across multiple machines (e.g., work Mac + personal Mac), you can connect to a self-hosted sync server. Only parsed, anonymized model call events are uploaded — never raw conversation content.

The sync server is included in this repo under `sync-server/` (Laravel + Docker).

---

## Features

- 📁 **Multi-provider indexing** — Claude, Codex, Cursor, Kimi, Cline, Continue, Ollama, LM Studio
- 💰 **API cost calculation** with per-model pricing catalog
- 📊 **Dashboard** with daily/weekly/monthly breakdowns
- 🏷️ **Project detection** and per-project spend tracking
- 🔄 **Incremental scanning** — only re-parses changed files
- ☁️ **Optional sync** across devices (self-hosted)
- 🍎 **Native desktop app** — macOS, Windows, Linux
- 🔒 **Private by default** — no cloud required, no telemetry

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Desktop framework | Tauri 2 |
| Frontend | React 19 + TypeScript |
| Build tool | Vite 8 |
| Backend | Rust (edition 2021) |
| Local database | SQLite via `rusqlite` (bundled) |
| HTTP client | `reqwest` (blocking) |
| Sync server | Laravel (PHP) + Docker + nginx |

---

## Development

```bash
# Install dependencies
npm install

# Run the desktop app in dev mode
npm run tauri:dev

# Build frontend only
npm run build

# Build release installers (macOS locally)
npm run tauri:build
```

### Windows Build Prerequisites

Tauri on Windows requires Microsoft Visual Studio C++ Build Tools:

- Visual Studio Build Tools 2022
- Workload: **Desktop development with C++**
- MSVC toolchain + Windows SDK

If Cargo fails on certificate revocation checks:

```powershell
$env:CARGO_HTTP_CHECK_REVOKE='false'
cargo check
```

---

## Testing Parsers

Synthetic parser fixtures live in `fixtures/`:

```text
fixtures/
  claude/
  codex/
  generic-jsonl/
  malformed/
```

Add one of those folders as a manual source in **Settings**, then run **Rescan** to test indexing without reading real LLM history.

---

## Sync Server

The optional sync server lives in `sync-server/` and is deployed via Docker Compose. It handles:

- Anonymous device registration
- Anonymized usage event sync
- Multi-device dashboard aggregation
- Update artifact hosting (macOS `.tar.gz`, Windows `.msi`, Linux `.AppImage`)
- Release publishing via `php artisan metr:release:publish`

See `sync-server/README.md` and `docs/sync_backend_laravel_implementation_and_deployment.md` for setup instructions.

---

## Contributing

Contributions are welcome. The codebase is intentionally concentrated in two files:

- `src/main.tsx` — all React components, hooks, types, and UI logic
- `src-tauri/src/lib.rs` — all Tauri commands, DB schema/migrations, parsing, pricing, sync, and queries

For agent-specific conventions, build steps, and deployment rules, see [`AGENTS.md`](AGENTS.md).

---

## License

MIT
