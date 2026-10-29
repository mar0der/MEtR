//! API for the headless `metr` CLI and daemon (Linux dev machines and
//! servers). Thin wrappers over the shared core; no Tauri.

use super::*;

pub use rusqlite::Connection;

/// Where the CLI keeps its database and token.
#[derive(Debug, Clone)]
pub struct Paths {
    pub db: PathBuf,
    pub token: PathBuf,
    /// Last scheduled run, for health checks.
    pub last_run: PathBuf,
    /// Held while a command writes the database (scan, sync, run, login).
    pub lock: PathBuf,
}

impl Paths {
    /// `~/.local/share/metr/metr.db`, `~/.config/metr/token` and
    /// `~/.local/state/metr/last-run.json` (XDG dirs).
    pub fn default_paths() -> Result<Self, String> {
        let data = dirs::data_dir().ok_or("No data directory (is HOME set?)")?;
        let config = dirs::config_dir().ok_or("No config directory (is HOME set?)")?;
        let state = dirs::state_dir().unwrap_or_else(|| data.clone());
        Ok(Self {
            db: data.join("metr").join("metr.db"),
            token: config.join("metr").join("token"),
            last_run: state.join("metr").join("last-run.json"),
            lock: state.join("metr").join("metr.lock"),
        })
    }
}

/// Opens (and migrates) the database and switches token storage to the
/// token file. Call once per process before anything else.
pub fn open(paths: &Paths) -> Result<Connection, String> {
    let _ = TOKEN_FILE.set(paths.token.clone());
    if let Some(dir) = paths.db.parent() {
        fs::create_dir_all(dir).map_err(|e| format!("Cannot create {}: {e}", dir.display()))?;
    }
    let conn = open_connection(&paths.db)?;
    migrate(&conn).map_err(to_string)?;
    seed_defaults(&conn).map_err(to_string)?;
    ensure_subscription_renewals(&conn).map_err(to_string)?;
    ensure_sync_config(&conn)?;
    Ok(conn)
}

/// A second connection to an already migrated database (web UI requests).
pub fn open_connection(path: &Path) -> Result<Connection, String> {
    let conn = Connection::open(path).map_err(to_string)?;
    configure_connection(&conn).map_err(to_string)?;
    Ok(conn)
}

/// Startup housekeeping the desktop app runs in the background.
pub fn maintenance(conn: &Connection) -> Result<(), String> {
    cleanup_known_bad_imports(conn).map_err(to_string)?;
    recalculate_event_costs(conn).map_err(to_string)
}

/// Registers the sources METR tracks on a headless machine: Claude Code
/// (`~/.claude`, `$CLAUDE_CONFIG_DIR`) and every Codex home.
pub fn ensure_default_sources(conn: &Connection) -> Result<usize, String> {
    let mut added = 0;
    if let Some(home) = dirs::home_dir() {
        let configured = std::env::var("CLAUDE_CONFIG_DIR").ok();
        for path in claude_source_paths(&home, configured.as_deref()) {
            if ensure_claude_source_at(conn, &path)? {
                added += 1;
            }
        }
    }
    added += ensure_codex_sources(conn)?;
    Ok(added)
}

/// Scans every enabled source (only changed files unless `full`).
pub fn scan(conn: &Connection, full: bool) -> Result<Value, String> {
    ensure_default_sources(conn)?;
    let mut imported = 0;
    let mut sources = Vec::new();
    for source in query_sources(conn)?.into_iter().filter(|s| s.enabled) {
        let count = scan_source(conn, &source, full)?;
        imported += count;
        sources.push(json!({ "path": source.path, "provider": source.provider_id, "imported": count }));
    }
    Ok(json!({ "imported": imported, "sources": sources }))
}

/// Uploads pending events (all events when `full`).
pub fn sync(conn: &Connection, full: bool) -> Result<Value, String> {
    if get_sync_token(conn)?.is_none() {
        return Err("Not logged in. Run `metr login` first.".to_string());
    }
    let result = perform_sync(conn, full, SyncPatience::WaitForServer, |_, _| {})?;
    serde_json::to_value(result).map_err(to_string)
}

pub fn is_logged_in(conn: &Connection) -> Result<bool, String> {
    Ok(get_sync_token(conn)?.is_some())
}

pub fn login(
    conn: &Connection,
    server_url: &str,
    login: &str,
    password: &str,
    device_name: Option<String>,
) -> Result<Value, String> {
    let input = LoginInput {
        login: login.to_string(),
        password: password.to_string(),
        server_url: server_url.to_string(),
    };
    let status = login_with_password(conn, &input, device_name)?;
    serde_json::to_value(status).map_err(to_string)
}

pub fn logout_device(conn: &Connection) -> Result<Value, String> {
    serde_json::to_value(logout(conn)?).map_err(to_string)
}

/// Sync state, sources (with format drift) and per-provider totals.
pub fn status(conn: &Connection, paths: &Paths) -> Result<Value, String> {
    let sync = get_sync_config(conn)?;
    let mut sources = Vec::new();
    for source in query_sources(conn)? {
        let drift: Option<String> = conn
            .query_row(
                "SELECT drift_json FROM log_sources WHERE id = ?1",
                params![source.id],
                |r| r.get(0),
            )
            .map_err(to_string)?;
        sources.push(json!({
            "path": source.path,
            "provider": source.provider_id,
            "parser": source.parser_id,
            "enabled": source.enabled,
            "last_scan_status": source.last_scan_status,
            "last_scan_message": source.last_scan_message,
            "drift": drift.and_then(|d| serde_json::from_str::<Value>(&d).ok()),
        }));
    }
    let totals = usage(conn, &UsageQuery { group_by: GroupBy::Provider, ..UsageQuery::default() })?;
    Ok(json!({
        "version": env!("CARGO_PKG_VERSION"),
        "database": paths.db,
        "sync": sync,
        "sources": sources,
        "totals": totals,
    }))
}

#[derive(Debug, Clone, Copy, Default, PartialEq)]
pub enum GroupBy {
    #[default]
    Total,
    Day,
    Model,
    Project,
    Provider,
}

impl std::str::FromStr for GroupBy {
    type Err = String;
    fn from_str(value: &str) -> Result<Self, Self::Err> {
        match value {
            "total" => Ok(Self::Total),
            "day" => Ok(Self::Day),
            "model" => Ok(Self::Model),
            "project" => Ok(Self::Project),
            "provider" => Ok(Self::Provider),
            other => Err(format!("unknown grouping '{other}' (total|day|model|project|provider)")),
        }
    }
}

#[derive(Debug, Clone, Default)]
pub struct UsageQuery {
    /// Inclusive lower bound, compared to the stored RFC 3339 timestamp (UTC).
    pub since: Option<String>,
    /// Exclusive upper bound.
    pub until: Option<String>,
    pub provider: Option<String>,
    pub group_by: GroupBy,
}

/// Token totals per provider and group. Days are UTC dates.
pub fn usage(conn: &Connection, query: &UsageQuery) -> Result<Vec<Value>, String> {
    let key = match query.group_by {
        GroupBy::Total => "'total'",
        GroupBy::Day => "substr(u.timestamp, 1, 10)",
        GroupBy::Model => "COALESCE(u.model, 'unknown')",
        GroupBy::Project => "COALESCE(pm.custom_name, p.display_name, p.path, 'unknown')",
        GroupBy::Provider => "u.provider_id",
    };
    let sql = format!(
        "SELECT {key} AS grp, u.provider_id, COUNT(*),
                SUM(u.input_tokens), SUM(u.cached_input_tokens), SUM(u.cache_write_tokens),
                SUM(u.cache_read_tokens), SUM(u.output_tokens), SUM(u.reasoning_tokens),
                SUM(u.unknown_tokens), SUM(COALESCE(u.official_api_cost_usd, 0)),
                MIN(u.timestamp), MAX(u.timestamp)
         FROM usage_events u
         LEFT JOIN projects p ON p.id = u.project_id
         LEFT JOIN project_management pm ON pm.id = p.id
         WHERE (?1 IS NULL OR u.timestamp >= ?1)
           AND (?2 IS NULL OR u.timestamp < ?2)
           AND (?3 IS NULL OR u.provider_id = ?3)
         GROUP BY grp, u.provider_id
         ORDER BY grp, u.provider_id"
    );
    let mut stmt = conn.prepare(&sql).map_err(to_string)?;
    let rows = stmt
        .query_map(params![query.since, query.until, query.provider], |r| {
            Ok(json!({
                "group": r.get::<_, String>(0)?,
                "provider": r.get::<_, String>(1)?,
                "events": r.get::<_, i64>(2)?,
                "input_tokens": r.get::<_, i64>(3)?,
                "cached_input_tokens": r.get::<_, i64>(4)?,
                "cache_write_tokens": r.get::<_, i64>(5)?,
                "cache_read_tokens": r.get::<_, i64>(6)?,
                "output_tokens": r.get::<_, i64>(7)?,
                "reasoning_tokens": r.get::<_, i64>(8)?,
                "unknown_tokens": r.get::<_, i64>(9)?,
                "api_equivalent_usd": (r.get::<_, f64>(10)? * 100.0).round() / 100.0,
                "first_event": r.get::<_, String>(11)?,
                "last_event": r.get::<_, String>(12)?,
            }))
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

/// Turns a user date bound into the stored timestamp form: `YYYY-MM-DD`
/// (UTC midnight) or RFC 3339 with any offset, converted to UTC. The result
/// has no zone suffix, so it sorts correctly against stored timestamps that
/// end in `Z`, `.123Z` or `+00:00`.
pub fn parse_bound(value: &str) -> Result<String, String> {
    let value = value.trim();
    let utc = if let Ok(date) = NaiveDate::parse_from_str(value, "%Y-%m-%d") {
        date.and_hms_opt(0, 0, 0).expect("midnight").and_utc()
    } else {
        chrono::DateTime::parse_from_rfc3339(value)
            .map_err(|_| format!("invalid date '{value}' (use YYYY-MM-DD or RFC 3339)"))?
            .with_timezone(&Utc)
    };
    Ok(utc.format("%Y-%m-%dT%H:%M:%S").to_string())
}

/// Linux desktop app database. Running the app and the CLI on one machine
/// would register two devices and count the same logs twice.
pub fn desktop_app_database() -> Option<PathBuf> {
    let path = dirs::data_dir()?.join("com.metr.local").join("metr.db");
    path.is_file().then_some(path)
}

/// Compares this device's local events with what the server holds for it,
/// per provider, and passes on the server's duplicate checks.
pub fn reconcile(conn: &Connection) -> Result<Value, String> {
    let token = get_sync_token(conn)?.ok_or("Not logged in. Run `metr login` first.")?;
    let (server_url, device_uuid): (String, Option<String>) = conn
        .query_row("SELECT server_url, device_uuid FROM sync_config WHERE id = 1", [], |r| {
            Ok((r.get(0)?, r.get(1)?))
        })
        .map_err(to_string)?;
    let device_uuid = device_uuid.ok_or("This device has not synced yet. Run `metr sync` first.")?;

    let mut local: BTreeMap<String, Value> = BTreeMap::new();
    let mut stmt = conn
        .prepare(
            "SELECT provider_id, COUNT(*), SUM(synced_at IS NOT NULL), SUM(input_tokens),
                    SUM(output_tokens), SUM(cached_input_tokens), SUM(cache_read_tokens)
             FROM usage_events
             -- The server skips events whose token fields sum to zero.
             WHERE input_tokens + output_tokens + cached_input_tokens + cache_write_tokens
                   + cache_write_1h_tokens + cache_read_tokens + reasoning_tokens
                   + tool_tokens + unknown_tokens > 0
             GROUP BY provider_id",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok((
                r.get::<_, String>(0)?,
                json!({
                    "events": r.get::<_, i64>(1)?,
                    "synced_events": r.get::<_, i64>(2)?,
                    "input_tokens": r.get::<_, i64>(3)?,
                    "output_tokens": r.get::<_, i64>(4)?,
                    "cached_input_tokens": r.get::<_, i64>(5)?,
                    "cache_read_tokens": r.get::<_, i64>(6)?,
                }),
            ))
        })
        .map_err(to_string)?;
    for row in rows {
        let (provider, totals) = row.map_err(to_string)?;
        local.insert(provider, totals);
    }

    let server: Value = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(120))
        .build()
        .map_err(to_string)?
        .get(format!("{}/api/v1/sync/reconcile", server_url.trim_end_matches('/')))
        .header("Authorization", format!("Bearer {token}"))
        .header("Accept", "application/json")
        .send()
        .and_then(|r| r.error_for_status())
        .map_err(|e| format!("Server reconcile failed: {e}"))?
        .json()
        .map_err(|e| format!("Invalid reconcile response: {e}"))?;

    let empty = Vec::new();
    let server_rows = server["totals"].as_array().unwrap_or(&empty);
    let mine: Vec<&Value> = server_rows.iter().filter(|row| row["device_uuid"] == json!(device_uuid)).collect();
    let mut providers: BTreeSet<String> = local.keys().cloned().collect();
    providers.extend(mine.iter().filter_map(|row| row["provider_id"].as_str().map(str::to_string)));
    let mut rows = Vec::new();
    for provider in providers {
        let here = local.get(&provider).cloned().unwrap_or_else(|| json!({}));
        let there = mine
            .iter()
            .find(|row| row["provider_id"] == json!(provider))
            .map(|row| (*row).clone())
            .unwrap_or_else(|| json!({}));
        let get = |v: &Value, key: &str| v[key].as_i64().unwrap_or(0);
        let synced = get(&here, "synced_events");
        let on_server = get(&there, "events");
        rows.push(json!({
            "provider": provider,
            "local_events": get(&here, "events"),
            "local_synced": synced,
            "pending": get(&here, "events") - synced,
            "server_events": on_server,
            // Positive: the server holds rows this device no longer has locally
            // (e.g. re-emits removed after an upgrade); negative: missing uploads.
            "server_minus_synced": on_server - synced,
            "local_input_tokens": get(&here, "input_tokens"),
            "server_input_tokens": get(&there, "input_tokens"),
            "local_output_tokens": get(&here, "output_tokens"),
            "server_output_tokens": get(&there, "output_tokens"),
        }));
    }
    let id_of_mine = mine.first().map(|row| row["device_id"].clone());
    let same_device: Vec<Value> = server["duplicates"]["same_device"]
        .as_array()
        .unwrap_or(&empty)
        .iter()
        .filter(|row| Some(&row["device_id"]) == id_of_mine.as_ref())
        .cloned()
        .collect();
    Ok(json!({
        "device_uuid": device_uuid,
        "providers": rows,
        "duplicates_on_this_device": same_device,
        "duplicates_across_devices": server["duplicates"]["cross_device"],
        "all_devices": server_rows,
    }))
}

/// Dashboard data for the local web UI.
pub fn dashboard(conn: &Connection) -> Result<Value, String> {
    serde_json::to_value(dashboard_summary(conn)?).map_err(to_string)
}

/// Writes `contents` to `path` readable only by the owner, replacing it
/// atomically so a concurrent reader never sees a half-written file.
pub fn write_private_file(path: &Path, contents: &str) -> Result<(), String> {
    use std::io::Write;
    if let Some(dir) = path.parent() {
        fs::create_dir_all(dir).map_err(|e| format!("Cannot create {}: {e}", dir.display()))?;
    }
    let final_path = path;
    let staged = path.with_extension(format!("tmp-{}", std::process::id()));
    let path = staged.as_path();
    let mut options = fs::OpenOptions::new();
    options.write(true).create(true).truncate(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    let mut file = options
        .open(path)
        .map_err(|e| format!("Cannot write {}: {e}", path.display()))?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        file.set_permissions(fs::Permissions::from_mode(0o600)).map_err(to_string)?;
    }
    file.write_all(contents.as_bytes()).map_err(to_string)?;
    file.sync_all().map_err(to_string)?;
    fs::rename(path, final_path).map_err(to_string)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn temp_paths() -> (PathBuf, Paths) {
        let root = std::env::temp_dir().join(format!("metr-headless-{}", Uuid::new_v4()));
        let paths = Paths {
            db: root.join("data/metr.db"),
            token: root.join("config/token"),
            last_run: root.join("state/last-run.json"),
            lock: root.join("state/metr.lock"),
        };
        (root, paths)
    }

    #[test]
    fn usage_groups_by_day_and_provider() {
        let (root, paths) = temp_paths();
        let conn = open(&paths).unwrap();
        let home = root.join("codex-home");
        let file = home.join("sessions").join("rollout-fixture.jsonl");
        fs::create_dir_all(file.parent().unwrap()).unwrap();
        fs::copy(
            "../fixtures/codex/rollout-2026-10/rollout-2026-10-29T10-00-00-0199f000-0000-7000-8000-00000000f107.jsonl",
            &file,
        )
        .unwrap();
        ensure_codex_sources_at(&conn, &[home]).unwrap();
        for source in query_sources(&conn).unwrap() {
            scan_source(&conn, &source, false).unwrap();
        }
        let by_day = usage(&conn, &UsageQuery { group_by: GroupBy::Day, ..UsageQuery::default() }).unwrap();
        assert_eq!(by_day.len(), 1);
        assert_eq!(by_day[0]["group"], "2026-10-29");
        assert_eq!(by_day[0]["provider"], "openai");
        assert_eq!(by_day[0]["events"], 2);
        assert_eq!(by_day[0]["input_tokens"], 25000);
        let none = usage(
            &conn,
            &UsageQuery { since: Some("2026-10-30".into()), ..UsageQuery::default() },
        )
        .unwrap();
        assert!(none.is_empty());
        let by_project =
            usage(&conn, &UsageQuery { group_by: GroupBy::Project, ..UsageQuery::default() }).unwrap();
        assert_eq!(by_project.len(), 1);
        assert_eq!(by_project[0]["group"], "fixture-app");
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn bounds_are_converted_to_utc_and_validated() {
        assert_eq!(parse_bound("2026-10-29").unwrap(), "2026-10-29T00:00:00");
        assert_eq!(parse_bound("2026-10-28T01:00:00+02:00").unwrap(), "2026-10-27T23:00:00");
        assert_eq!(parse_bound("2026-10-28T01:00:00Z").unwrap(), "2026-10-28T01:00:00");
        for bad in ["notadate", "2026-13-01", "2026-10-28T25:00:00Z", ""] {
            assert!(parse_bound(bad).is_err(), "{bad}");
        }
        // Stored forms sort on the right side of a bound at the same second.
        let bound = parse_bound("2026-10-29").unwrap();
        for stored in ["2026-10-29T00:00:00Z", "2026-10-29T00:00:00.000Z", "2026-10-29T00:00:00+00:00"] {
            assert!(stored > bound.as_str(), "{stored}");
        }
    }

    #[cfg(unix)]
    #[test]
    fn token_file_is_private() {
        use std::os::unix::fs::PermissionsExt;
        let (root, paths) = temp_paths();
        write_private_file(&paths.token, "secret").unwrap();
        let mode = fs::metadata(&paths.token).unwrap().permissions().mode() & 0o777;
        assert_eq!(mode, 0o600);
        fs::remove_dir_all(root).unwrap();
    }
}
