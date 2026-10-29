//! `metr`: headless METR for Linux dev machines and servers.
//! Scans Claude Code and Codex logs, syncs usage to the METR server, and
//! serves a read-only local web UI (loopback and Tailscale addresses only).

mod schedule;
mod serve;
mod update;

use clap::{Parser, Subcommand};
use metr_lib::headless::{self, GroupBy, Paths, UsageQuery};
use serde_json::Value;
use std::io::{BufRead, IsTerminal};

const DEFAULT_SERVER: &str = "https://metr.petarpetkov.com";
// 8765 is taken by llama-server on the20th.
const DEFAULT_PORT: u16 = 8790;

#[derive(Parser)]
#[command(
    name = "metr",
    version = metr_lib::VERSION,
    about = "Track Claude Code and Codex token usage"
)]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Show sync state, sources (with format drift) and totals per provider.
    Status {
        #[arg(long)]
        json: bool,
    },
    /// Scan logs for new usage (only changed files unless --full).
    Scan {
        #[arg(long)]
        full: bool,
        #[arg(long)]
        json: bool,
    },
    /// Query usage. Dates are UTC (YYYY-MM-DD or RFC 3339).
    Usage {
        /// Inclusive start, e.g. 2026-10-01.
        #[arg(long)]
        since: Option<String>,
        /// Exclusive end, e.g. 2026-11-01.
        #[arg(long)]
        until: Option<String>,
        /// Only this provider (anthropic, openai, ...).
        #[arg(long)]
        provider: Option<String>,
        /// total | day | model | project | provider
        #[arg(long, default_value = "provider")]
        by: GroupBy,
        #[arg(long)]
        json: bool,
    },
    /// Scan, then push pending events to the server now.
    Sync {
        /// Re-send every event (the server dedupes).
        #[arg(long)]
        full: bool,
        #[arg(long)]
        json: bool,
    },
    /// Log this machine in as a METR device. Prompts for the password.
    Login {
        #[arg(long, default_value = DEFAULT_SERVER)]
        server: String,
        /// Username or email.
        #[arg(long)]
        user: String,
        /// Device name shown on the server (default: linux-<hostname>).
        #[arg(long)]
        device: Option<String>,
        /// Read the password from the first line of stdin (for scripted installs).
        #[arg(long)]
        password_stdin: bool,
        /// Log in even though the METR desktop app also tracks this machine.
        #[arg(long)]
        allow_with_desktop: bool,
    },
    /// Compare local events with what the server holds for this device.
    Reconcile {
        /// Also list every device's totals on the server.
        #[arg(long)]
        all: bool,
        #[arg(long)]
        json: bool,
    },
    /// Log this device out and forget the token.
    Logout,
    /// One gentle scan + sync pass (what the systemd timer runs). Waits while
    /// the machine is busy and skips the pass if it stays busy.
    Run {
        /// Busy threshold: 1-minute load average per CPU core.
        #[arg(long, default_value_t = 1.5)]
        max_load_per_core: f64,
        /// How long to wait for the load to drop before skipping (minutes).
        #[arg(long, default_value_t = 15)]
        max_wait_minutes: u64,
        /// Run anyway (still at low priority) when the last successful run is
        /// older than this, so a constantly busy server still syncs.
        #[arg(long, default_value_t = 24)]
        force_after_hours: u64,
        #[arg(long)]
        json: bool,
    },
    /// Install systemd units: scheduled scan + sync, optionally the web UI.
    Install {
        /// hourly | daily
        #[arg(long, default_value = "hourly")]
        schedule: schedule::Schedule,
        /// Also run the web UI as a service.
        #[arg(long)]
        web: bool,
        /// Install system units in /etc/systemd/system (root), not --user units.
        #[arg(long)]
        system: bool,
        /// Install even though the METR desktop app also tracks this machine.
        #[arg(long)]
        allow_with_desktop: bool,
    },
    /// Stop and remove the systemd units `metr install` created.
    Uninstall {
        #[arg(long)]
        system: bool,
    },
    /// Serve the read-only web UI. Default: 127.0.0.1 and this machine's Tailscale IP.
    Serve {
        /// Address to bind (repeatable). Only loopback and Tailscale addresses are allowed.
        #[arg(long = "listen")]
        listen: Vec<String>,
        #[arg(long, default_value_t = DEFAULT_PORT)]
        port: u16,
    },
    /// Update to the latest METR release (builds it from source with cargo).
    SelfUpdate {
        /// Rebuild even if already on the latest release.
        #[arg(long)]
        force: bool,
        /// systemd unit to restart afterwards, e.g. metr.service.
        #[arg(long)]
        restart: Option<String>,
        /// The unit is a system unit (root installs), not a --user unit.
        #[arg(long)]
        system: bool,
    },
}

fn main() {
    let cli = Cli::parse();
    if let Err(error) = run(cli.command) {
        eprintln!("metr: {error}");
        std::process::exit(1);
    }
}

fn run(command: Command) -> Result<(), String> {
    if let Command::SelfUpdate {
        force,
        restart,
        system,
    } = command
    {
        return update::self_update(force, restart.as_deref(), system);
    }
    let paths = Paths::default_paths()?;
    let conn = headless::open(&paths)?;
    // Commands that write the database run one at a time (timer vs. manual).
    let _lock = if command.writes() {
        Some(lock(&paths)?)
    } else {
        None
    };
    match command {
        Command::Status { json } => {
            let mut status = headless::status(&conn, &paths)?;
            status["last_run"] = schedule::last_run(&paths).unwrap_or(Value::Null);
            print_or(json, &status, print_status)
        }
        Command::Scan { full, json } => {
            let result = headless::scan(&conn, full)?;
            print_or(json, &result, |r| {
                println!("Imported {} new model calls.", r["imported"])
            })
        }
        Command::Usage {
            since,
            until,
            provider,
            by,
            json,
        } => {
            let since = since.as_deref().map(headless::parse_bound).transpose()?;
            let until = until.as_deref().map(headless::parse_bound).transpose()?;
            if let (Some(since), Some(until)) = (&since, &until) {
                if since >= until {
                    return Err("--since must be before --until".to_string());
                }
            }
            let query = UsageQuery {
                since,
                until,
                provider,
                group_by: by,
            };
            let rows = headless::usage(&conn, &query)?;
            print_or(json, &Value::Array(rows), print_usage)
        }
        Command::Sync { full, json } => {
            headless::scan(&conn, false)?;
            let result = headless::sync(&conn, full)?;
            print_or(json, &result, |r| {
                println!(
                    "Uploaded {} events in {} batch(es).",
                    r["uploaded"], r["batches"]
                );
                for error in r["errors"].as_array().into_iter().flatten() {
                    println!("  error: {}", error.as_str().unwrap_or_default());
                }
            })
        }
        Command::Login {
            server,
            user,
            device,
            password_stdin,
            allow_with_desktop,
        } => {
            refuse_with_desktop(allow_with_desktop)?;
            let password = read_password(password_stdin)?;
            let device = device.or_else(default_device_name);
            let status = headless::login(&conn, &server, &user, &password, device)?;
            println!(
                "Logged in as {} on {} (device {}).",
                status["username"].as_str().unwrap_or(&user),
                status["server_url"].as_str().unwrap_or(&server),
                status["device_name"].as_str().unwrap_or("?")
            );
            // Login pulls server prices; refresh the API-equivalent costs.
            headless::maintenance(&conn)
        }
        Command::Reconcile { all, json } => {
            let report = headless::reconcile(&conn)?;
            print_or(json, &report, |r| print_reconcile(r, all))
        }
        Command::Logout => {
            headless::logout_device(&conn)?;
            println!("Logged out.");
            Ok(())
        }
        Command::Serve { listen, port } => {
            drop(conn);
            serve::serve_forever(paths, listen, port)
        }
        Command::Run {
            max_load_per_core,
            max_wait_minutes,
            force_after_hours,
            json,
        } => {
            if !max_load_per_core.is_finite() || max_load_per_core <= 0.0 {
                return Err("--max-load-per-core must be a positive number".to_string());
            }
            let limits = schedule::Limits {
                max_load_per_core,
                max_wait_minutes,
                force_after_hours,
            };
            let result = schedule::run_once(&conn, &paths, &limits)?;
            print_or(json, &result, |r| println!("{}", schedule::summary(r)))
        }
        Command::Install {
            schedule,
            web,
            system,
            allow_with_desktop,
        } => {
            refuse_with_desktop(allow_with_desktop)?;
            schedule::install(schedule, web, system)
        }
        Command::Uninstall { system } => schedule::uninstall(system),
        Command::SelfUpdate { .. } => unreachable!(),
    }
}

fn print_or(json_output: bool, value: &Value, human: impl Fn(&Value)) -> Result<(), String> {
    if json_output {
        println!(
            "{}",
            serde_json::to_string_pretty(value).map_err(|e| e.to_string())?
        );
    } else {
        human(value);
    }
    Ok(())
}

fn print_status(status: &Value) {
    let sync = &status["sync"];
    println!(
        "METR {}  database {}",
        status["version"].as_str().unwrap_or("?"),
        status["database"].as_str().unwrap_or("?")
    );
    if sync["logged_in"].as_bool().unwrap_or(false) {
        println!(
            "Sync: {} as {} (device {}), last sync {}, pending {}",
            sync["server_url"].as_str().unwrap_or("?"),
            sync["username"].as_str().unwrap_or("?"),
            sync["device_name"].as_str().unwrap_or("?"),
            sync["last_sync_at"].as_str().unwrap_or("never"),
            sync["pending_events"]
        );
        if let Some(error) = sync["last_sync_error"].as_str() {
            println!("  last error: {error}");
        }
    } else {
        println!("Sync: not logged in (metr login --user <name>)");
    }
    println!("Sources:");
    for source in status["sources"].as_array().into_iter().flatten() {
        let flag = if source["drift"].is_null() {
            ""
        } else {
            "  [FORMAT DRIFT]"
        };
        println!(
            "  {:<9} {}{}",
            source["provider"].as_str().unwrap_or("?"),
            source["path"].as_str().unwrap_or("?"),
            flag
        );
        if let Some(message) = source["last_scan_message"].as_str() {
            println!("            {message}");
        }
    }
    match status["last_run"].as_object() {
        Some(_) => println!(
            "Last scheduled run: {}",
            schedule::summary(&status["last_run"])
        ),
        None => println!("Last scheduled run: never (metr install sets up the timer)"),
    }
    println!("Totals:");
    print_usage(&status["totals"]);
}

fn print_usage(rows: &Value) {
    let rows = rows.as_array().cloned().unwrap_or_default();
    if rows.is_empty() {
        println!("  (no usage)");
        return;
    }
    println!(
        "  {:<24} {:<10} {:>8} {:>15} {:>15} {:>13} {:>12} {:>11} {:>10}",
        "group",
        "provider",
        "events",
        "input",
        "cached input",
        "cache r/w",
        "output",
        "reasoning",
        "api usd"
    );
    for row in rows {
        let cache_rw = row["cache_read_tokens"].as_i64().unwrap_or(0)
            + row["cache_write_tokens"].as_i64().unwrap_or(0);
        println!(
            "  {:<24} {:<10} {:>8} {:>15} {:>15} {:>13} {:>12} {:>11} {:>10.2}",
            truncate(row["group"].as_str().unwrap_or("?"), 24),
            row["provider"].as_str().unwrap_or("?"),
            row["events"].as_i64().unwrap_or(0),
            row["input_tokens"].as_i64().unwrap_or(0),
            row["cached_input_tokens"].as_i64().unwrap_or(0),
            cache_rw,
            row["output_tokens"].as_i64().unwrap_or(0),
            row["reasoning_tokens"].as_i64().unwrap_or(0),
            row["api_equivalent_usd"].as_f64().unwrap_or(0.0)
        );
    }
}

fn print_reconcile(report: &Value, all: bool) {
    println!(
        "  {:<10} {:>12} {:>12} {:>9} {:>13} {:>11}",
        "provider", "local", "synced", "pending", "on server", "difference"
    );
    let mut clean = true;
    for row in report["providers"].as_array().into_iter().flatten() {
        let difference = row["server_minus_synced"].as_i64().unwrap_or(0);
        clean &= difference == 0;
        println!(
            "  {:<10} {:>12} {:>12} {:>9} {:>13} {:>11}",
            row["provider"].as_str().unwrap_or("?"),
            row["local_events"].as_i64().unwrap_or(0),
            row["local_synced"].as_i64().unwrap_or(0),
            row["pending"].as_i64().unwrap_or(0),
            row["server_events"].as_i64().unwrap_or(0),
            difference
        );
    }
    let duplicates = report["duplicates_on_this_device"].as_array().map_or(0, Vec::len);
    let cross = report["duplicates_across_devices"].as_array().map_or(0, Vec::len);
    println!(
        "Duplicate lines on the server: this device {}, shared across devices {}.",
        if duplicates == 0 { "none".to_string() } else { format!("{duplicates} provider(s) affected") },
        if cross == 0 { "none".to_string() } else { format!("{cross} provider(s)") }
    );
    if clean && duplicates == 0 {
        println!("Server and local totals agree for every synced event.");
    }
    if all {
        println!("All devices on the server:");
        for row in report["all_devices"].as_array().into_iter().flatten() {
            println!(
                "  {:<28} {:<10} {:>9} events  last {}",
                truncate(row["device_name"].as_str().unwrap_or("?"), 28),
                row["provider_id"].as_str().unwrap_or("?"),
                row["events"].as_i64().unwrap_or(0),
                row["last_event"].as_str().unwrap_or("?")
            );
        }
    }
}

fn truncate(text: &str, max: usize) -> String {
    if text.chars().count() <= max {
        text.to_string()
    } else {
        let mut cut: String = text.chars().take(max - 1).collect();
        cut.push('…');
        cut
    }
}

impl Command {
    fn writes(&self) -> bool {
        matches!(
            self,
            Command::Scan { .. }
                | Command::Sync { .. }
                | Command::Run { .. }
                | Command::Login { .. }
                | Command::Logout
        )
    }
}

/// Exclusive lock for database writers; waits for a running pass to finish.
fn lock(paths: &Paths) -> Result<std::fs::File, String> {
    if let Some(dir) = paths.lock.parent() {
        std::fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    }
    let file = std::fs::OpenOptions::new()
        .create(true)
        .truncate(false)
        .write(true)
        .open(&paths.lock)
        .map_err(|e| format!("{}: {e}", paths.lock.display()))?;
    file.lock()
        .map_err(|e| format!("cannot lock {}: {e}", paths.lock.display()))?;
    Ok(file)
}

fn refuse_with_desktop(allowed: bool) -> Result<(), String> {
    match headless::desktop_app_database() {
        Some(db) if !allowed => Err(format!(
            "the METR desktop app already tracks this machine ({}); running both would count usage twice. \
             Use one of them, or pass --allow-with-desktop.",
            db.display()
        )),
        _ => Ok(()),
    }
}

fn default_device_name() -> Option<String> {
    std::fs::read_to_string("/etc/hostname")
        .ok()
        .map(|h| h.trim().to_string())
        .filter(|h| !h.is_empty())
        .map(|h| format!("linux-{h}"))
}

fn read_password(from_stdin: bool) -> Result<String, String> {
    let password = if from_stdin || !std::io::stdin().is_terminal() {
        let mut line = String::new();
        std::io::stdin()
            .lock()
            .read_line(&mut line)
            .map_err(|e| e.to_string())?;
        line.trim_end_matches(['\r', '\n']).to_string()
    } else {
        rpassword::prompt_password("METR password: ").map_err(|e| e.to_string())?
    };
    if password.is_empty() {
        return Err("empty password".to_string());
    }
    Ok(password)
}
