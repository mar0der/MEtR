# METR status

## 2026-10-29
- PLAN.md written (incl. Guardian addendum A–G).
- 17:05 Petar decisions folded in: Codex tracking first (zstd, all Codex homes, format-drift check), per-provider only (no account attribution), Cursor dropped, Grok Bot research via Guardian. Next: M1.1 baseline `cargo test`, then zstd.

## needs-petar
- **Device alias leak:** server device `windows-DESKTOP-PG7TRK4` (last seen Oct 9) has an API-key-like string as its alias. Proposal: clear that alias (single-row UPDATE, non-destructive). Please rotate that Kimi key. Not printed anywhere.
- **Push:** Guardian will add the deploy key (Petar decision 6); committing locally meanwhile.
- **Linux device login:** `metr login` on the20th needs your METR password typed by you (`! metr login`) when M1 install is ready.
- **Push blocked** (checked 2026-10-29): `git@gh-MEtR` is denied. Please add `~/.ssh/gh_MEtR.pub` as a write deploy key on mar0der/MEtR. Commits stay local until then.
