Synthetic Codex rollout in the Oct 2026 format (session_meta, turn_context,
token_count with `info: null`, token_usage_record, a repeated token_count with
unchanged totals, task_complete). The `.zst` copy is the same file compressed
with the zstd CLI, as Codex does for old rollouts. Expected: 2 usage events.
No real conversation content.
