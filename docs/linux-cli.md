# METR headless CLI for Linux

One binary (`metr`) for Linux dev machines and servers. It scans Claude Code
and Codex logs, syncs usage to the METR server as its own device, and can
serve a read-only web UI on 127.0.0.1 and the machine's Tailscale IP. It is
never reachable on 0.0.0.0 or the LAN.

- Portable: x86_64, glibc 2.17 or newer, no OpenSSL needed.
- Sources: `~/.claude` (+ `$CLAUDE_CONFIG_DIR`), `~/.codex`, `$CODEX_HOME`,
  `~/.codex-*`, `~/.codex-accounts/*`, including compressed `.jsonl.zst` rollouts.
- Data: `~/.local/share/metr/metr.db`. Token: `~/.config/metr/token` (0600).
  Last scheduled run: `~/.local/state/metr/last-run.json`.
- Do not run it on a machine where the METR desktop app also runs (both would
  report the same logs as two devices). `metr login` and `metr install` refuse
  if they find the desktop app's database.

## Build (on the20th)

```sh
scripts/build-linux-cli.sh
# → src-tauri/target/dist/metr-<version>-linux-x86_64.tar.gz (+ .sha256)
```

## Install for a normal user (the20th)

```sh
tar -xzf metr-<version>-linux-x86_64.tar.gz
install -m 0755 metr-<version>-linux-x86_64/metr ~/.local/bin/metr
metr scan                                   # first scan, prints imported calls
metr login --user <metr-username>           # prompts for the password (type it yourself)
metr install --schedule hourly --web        # systemd --user timer + web UI
loginctl show-user "$USER" -p Linger        # must be yes to run while logged out
```

The web UI is then on http://127.0.0.1:8790/ and http://<tailscale-ip>:8790/.

## Install on a server as root (the18th)

The scheduled job runs as root so it can read `/root/.codex`. It sees only
root's home folder.

```sh
sha256sum -c metr-<version>-linux-x86_64.tar.gz.sha256
tar -xzf metr-<version>-linux-x86_64.tar.gz
install -o root -g root -m 0755 metr-<version>-linux-x86_64/metr /usr/local/bin/metr
metr scan                                   # as root
metr login --user <metr-username> --device linux-the18th   # password typed by a human
metr install --system --schedule hourly     # add --web only if the UI is wanted there
systemctl list-timers metr-sync.timer
```

`--system` refuses to install unless `/usr/local/bin/metr` and every folder
above it is owned by root and not writable by others. System units run
sandboxed (NoNewPrivileges, ProtectSystem=strict, PrivateTmp, write access
only to METR's own folders).

## Scheduling and load

`metr-sync.timer` runs `metr run` hourly (or daily with `--schedule daily`),
with a random delay of up to 10 minutes (1 hour for daily), and catches up
after downtime. `metr run`:

- runs at the lowest priority (Nice=19, idle IO, batch CPU);
- waits while the 1-minute load average per CPU core is above 1.5
  (`--max-load-per-core`), for up to 15 minutes (`--max-wait-minutes`), then
  skips that run;
- still runs if the last successful run is older than 24 hours
  (`--force-after-hours`), so a constantly busy server keeps syncing;
- scans only new or changed files and uploads only unsent events (the server
  ignores duplicates).

## Health check (Guardian)

```sh
metr status            # sync state, sources (FORMAT DRIFT flags), last run, totals
metr status --json | jq '.last_run, .sync.last_sync_at, .sync.pending_events'
journalctl --user -u metr-sync.service -n 20      # use without --user for --system installs
```

`last_run.outcome` is `ok`, `skipped_busy` or `error`. A failed run also
marks `metr-sync.service` as failed.

## Everyday commands

```sh
metr usage --by day --since 2026-10-01              # also: model, project, provider, total
metr usage --by model --provider openai --json
metr sync                                           # scan and push now
metr serve --listen 127.0.0.1 --port 8790           # UI by hand
```

## Update

```sh
metr self-update --restart metr-web.service         # add --system for root installs
```

This builds the newest release tag from source (it needs `cargo`), then
replaces the binary atomically. Without cargo, install a new tarball as above.

## Uninstall

```sh
metr uninstall            # or: metr uninstall --system   (as root)
metr logout               # revokes this device's token on the server
rm ~/.local/bin/metr      # or /usr/local/bin/metr
rm -r ~/.local/share/metr ~/.config/metr ~/.local/state/metr   # forget local data
```
