# METR bugs

## Fixed on `fix/codex-zstd-homes` (not yet released)
- **Codex `.jsonl.zst` rollouts never scanned** → Mac Codex events dropped 31k (Aug) → 639 (Oct). Fixed: zstd decoding.
- **Extra Codex homes not scanned** (`~/.codex-acct2`, `~/.codex-accounts/*`, `$CODEX_HOME`). Fixed: discovery + auto-registration.
- **Local double count when Codex archives/compresses a rollout** (event id hashed the file path). Fixed: raw-line reconciliation + one-time local cleanup. Server was already safe.
- **Codex repeated token_count counted twice** (~0.2% overcount). Fixed forward; already-imported repeats stay in history (not worth a full reparse).
