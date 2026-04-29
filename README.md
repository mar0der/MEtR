# MEtR

Local-first desktop app for tracking LLM token usage from local log/history files and comparing subscription spend against API-equivalent pricing.

## Stack

- Tauri 2
- React + TypeScript + Vite
- Rust backend
- SQLite via `rusqlite`

## Development

Install frontend dependencies:

```powershell
npm install
```

Run frontend-only build:

```powershell
npm run build
```

Run the desktop app:

```powershell
npm run tauri:dev
```

Build installers:

```powershell
npm run tauri:build
```

## Windows Build Requirement

Tauri/Rust on Windows requires Microsoft Visual Studio C++ Build Tools, including the MSVC linker `link.exe`.

Install:

- Visual Studio Build Tools 2022
- Workload: Desktop development with C++
- MSVC toolchain
- Windows SDK

If Cargo fails to download crates because of certificate revocation checks on a corporate network, retry with:

```powershell
$env:CARGO_HTTP_CHECK_REVOKE='false'
cargo check
```

## Fixtures

Synthetic parser fixtures live in:

```text
fixtures/
  claude/
  codex/
  generic-jsonl/
  malformed/
```

Add one of those folders as a manual source in Settings, then run Rescan to test indexing without reading real LLM history.
