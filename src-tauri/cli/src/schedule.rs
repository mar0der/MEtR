//! Scheduled, load-aware scan + sync (`metr run`) and the systemd units
//! that trigger it (`metr install` / `metr uninstall`).

use metr_lib::headless::{self, Connection, Paths};
use serde_json::{json, Value};
use std::path::PathBuf;
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

/// 1-minute load average divided by the number of CPU cores.
fn load_per_core() -> Option<f64> {
    let loadavg = std::fs::read_to_string("/proc/loadavg").ok()?;
    let one_minute: f64 = loadavg.split_whitespace().next()?.parse().ok()?;
    let cores = std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1);
    Some(one_minute / cores as f64)
}

fn now() -> String {
    chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, true)
}

/// One pass: wait (up to `max_wait_minutes`) while the machine is busy,
/// then scan changed files and sync. Records the outcome in `paths.last_run`.
pub fn run_once(
    conn: &Connection,
    paths: &Paths,
    max_load_per_core: f64,
    max_wait_minutes: u64,
) -> Result<Value, String> {
    let started_at = now();
    let waiting_since = Instant::now();
    let max_wait = Duration::from_secs(max_wait_minutes * 60);
    let mut load = load_per_core();
    while load.is_some_and(|l| l > max_load_per_core) {
        if waiting_since.elapsed() >= max_wait {
            let result = json!({
                "started_at": started_at,
                "finished_at": now(),
                "outcome": "skipped_busy",
                "load_per_core": load,
                "max_load_per_core": max_load_per_core,
            });
            record(paths, &result);
            return Ok(result);
        }
        std::thread::sleep(Duration::from_secs(60));
        load = load_per_core();
    }

    let mut result = json!({
        "started_at": started_at,
        "load_per_core": load,
        "waited_seconds": waiting_since.elapsed().as_secs(),
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
    result["finished_at"] = json!(now());
    result["outcome"] = json!(if failure.is_some() { "error" } else { "ok" });
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
    let _ = std::fs::write(
        &paths.last_run,
        serde_json::to_string_pretty(result).unwrap_or_default(),
    );
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
        "skipped_busy" => format!("{when} skipped, machine busy ({load})"),
        outcome => {
            let mut text = format!(
                "{when} {outcome}: imported {}, uploaded {} ({load})",
                run["imported"].as_u64().unwrap_or(0),
                run["uploaded"].as_u64().unwrap_or(0)
            );
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
}

const UNITS: [&str; 3] = ["metr-sync.service", "metr-sync.timer", "metr-web.service"];

pub fn install(schedule: Schedule, web: bool, system: bool) -> Result<(), String> {
    let units = UnitDir::new(system)?;
    let exe = std::env::current_exe()
        .and_then(|p| p.canonicalize())
        .map_err(|e| format!("cannot find the metr binary: {e}"))?;
    let exe = exe.to_string_lossy();
    if exe.contains(char::is_whitespace) {
        return Err(format!("install metr to a path without spaces (now {exe})"));
    }
    std::fs::create_dir_all(&units.dir).map_err(|e| format!("{}: {e}", units.dir.display()))?;
    let (calendar, jitter) = match schedule {
        Schedule::Hourly => ("hourly", "10min"),
        Schedule::Daily => ("daily", "1h"),
    };
    let user_line = if system { "User=root\n" } else { "" };
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
             {user_line}\
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
                 {user_line}\
                 ExecStart={exe} serve\n\
                 Restart=on-failure\n\
                 RestartSec=60\n\
                 Nice=10\n\n\
                 [Install]\n\
                 WantedBy={wanted_by}\n"
            ),
        )?;
    }
    units.systemctl(&["daemon-reload"])?;
    units.systemctl(&["enable", "--now", "metr-sync.timer"])?;
    if web {
        units.systemctl(&["enable", "--now", "metr-web.service"])?;
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
        let path = units.dir.join(unit);
        if path.exists() {
            let _ = units.systemctl(&["disable", "--now", unit]);
            std::fs::remove_file(&path).map_err(|e| format!("{}: {e}", path.display()))?;
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
