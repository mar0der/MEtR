# Test findings

Testers (Codex or Claude subagents) append findings here before each deploy.

## 2026-10-29 — branch fix/codex-zstd-homes (Claude subagent tester, black-box fake homes; Codex quota exhausted)
27 cases, 23 pass (plain+zst+archived+handoffs dedupe, overlapping homes, multi-frame/long-window/levels zst, truncated/empty zst → drift, uppercase ext, CRLF/BOM, >1 MB line, non-JSON lines, re-emits vs equal last-usage, CODEX_HOME absolute/relative/trailing slash/tilde/non-home, acct1 auth-only, symlinked homes, outside-dir drift, unknown type drift).
Failures:
- T1 (high) invalid UTF-8 byte in one line drops the whole rollout (`BufRead::lines` error aborts file).
- T2 (medium) CODEX_HOME nested inside ~/.codex is never scanned (skipped as "covered", but ~/.codex only walks its rollout dirs).
- T3 (low) symlinked rollout file silently skipped.
- T4 (medium) stray pretty-printed `.json` in sessions/ creates fake events via the generic whole-file JSON fallback.
- T5 (harness) real_codex_homes_smoke panics on SUM(NULL) when nothing imported.
Note: when a Codex event is found again in another file/source, the UPDATE path moves the row's source_id to the newer source.
Status: all fixed (T1–T5) with tests; see commit after 2c8b5c1.

## 2026-10-29 — branch feat/linux-cli (Claude subagent tester, fake HOME)
Pass: scan/status/usage totals vs own sums, rescan idempotent, run (normal/busy/not logged in), sync/login errors, bind refusals, Host checks, long queries, 120 concurrent scans/runs vs 800 web requests (no lock errors).
- CT1 `--by project` → no such column `p.custom_name` → FIXED (join project_management).
- CT2 dates: offsets ignored, invalid dates accepted → FIXED (`parse_bound`: UTC conversion, validation, since < until).
- CT3 only `%3A` decoded → FIXED (full percent-decoding, then validation).
- CT4 idle connections never closed (1203 threads) → FIXED (timeouts; verified 40/40 idle closed after 10 s).
- minor: NaN load threshold → rejected; cache read/write column added to the table.
