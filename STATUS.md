# METR status

## 2026-10-29
- PLAN.md written (incl. Guardian addendum A–G). Next: M0 baseline `cargo test`, then M1 step 1 (core crate split).

## needs-petar
- **Device alias leak:** server device `windows-DESKTOP-PG7TRK4` (last seen Oct 9) has an API-key-like string as its alias. Proposal: clear that alias (single-row UPDATE, non-destructive). Please rotate that Kimi key. Not printed anywhere.
- **Linux device login:** `metr login` on the20th needs your METR password typed by you (`! metr login`) when M1 install is ready.
- **Push:** deploy key status to be checked at first commit; if missing, add `~/.ssh/gh_MEtR.pub` as a write deploy key on mar0der/MEtR.
