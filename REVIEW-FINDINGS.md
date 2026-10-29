# Review findings

Reviewers (Codex or Claude subagents) append findings here before each deploy.

## 2026-10-29 — branch fix/codex-zstd-homes (Claude subagent reviewer; Codex quota exhausted)
No new double-count path for Codex tokens. Findings and disposition:
- R1 (med-high) repeated-token_count rows already stored are never removed (no PARSER_VERSION bump, reparse never deletes) → FIX: bump parser version; on a complete Codex file reparse delete that file's rows whose raw hash is no longer produced. Server keeps already-synced repeats (~0.2%), noted.
- R2 (med) Windows `\\?\`/case and macOS symlink paths → duplicate Codex source → FIX: canonical, case-folded path keys.
- R3 (med) removed auto sources come back → FIX: dismissed_source_paths table.
- R4 (med-low) duplicate-update rewrites synced rows' source/conversation → FIX: Codex duplicates are left untouched.
- R5 (med-low) source inside a Codex home blocks the home → FIX: only an ancestor that actually walks the home blocks it (also fixes tester T2).
- R6 (low-med) drift false positives for non-Codex parsers and OS junk files → FIX.
- R7 (low) partial/truncated reads drop the file → FIX: lossy UTF-8 per line; keep events read so far, do not mark indexed.
- R8 (low) drift not computed for already-indexed files → covered by R1 bump.
- R9 (low) lookup can match generic rows → FIX: `AND parser_id = 'codex'`.
- R10 (low) Windows `~\` CODEX_HOME, `\\?\` compare → FIX; macOS GUI apps don't see CODEX_HOME (noted, default homes cover Petar's setup).
- Pre-existing: update_codex_context takes session_id from any payload.id → FIX: only from session_meta.
- Perf: startup cleanup scans table each start (ms) → acceptable.
