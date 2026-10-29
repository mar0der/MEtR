//! Scheduled, load-aware scan + sync (`metr run`) and the systemd units
//! that trigger it (`metr install` / `metr uninstall`).

use metr_lib::headless::{self, Connection, Paths};
use serde_json::{json, Value};
use std::path::{Path, PathBuf};
use std::process::Command;
use std::time::{Duration, Instant};

#[derive(Debug, Clone, Copy)]
pub enum Schedule {
    Hourly,
    Daily,
}

impl std::str::FromStr for Schedule {
    type Err = String;
    fn from_str(value: &str) -> Result<Self, Self::Err> {
        match value {
            "hourly" => Ok(Self::Hourly),
            "daily" => Ok(Self::Daily),
            other => Err(format!("unknown schedule '{other}' (hourly|daily)")),
        }
    }
}

pub struct Limits {
    /// Busy threshold: 1-minute load average per CPU core.
    pub max_load_per_core: f64,
    /// How long to wait for the load to drop before skipping.
    pub max_wait_minutes: u64,
    /// Run anyway once the last successful run is older than this.
    pub force_after_hours: u64,
}

/// 1-minute load average divided by the number of CPU cores.
fn load_per_core() -> Option<f64> {
    let loadavg = std::fs::read_to_string("/proc/loadavg").ok()?;
    let one_minute: f64 = loadavg.split_whitespace().next()?.parse().ok()?;
    let cores = std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1);
    Some(one_minute / cores as f64)
}

fn now() -> chrono::DateTime<chrono::Utc> {
    chrono::Utc::now()
}

fn stamp(time: chrono::DateTime<chrono::Utc>) -> String {
    time.to_rfc3339_opts(chrono::SecondsFormat::Secs, true)
}

/// One pass: wait (up to `max_wait_minutes`) while the machine is busy,
/// then scan changed files and sync. A machine that stays busy still gets a
/// pass once the last successful one is older than `force_after_hours`.
/// Records the outcome in `paths.last_run`.
pub fn run_once(conn: &Connection, paths: &Paths, limits: &Limits) -> Result<Value, String> {
    let started_at = now();
    let last_ok_at = last_run(paths)
        .and_then(|run| run["last_ok_at"].as_str().map(str::to_string))
        .and_then(|text| chrono::DateTime::parse_from_rfc3339(&text).ok())
        .map(|time| time.with_timezone(&chrono::Utc));
    let overdue = last_ok_at.is_none_or(|time| {
        started_at - time > chrono::Duration::hours(limits.force_after_hours as i64)
    });
    let waiting_since = Instant::now();
    let max_wait = Duration::from_secs(limits.max_wait_minutes * 60);
    let mut load = load_per_core();
    let mut forced = false;
    while load.is_some_and(|l| l > limits.max_load_per_core) {
        if overdue && last_ok_at.is_some() {
            forced = true;
            break;
        }
        if waiting_since.elapsed() >= max_wait {
            let result = json!({
                "started_at": stamp(started_at),
                "finished_at": stamp(now()),
                "outcome": "skipped_busy",
                "load_per_core": load,
                "max_load_per_core": limits.max_load_per_core,
                "last_ok_at": last_ok_at.map(stamp),
            });
            record(paths, &result);
            return Ok(result);
        }
        std::thread::sleep(Duration::from_secs(60));
        load = load_per_core();
    }

    let mut result = json!({
        "started_at": stamp(started_at),
        "load_per_core": load,
        "waited_seconds": waiting_since.elapsed().as_secs(),
        "forced_while_busy": forced,
    });
    let mut failure = None;
    match headless::scan(conn, false) {
        Ok(scan) => result["imported"] = scan["imported"].clone(),
        Err(error) => failure = Some(format!("scan: {error}")),
    }
    let logged_in = headless::is_logged_in(conn).unwrap_or(false);
    result["logged_in"] = json!(logged_in);
    if failure.is_none() && logged_in {
        match headless::sync(conn, false) {
            Ok(sync) => {
                result["uploaded"] = sync["uploaded"].clone();
                result["sync_errors"] = sync["errors"].clone();
                if sync["errors"].as_array().is_some_and(|e| !e.is_empty()) {
                    failure = Some("sync reported errors".to_string());
                }
            }
            Err(error) => failure = Some(format!("sync: {error}")),
        }
    }
    if failure.is_none() {
        // Server prices may have changed; keep API-equivalent costs current.
        if let Err(error) = headless::maintenance(conn) {
            failure = Some(format!("maintenance: {error}"));
        }
    }
    let finished_at = now();
    result["finished_at"] = json!(stamp(finished_at));
    result["outcome"] = json!(if failure.is_some() { "error" } else { "ok" });
    result["last_ok_at"] = json!(if failure.is_none() {
        Some(stamp(finished_at))
    } else {
        last_ok_at.map(stamp)
    });
    if let Some(error) = &failure {
        result["error"] = json!(error);
    }
    record(paths, &result);
    match failure {
        // A failed run exits non-zero so systemd shows the unit as failed.
        Some(error) => Err(format!("{error} ({})", summary(&result))),
        None => Ok(result),
    }
}

fn record(paths: &Paths, result: &Value) {
    if let Some(dir) = paths.last_run.parent() {
        let _ = std::fs::create_dir_all(dir);
    }
    let staged = paths.last_run.with_extension("tmp");
    if std::fs::write(
        &staged,
        serde_json::to_string_pretty(result).unwrap_or_default(),
    )
    .is_ok()
    {
        let _ = std::fs::rename(&staged, &paths.last_run);
    }
}

pub fn last_run(paths: &Paths) -> Option<Value> {
    let text = std::fs::read_to_string(&paths.last_run).ok()?;
    serde_json::from_str(&text).ok()
}

pub fn summary(run: &Value) -> String {
    let when = run["finished_at"].as_str().unwrap_or("?");
    let load = run["load_per_core"]
        .as_f64()
        .map(|l| format!("load {l:.2}/core"))
        .unwrap_or_else(|| "load ?".to_string());
    match run["outcome"].as_str().unwrap_or("?") {
        "skipped_busy" => format!(
            "{when} skipped, machine busy ({load}); last ok {}",
            run["last_ok_at"].as_str().unwrap_or("never")
        ),
        outcome => {
            let mut text = format!(
                "{when} {outcome}: imported {}, uploaded {} ({load})",
                run["imported"].as_u64().unwrap_or(0),
                run["uploaded"].as_u64().unwrap_or(0)
            );
            if run["forced_while_busy"] == json!(true) {
                text.push_str(", ran although busy (overdue)");
            }
            if run["logged_in"] == json!(false) {
                text.push_str(", not logged in");
            }
            if let Some(error) = run["error"].as_str() {
                text.push_str(&format!(", error: {error}"));
            }
            text
        }
    }
}

struct UnitDir {
    dir: PathBuf,
    system: bool,
}

impl UnitDir {
    fn new(system: bool) -> Result<Self, String> {
        let dir = if system {
            PathBuf::from("/etc/systemd/system")
        } else {
            std::env::var_os("HOME")
                .map(PathBuf::from)
                .ok_or("HOME is not set")?
                .join(".config/systemd/user")
        };
        Ok(Self { dir, system })
    }

    fn systemctl(&self, args: &[&str]) -> Result<(), String> {
        let mut command = Command::new("systemctl");
        if !self.system {
            command.arg("--user");
        }
        let status = command
            .args(args)
            .status()
            .map_err(|e| format!("systemctl: {e}"))?;
        if status.success() {
            Ok(())
        } else {
            Err(format!("systemctl {} failed", args.join(" ")))
        }
    }

    fn remove(&self, unit: &str) -> Result<bool, String> {
        let path = self.dir.join(unit);
        if !path.exists() {
            return Ok(false);
        }
        let _ = self.systemctl(&["disable", "--now", unit]);
        std::fs::remove_file(&path).map_err(|e| format!("{}: {e}", path.display()))?;
        Ok(true)
    }
}

const UNITS: [&str; 3] = ["metr-sync.service", "metr-sync.timer", "metr-web.service"];

/// Root must not run a binary that another user can replace: the file and
/// every folder above it must be owned by root and not group/other-writable.
#[cfg(unix)]
fn check_root_owned(exe: &Path) -> Result<(), String> {
    use std::os::unix::fs::MetadataExt;
    for path in exe.ancestors() {
        if path.as_os_str().is_empty() {
            continue;
        }
        let meta = std::fs::metadata(path).map_err(|e| format!("{}: {e}", path.display()))?;
        if meta.uid() != 0 || meta.mode() & 0o022 != 0 {
            return Err(format!(
                "for --system, install metr where only root can change it (e.g. /usr/local/bin); {} is not",
                path.display()
            ));
        }
    }
    Ok(())
}

/// systemd treats `%` as a specifier in unit files.
fn unit_escape(path: &str) -> String {
    path.replace('%', "%%")
}

pub fn install(schedule: Schedule, web: bool, system: bool) -> Result<(), String> {
    let units = UnitDir::new(system)?;
    let exe = std::env::current_exe()
        .and_then(|p| p.canonicalize())
        .map_err(|e| format!("cannot find the metr binary: {e}"))?;
    if system {
        #[cfg(unix)]
        check_root_owned(&exe)?;
    }
    let exe_text = exe.to_string_lossy();
    if exe_text.contains(char::is_whitespace) {
        return Err(format!(
            "install metr to a path without spaces (now {exe_text})"
        ));
    }
    let exe = unit_escape(&exe_text);
    let paths = Paths::default_paths()?;
    std::fs::create_dir_all(&units.dir).map_err(|e| format!("{}: {e}", units.dir.display()))?;
    let (calendar, jitter) = match schedule {
        Schedule::Hourly => ("hourly", "10min"),
        Schedule::Daily => ("daily", "1h"),
    };
    // System units run as root: sandbox them to writing only METR's own folders.
    let (sync_hardening, web_hardening) = if system {
        let mut writable = Vec::new();
        for path in [&paths.db, &paths.token, &paths.last_run] {
            let dir = path.parent().ok_or("bad data path")?;
            std::fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
            writable.push(unit_escape(&dir.to_string_lossy()));
        }
        let common = "NoNewPrivileges=yes\nPrivateTmp=yes\nProtectSystem=strict\n\
                      ProtectKernelTunables=yes\nProtectControlGroups=yes\nRestrictSUIDSGID=yes\n";
        (
            format!("User=root\n{common}ReadWritePaths={}\n", writable.join(" ")),
            format!(
                "User=root\n{common}ProtectHome=read-only\nReadWritePaths={}\n",
                writable.join(" ")
            ),
        )
    } else {
        (String::new(), String::new())
    };
    let wanted_by = if system {
        "multi-user.target"
    } else {
        "default.target"
    };
    let write = |name: &str, body: String| -> Result<(), String> {
        let path = units.dir.join(name);
        std::fs::write(&path, body).map_err(|e| format!("{}: {e}", path.display()))
    };
    write(
        "metr-sync.service",
        format!(
            "[Unit]\n\
             Description=METR scan and sync (Claude Code and Codex usage)\n\
             After=network-online.target\n\
             Wants=network-online.target\n\n\
             [Service]\n\
             Type=oneshot\n\
             {sync_hardening}\
             ExecStart={exe} run\n\
             Nice=19\n\
             IOSchedulingClass=idle\n\
             CPUSchedulingPolicy=batch\n\
             TimeoutStartSec=2h\n"
        ),
    )?;
    write(
        "metr-sync.timer",
        format!(
            "[Unit]\n\
             Description=Run METR scan and sync {calendar}\n\n\
             [Timer]\n\
             OnCalendar={calendar}\n\
             RandomizedDelaySec={jitter}\n\
             Persistent=true\n\n\
             [Install]\n\
             WantedBy=timers.target\n"
        ),
    )?;
    if web {
        write(
            "metr-web.service",
            format!(
                "[Unit]\n\
                 Description=METR local web UI (loopback and Tailscale only)\n\
                 After=network-online.target\n\n\
                 [Service]\n\
                 {web_hardening}\
                 ExecStart={exe} serve\n\
                 Restart=on-failure\n\
                 RestartSec=60\n\
                 Nice=10\n\n\
                 [Install]\n\
                 WantedBy={wanted_by}\n"
            ),
        )?;
    } else {
        // Reinstalling without --web removes a web UI installed earlier.
        units.remove("metr-web.service")?;
    }
    units.systemctl(&["daemon-reload"])?;
    units.systemctl(&["enable", "--now", "metr-sync.timer"])?;
    if web {
        units.systemctl(&["enable", "--now", "metr-web.service"])?;
        units.systemctl(&["restart", "metr-web.service"])?;
    }
    println!(
        "Installed in {} ({calendar} scan + sync{}).",
        units.dir.display(),
        if web { ", web UI" } else { "" }
    );
    if !system {
        println!(
            "To keep it running while you are logged out: loginctl enable-linger {}",
            std::env::var("USER").unwrap_or_else(|_| "$USER".to_string())
        );
    }
    Ok(())
}

pub fn uninstall(system: bool) -> Result<(), String> {
    let units = UnitDir::new(system)?;
    let mut removed = Vec::new();
    for unit in UNITS {
        if units.remove(unit)? {
            removed.push(unit);
        }
    }
    units.systemctl(&["daemon-reload"])?;
    if removed.is_empty() {
        println!("Nothing to remove in {}.", units.dir.display());
    } else {
        println!(
            "Removed {} from {}.",
            removed.join(", "),
            units.dir.display()
        );
    }
    println!(
        "Data stays in ~/.local/share/metr and ~/.config/metr; delete them to forget this device."
    );
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn percent_signs_are_escaped_for_systemd() {
        assert_eq!(unit_escape("/opt/100%/metr"), "/opt/100%%/metr");
    }

    #[cfg(unix)]
    #[test]
    fn user_owned_binaries_are_refused_for_system_units() {
        let exe = std::env::current_exe().unwrap();
        // Test binaries live in the user's build folder.
        if !std::env::var("USER").is_ok_and(|u| u == "root") {
            assert!(check_root_owned(&exe).is_err());
        }
    }

    #[test]
    fn summary_mentions_busy_skips_and_forced_runs() {
        let skipped = json!({"outcome": "skipped_busy", "finished_at": "t", "load_per_core": 3.0});
        assert!(summary(&skipped).contains("skipped, machine busy"));
        let forced = json!({"outcome": "ok", "finished_at": "t", "forced_while_busy": true, "logged_in": true});
        assert!(summary(&forced).contains("overdue"));
    }
}
