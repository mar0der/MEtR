use chrono::{Datelike, Local, NaiveDate, TimeZone, Utc};
use rusqlite::{params, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::fs;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use tauri::{Manager, State};
use uuid::Uuid;
use walkdir::WalkDir;

struct AppState {
    db: Mutex<Connection>,
}

#[derive(Debug, Serialize)]
struct Source {
    id: String,
    provider_id: String,
    parser_id: String,
    display_name: String,
    path: String,
    enabled: bool,
    detection_confidence: String,
    last_scan_status: Option<String>,
    last_scan_message: Option<String>,
}

#[derive(Debug, Serialize)]
struct DetectedSource {
    provider_id: String,
    parser_id: String,
    display_name: String,
    path: String,
    confidence: String,
    found_file_count: usize,
    notes: String,
}

#[derive(Debug, Deserialize)]
struct AddSourceInput {
    path: String,
    provider_id: Option<String>,
    parser_id: Option<String>,
    display_name: Option<String>,
}

#[derive(Debug, Serialize)]
struct Subscription {
    id: String,
    provider_id: String,
    product_name: String,
    monthly_amount: f64,
    currency: String,
    billing_anchor_day: i64,
    enabled: bool,
}

#[derive(Debug, Deserialize)]
struct SubscriptionInput {
    provider_id: String,
    product_name: String,
    monthly_amount: f64,
    currency: String,
    billing_anchor_day: i64,
}

#[derive(Debug, Serialize)]
struct DashboardSummary {
    providers: Vec<ProviderSummary>,
    totals: UsageTotals,
    subscriptions_total: f64,
    api_equivalent_total: f64,
    net_savings_vs_api: f64,
    break_even_progress: Option<f64>,
    top_projects: Vec<ProjectSummary>,
    recent_sessions: Vec<SessionSummary>,
}

#[derive(Debug, Serialize, Default, Clone)]
struct UsageTotals {
    input_tokens: i64,
    output_tokens: i64,
    cached_input_tokens: i64,
    cache_write_tokens: i64,
    cache_read_tokens: i64,
    reasoning_tokens: i64,
    tool_tokens: i64,
    unknown_tokens: i64,
    total_tokens: i64,
}

#[derive(Debug, Serialize)]
struct ProviderSummary {
    provider_id: String,
    display_name: String,
    totals: UsageTotals,
    api_equivalent_cost: f64,
    subscription_amount: f64,
    net_savings_vs_api: f64,
    source_count: i64,
    last_seen: Option<String>,
}

#[derive(Debug, Serialize)]
struct ProjectSummary {
    id: String,
    provider_id: String,
    display_name: String,
    path: Option<String>,
    totals: UsageTotals,
    api_equivalent_cost: f64,
    last_seen: Option<String>,
}

#[derive(Debug, Serialize)]
struct SessionSummary {
    id: String,
    provider_id: String,
    project_name: Option<String>,
    model: Option<String>,
    timestamp: String,
    total_tokens: i64,
    api_equivalent_cost: Option<f64>,
    confidence: String,
}

#[derive(Debug, Clone)]
struct ParsedEvent {
    provider_id: String,
    product_id: Option<String>,
    timestamp: String,
    project_path: Option<String>,
    conversation_id: Option<String>,
    message_id: Option<String>,
    request_id: Option<String>,
    model: Option<String>,
    input_tokens: i64,
    output_tokens: i64,
    cached_input_tokens: i64,
    cache_write_tokens: i64,
    cache_read_tokens: i64,
    reasoning_tokens: i64,
    tool_tokens: i64,
    unknown_tokens: i64,
    source_offset: Option<i64>,
    raw_record_hash: String,
    confidence: String,
    warnings: Vec<String>,
}

#[derive(Debug, Clone)]
struct Pricing {
    id: String,
    input_per_1m: Option<f64>,
    output_per_1m: Option<f64>,
    cached_input_per_1m: Option<f64>,
    cache_write_per_1m: Option<f64>,
    cache_read_per_1m: Option<f64>,
    reasoning_per_1m: Option<f64>,
    tool_per_1m: Option<f64>,
}

pub fn run() {
    tauri::Builder::default()
        .setup(|app| {
            let db_path = app
                .path()
                .app_data_dir()
                .unwrap_or_else(|_| std::env::current_dir().unwrap().join(".metr-data"));
            fs::create_dir_all(&db_path)?;
            let conn = Connection::open(db_path.join("metr.db"))?;
            migrate(&conn)?;
            seed_defaults(&conn)?;
            reprice_usage_events(&conn)?;
            app.manage(AppState {
                db: Mutex::new(conn),
            });
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            get_app_status,
            detect_sources,
            list_sources,
            add_source,
            remove_source,
            rescan_all,
            rescan_source,
            get_dashboard_summary,
            list_subscriptions,
            create_subscription,
            delete_subscription,
            list_pricing_catalog,
            login_sync,
            logout_sync,
            get_sync_status,
            configure_sync_server,
            sync_now
        ])
        .run(tauri::generate_context!())
        .expect("error while running MEtR");
}

#[tauri::command]
fn get_app_status(app: tauri::AppHandle) -> Result<Value, String> {
    let data_dir = app.path().app_data_dir().map_err(to_string)?;
    Ok(serde_json::json!({
        "name": "MEtR",
        "version": env!("CARGO_PKG_VERSION"),
        "databasePath": data_dir.join("metr.db").to_string_lossy()
    }))
}

#[tauri::command]
fn detect_sources() -> Result<Vec<DetectedSource>, String> {
    let mut results = Vec::new();
    for candidate in candidate_sources() {
        if candidate.path.exists() {
            let count = count_candidate_files(&candidate.path);
            results.push(DetectedSource {
                provider_id: candidate.provider_id.to_string(),
                parser_id: candidate.parser_id.to_string(),
                display_name: candidate.display_name.to_string(),
                path: candidate.path.to_string_lossy().to_string(),
                confidence: if count > 0 { "medium" } else { "low" }.to_string(),
                found_file_count: count,
                notes: if count > 0 {
                    "Folder exists and contains candidate files.".to_string()
                } else {
                    "Folder exists but no candidate files were found yet.".to_string()
                },
            });
        }
    }
    Ok(results)
}

#[tauri::command]
fn list_sources(state: State<AppState>) -> Result<Vec<Source>, String> {
    let conn = state.db.lock().map_err(to_string)?;
    query_sources(&conn)
}

#[tauri::command]
fn add_source(state: State<AppState>, input: AddSourceInput) -> Result<Source, String> {
    let path = PathBuf::from(&input.path);
    let (provider_id, parser_id, name) = match (&input.provider_id, &input.parser_id) {
        (Some(provider), Some(parser)) => (
            provider.clone(),
            parser.clone(),
            input
                .display_name
                .unwrap_or_else(|| provider_display_name(provider).to_string()),
        ),
        _ => infer_source(&path),
    };
    let now = now();
    let conn = state.db.lock().map_err(to_string)?;
    ensure_provider(&conn, &provider_id, provider_display_name(&provider_id)).map_err(to_string)?;
    let existing: Option<String> = conn
        .query_row(
            "SELECT id FROM log_sources WHERE path = ?1 AND provider_id = ?2",
            params![input.path, provider_id],
            |r| r.get(0),
        )
        .optional()
        .map_err(to_string)?;
    let id = existing.unwrap_or_else(|| Uuid::new_v4().to_string());
    conn.execute(
        "INSERT OR REPLACE INTO log_sources
        (id, provider_id, parser_id, display_name, path, enabled, recursive, include_patterns, exclude_patterns,
         detection_confidence, last_scan_status, last_scan_message, created_at, updated_at)
         VALUES (?1, ?2, ?3, ?4, ?5, 1, 1, ?6, ?7, 'manual', NULL, NULL,
         COALESCE((SELECT created_at FROM log_sources WHERE id = ?1), ?8), ?8)",
        params![
            id,
            provider_id,
            parser_id,
            name,
            input.path,
            "*.json,*.jsonl,*.log,*.txt,*.md",
            "node_modules,.git,target,dist,build,.next,vendor",
            now
        ],
    )
    .map_err(to_string)?;
    query_source(&conn, &id)
}

#[tauri::command]
fn remove_source(state: State<AppState>, source_id: String) -> Result<(), String> {
    let conn = state.db.lock().map_err(to_string)?;
    conn.execute("DELETE FROM log_sources WHERE id = ?1", params![source_id])
        .map_err(to_string)?;
    Ok(())
}

#[tauri::command]
fn rescan_all(state: State<AppState>) -> Result<Value, String> {
    let conn = state.db.lock().map_err(to_string)?;
    let sources = query_sources(&conn)?;
    let mut imported = 0usize;
    for source in sources.into_iter().filter(|s| s.enabled) {
        imported += scan_source(&conn, &source)?;
    }
    Ok(serde_json::json!({ "imported": imported }))
}

#[tauri::command]
fn rescan_source(state: State<AppState>, source_id: String) -> Result<Value, String> {
    let conn = state.db.lock().map_err(to_string)?;
    let source = query_source(&conn, &source_id)?;
    let imported = scan_source(&conn, &source)?;
    Ok(serde_json::json!({ "imported": imported }))
}

#[tauri::command]
fn get_dashboard_summary(state: State<AppState>) -> Result<DashboardSummary, String> {
    let conn = state.db.lock().map_err(to_string)?;
    let providers = query_provider_summaries(&conn)?;
    let top_projects = query_top_projects(&conn)?;
    let recent_sessions = query_recent_sessions(&conn)?;
    let totals = sum_usage(&providers);
    let subscriptions_total: f64 = providers.iter().map(|p| p.subscription_amount).sum();
    let api_equivalent_total: f64 = providers.iter().map(|p| p.api_equivalent_cost).sum();
    let break_even_progress = if subscriptions_total > 0.0 {
        Some(api_equivalent_total / subscriptions_total)
    } else {
        None
    };
    Ok(DashboardSummary {
        providers,
        totals,
        subscriptions_total,
        api_equivalent_total,
        net_savings_vs_api: api_equivalent_total - subscriptions_total,
        break_even_progress,
        top_projects,
        recent_sessions,
    })
}

#[tauri::command]
fn list_subscriptions(state: State<AppState>) -> Result<Vec<Subscription>, String> {
    let conn = state.db.lock().map_err(to_string)?;
    let mut stmt = conn
        .prepare(
            "SELECT id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, enabled
             FROM subscriptions ORDER BY provider_id, product_name",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok(Subscription {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                product_name: r.get(2)?,
                monthly_amount: r.get(3)?,
                currency: r.get(4)?,
                billing_anchor_day: r.get(5)?,
                enabled: r.get::<_, i64>(6)? == 1,
            })
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

#[tauri::command]
fn create_subscription(
    state: State<AppState>,
    input: SubscriptionInput,
) -> Result<Subscription, String> {
    if input.monthly_amount < 0.0 {
        return Err("Monthly amount must be positive.".to_string());
    }
    if input.billing_anchor_day < 1 || input.billing_anchor_day > 31 {
        return Err("Billing anchor day must be between 1 and 31.".to_string());
    }
    let conn = state.db.lock().map_err(to_string)?;
    ensure_provider(
        &conn,
        &input.provider_id,
        provider_display_name(&input.provider_id),
    )
    .map_err(to_string)?;
    let id = Uuid::new_v4().to_string();
    let now = now();
    conn.execute(
        "INSERT INTO subscriptions
        (id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, billing_anchor_time, enabled, created_at, updated_at)
        VALUES (?1, ?2, ?3, ?4, ?5, ?6, '00:00:00', 1, ?7, ?7)",
        params![
            id,
            input.provider_id,
            input.product_name,
            input.monthly_amount,
            input.currency,
            input.billing_anchor_day,
            now
        ],
    )
    .map_err(to_string)?;
    drop(conn);
    let subscriptions = list_subscriptions(state)?;
    subscriptions
        .into_iter()
        .find(|s| s.id == id)
        .ok_or_else(|| "Subscription was created but could not be loaded.".to_string())
}

#[tauri::command]
fn delete_subscription(state: State<AppState>, id: String) -> Result<(), String> {
    let conn = state.db.lock().map_err(to_string)?;
    conn.execute("DELETE FROM subscriptions WHERE id = ?1", params![id])
        .map_err(to_string)?;
    Ok(())
}

#[tauri::command]
fn list_pricing_catalog(state: State<AppState>) -> Result<Vec<Value>, String> {
    let conn = state.db.lock().map_err(to_string)?;
    let mut stmt = conn
        .prepare(
            "SELECT id, provider_id, model, aliases_json, input_per_1m, output_per_1m,
             cached_input_per_1m, cache_write_per_1m, cache_read_per_1m, source_url
             FROM pricing_catalogs ORDER BY provider_id, model",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok(serde_json::json!({
                "id": r.get::<_, String>(0)?,
                "provider_id": r.get::<_, String>(1)?,
                "model": r.get::<_, String>(2)?,
                "aliases": serde_json::from_str::<Value>(&r.get::<_, String>(3)?).unwrap_or(Value::Array(vec![])),
                "input_per_1m": r.get::<_, Option<f64>>(4)?,
                "output_per_1m": r.get::<_, Option<f64>>(5)?,
                "cached_input_per_1m": r.get::<_, Option<f64>>(6)?,
                "cache_write_per_1m": r.get::<_, Option<f64>>(7)?,
                "cache_read_per_1m": r.get::<_, Option<f64>>(8)?,
                "source_url": r.get::<_, Option<String>>(9)?
            }))
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

// ---------------------------------------------------------------------------
// Sync commands
// ---------------------------------------------------------------------------

#[derive(Debug, Deserialize)]
struct LoginInput {
    login: String,
    password: String,
    server_url: String,
}

#[derive(Debug, Serialize)]
struct SyncStatus {
    configured: bool,
    server_url: String,
    logged_in: bool,
    username: Option<String>,
    device_name: Option<String>,
    last_sync_at: Option<String>,
    pending_events: i64,
    sync_enabled: bool,
}

#[derive(Debug, Serialize)]
struct SyncResult {
    uploaded: usize,
    batches: usize,
    errors: Vec<String>,
}

fn ensure_sync_config(conn: &Connection) -> Result<(), String> {
    let exists: bool = conn
        .query_row("SELECT 1 FROM sync_config WHERE id = 1", [], |_| Ok(true))
        .unwrap_or(false);
    if !exists {
        let now = now();
        conn.execute(
            "INSERT INTO sync_config (id, server_url, created_at, updated_at)
             VALUES (1, 'https://metr.petarpetkov.com', ?1, ?1)",
            params![now],
        )
        .map_err(to_string)?;
    }
    Ok(())
}

fn get_sync_config(conn: &Connection) -> Result<SyncStatus, String> {
    ensure_sync_config(conn)?;
    let row = conn
        .query_row(
            "SELECT server_url, auth_token, device_name, username, last_sync_at, sync_enabled
             FROM sync_config WHERE id = 1",
            [],
            |r| {
                Ok((
                    r.get::<_, String>(0)?,
                    r.get::<_, Option<String>>(1)?,
                    r.get::<_, Option<String>>(2)?,
                    r.get::<_, Option<String>>(3)?,
                    r.get::<_, Option<String>>(4)?,
                    r.get::<_, i64>(5)? == 1,
                ))
            },
        )
        .map_err(to_string)?;

    let pending: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM usage_events WHERE synced_at IS NULL",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);

    Ok(SyncStatus {
        configured: true,
        server_url: row.0,
        logged_in: row.1.is_some(),
        username: row.3,
        device_name: row.2,
        last_sync_at: row.4,
        pending_events: pending,
        sync_enabled: row.5,
    })
}

#[tauri::command]
fn configure_sync_server(state: State<AppState>, server_url: String) -> Result<SyncStatus, String> {
    let conn = state.db.lock().map_err(to_string)?;
    ensure_sync_config(&conn)?;
    let now = now();
    conn.execute(
        "UPDATE sync_config SET server_url = ?1, updated_at = ?2 WHERE id = 1",
        params![server_url, now],
    )
    .map_err(to_string)?;
    get_sync_config(&conn)
}

#[tauri::command]
fn login_sync(state: State<AppState>, input: LoginInput) -> Result<SyncStatus, String> {
    let conn = state.db.lock().map_err(to_string)?;
    ensure_sync_config(&conn)?;

    let device_name = format!(
        "{}-{}",
        std::env::consts::OS,
        whoami::fallible::hostname().unwrap_or_else(|_| "unknown".to_string())
    );

    let client = reqwest::blocking::Client::new();
    let url = format!(
        "{}/api/v1/auth/login",
        input.server_url.trim_end_matches('/')
    );
    let resp = client
        .post(&url)
        .json(&serde_json::json!({
            "login": input.login,
            "password": input.password,
            "device_name": device_name,
        }))
        .send()
        .map_err(|e| format!("Login request failed: {}", e))?;

    if !resp.status().is_success() {
        let body = resp.text().unwrap_or_default();
        return Err(format!("Login failed: {}", body));
    }

    let data: Value = resp
        .json()
        .map_err(|e| format!("Invalid login response: {}", e))?;
    let token = data
        .get("token")
        .and_then(|t| t.as_str())
        .ok_or("No token in login response")?;
    let username = data
        .get("user")
        .and_then(|u| u.get("username"))
        .and_then(|u| u.as_str())
        .unwrap_or(&input.login)
        .to_string();

    let now = now();
    conn.execute(
        "UPDATE sync_config SET server_url = ?1, auth_token = ?2, username = ?3, device_name = ?4, sync_enabled = 1, updated_at = ?5 WHERE id = 1",
        params![input.server_url, token, username, device_name, now],
    )
    .map_err(to_string)?;

    get_sync_config(&conn)
}

#[tauri::command]
fn logout_sync(state: State<AppState>) -> Result<SyncStatus, String> {
    let conn = state.db.lock().map_err(to_string)?;
    ensure_sync_config(&conn)?;

    // Optionally notify server, but ignore errors
    if let Ok((Some(token), server_url)) = conn.query_row(
        "SELECT auth_token, server_url FROM sync_config WHERE id = 1",
        [],
        |r| Ok((r.get::<_, Option<String>>(0)?, r.get::<_, String>(1)?)),
    ) {
        let _ = reqwest::blocking::Client::new()
            .post(format!(
                "{}/api/v1/auth/logout",
                server_url.trim_end_matches('/')
            ))
            .header("Authorization", format!("Bearer {}", token))
            .header("Accept", "application/json")
            .send();
    }

    let now = now();
    conn.execute(
        "UPDATE sync_config SET auth_token = NULL, username = NULL, user_id = NULL, last_sync_at = NULL, sync_enabled = 0, updated_at = ?1 WHERE id = 1",
        params![now],
    )
    .map_err(to_string)?;

    get_sync_config(&conn)
}

#[tauri::command]
fn get_sync_status(state: State<AppState>) -> Result<SyncStatus, String> {
    let conn = state.db.lock().map_err(to_string)?;
    get_sync_config(&conn)
}

#[tauri::command]
fn sync_now(state: State<AppState>) -> Result<SyncResult, String> {
    let conn = state.db.lock().map_err(to_string)?;
    ensure_sync_config(&conn)?;

    let (token, server_url, device_uuid): (String, String, Option<String>) = conn
        .query_row(
            "SELECT auth_token, server_url, device_uuid FROM sync_config WHERE id = 1",
            [],
            |r| {
                Ok((
                    r.get::<_, String>(0)?,
                    r.get::<_, String>(1)?,
                    r.get::<_, Option<String>>(2)?,
                ))
            },
        )
        .map_err(|_| "Not logged in. Please log in first.".to_string())?;

    let device_uuid = match device_uuid {
        Some(uuid) => uuid,
        None => {
            let uuid = Uuid::new_v4().to_string();
            conn.execute(
                "UPDATE sync_config SET device_uuid = ?1, updated_at = ?2 WHERE id = 1",
                params![uuid, now()],
            )
            .map_err(to_string)?;
            uuid
        }
    };

    let base_url = server_url.trim_end_matches('/');
    let client = reqwest::blocking::Client::new();
    let auth_header = format!("Bearer {}", token);

    // Register device
    let device_name = conn
        .query_row(
            "SELECT device_name FROM sync_config WHERE id = 1",
            [],
            |r| r.get::<_, String>(0),
        )
        .unwrap_or_else(|_| format!("{}-unknown", std::env::consts::OS));

    let reg_resp = client
        .post(format!("{}/api/v1/devices/register", base_url))
        .header("Authorization", &auth_header)
        .header("Accept", "application/json")
        .json(&serde_json::json!({
            "device_uuid": device_uuid,
            "display_name": device_name,
            "platform": std::env::consts::OS,
            "hostname_hash": hash(&whoami::fallible::hostname().unwrap_or_else(|_| "unknown".to_string())),
            "os_version": whoami::distro(),
            "app_version": env!("CARGO_PKG_VERSION"),
        }))
        .send()
        .map_err(|e| format!("Device registration failed: {}", e))?;

    if !reg_resp.status().is_success() {
        let body = reg_resp.text().unwrap_or_default();
        return Err(format!("Device registration failed: {}", body));
    }

    // Query unsynced events in batches
    let mut total_uploaded = 0usize;
    let mut batch_count = 0usize;
    let mut errors = Vec::new();

    loop {
        let mut stmt = conn
            .prepare(
                "SELECT u.id, u.provider_id, u.timestamp, u.model,
                 u.input_tokens, u.output_tokens, u.cached_input_tokens,
                 u.cache_write_tokens, u.cache_read_tokens,
                 u.reasoning_tokens, u.tool_tokens, u.unknown_tokens,
                 u.source_hash, u.raw_record_hash, u.source_file_path, u.source_offset,
                 u.official_api_cost_usd, u.pricing_match_confidence, u.warnings_json,
                 p.path, p.display_name,
                 c.external_conversation_id, c.display_name,
                 u.created_at, u.updated_at
                 FROM usage_events u
                 LEFT JOIN projects p ON p.id = u.project_id
                 LEFT JOIN conversations c ON c.id = u.conversation_id
                 WHERE u.synced_at IS NULL
                 ORDER BY u.timestamp ASC
                 LIMIT 500",
            )
            .map_err(to_string)?;

        let events: Vec<Value> = stmt
            .query_map([], |r| {
                let project_path: Option<String> = r.get(19)?;
                let project_display: Option<String> = r.get(20)?;
                let conversation_external: Option<String> = r.get(21)?;
                let conversation_display: Option<String> = r.get(22)?;
                let cost: Option<f64> = r.get(16)?;
                let pricing_match_confidence: String = r.get(17)?;
                let warnings_json: String = r.get(18)?;
                let warnings = serde_json::from_str::<Value>(&warnings_json)
                    .unwrap_or_else(|_| Value::Array(vec![]));
                Ok(serde_json::json!({
                    "source_event_id": r.get::<_, String>(0)?,
                    "source_event_hash": r.get::<_, String>(13)?,
                    "source_file_hash": r.get::<_, String>(12)?,
                    "source_offset": r.get::<_, Option<i64>>(15)?,
                    "provider_id": r.get::<_, String>(1)?,
                    "timestamp": r.get::<_, String>(2)?,
                    "model": r.get::<_, Option<String>>(3)?,
                    "project": project_path.map(|root_path| serde_json::json!({
                        "root_path": root_path,
                        "display_name": project_display,
                    })),
                    "conversation": conversation_external.map(|external_conversation_id| serde_json::json!({
                        "external_conversation_id": external_conversation_id,
                        "display_name": conversation_display,
                    })),
                    "tokens": {
                        "input": r.get::<_, i64>(4)?,
                        "output": r.get::<_, i64>(5)?,
                        "cached_input": r.get::<_, i64>(6)?,
                        "cache_write": r.get::<_, i64>(7)?,
                        "cache_read": r.get::<_, i64>(8)?,
                        "reasoning": r.get::<_, i64>(9)?,
                        "tool": r.get::<_, i64>(10)?,
                        "unknown": r.get::<_, i64>(11)?,
                    },
                    "client_cost": cost.map(|official_api_cost_usd| serde_json::json!({
                        "official_api_cost_usd": official_api_cost_usd,
                        "pricing_match_confidence": pricing_match_confidence,
                    })),
                    "warnings": warnings,
                    "client_created_at": r.get::<_, String>(23)?,
                    "client_updated_at": r.get::<_, String>(24)?,
                }))
            })
            .map_err(to_string)?
            .collect::<Result<Vec<_>, _>>()
            .map_err(to_string)?;

        if events.is_empty() {
            break;
        }

        let client_batch_id = format!("{}-{}", device_uuid, Uuid::new_v4());
        let resp = client
            .post(format!("{}/api/v1/sync/events", base_url))
            .header("Authorization", &auth_header)
            .header("Accept", "application/json")
            .json(&serde_json::json!({
                "device_uuid": device_uuid,
                "client_batch_id": client_batch_id,
                "events": events,
            }))
            .send()
            .map_err(|e| format!("Sync request failed: {}", e))?;

        if resp.status().is_success() {
            let now = now();
            let event_ids: Vec<String> = events
                .iter()
                .filter_map(|e| {
                    e.get("source_event_id")
                        .and_then(|v| v.as_str())
                        .map(|s| s.to_string())
                })
                .collect();

            for id in event_ids {
                conn.execute(
                    "UPDATE usage_events
                     SET synced_at = ?1, sync_batch_id = ?2, sync_error = NULL, updated_at = ?1
                     WHERE id = ?3",
                    params![now, client_batch_id, id],
                )
                .map_err(to_string)?;
            }

            total_uploaded += events.len();
            batch_count += 1;
        } else {
            let body = resp.text().unwrap_or_default();
            errors.push(format!("Batch {} failed: {}", batch_count + 1, body));
            let failed_at = now();
            for event in &events {
                if let Some(id) = event.get("source_event_id").and_then(|v| v.as_str()) {
                    conn.execute(
                        "UPDATE usage_events SET sync_error = ?1, updated_at = ?2 WHERE id = ?3",
                        params![errors.last().cloned().unwrap_or_default(), failed_at, id],
                    )
                    .map_err(to_string)?;
                }
            }
            break;
        }
    }

    let now = now();
    conn.execute(
        "UPDATE sync_config SET last_sync_at = ?1, updated_at = ?1 WHERE id = 1",
        params![now],
    )
    .map_err(to_string)?;

    Ok(SyncResult {
        uploaded: total_uploaded,
        batches: batch_count,
        errors,
    })
}

fn add_column_if_missing(
    conn: &Connection,
    table: &str,
    column: &str,
    def: &str,
) -> rusqlite::Result<()> {
    let sql = format!("ALTER TABLE {} ADD COLUMN {} {}", table, column, def);
    match conn.execute(&sql, []) {
        Ok(_) => Ok(()),
        Err(rusqlite::Error::SqliteFailure(code, Some(msg)))
            if code.extended_code == 1 && msg.contains("duplicate column name") =>
        {
            Ok(())
        }
        Err(e) => Err(e),
    }
}

fn migrate(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(
        "
        PRAGMA foreign_keys = ON;
        CREATE TABLE IF NOT EXISTS providers (
          id TEXT PRIMARY KEY,
          display_name TEXT NOT NULL,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS log_sources (
          id TEXT PRIMARY KEY,
          provider_id TEXT NOT NULL,
          parser_id TEXT NOT NULL,
          display_name TEXT NOT NULL,
          path TEXT NOT NULL,
          enabled INTEGER NOT NULL DEFAULT 1,
          recursive INTEGER NOT NULL DEFAULT 1,
          include_patterns TEXT NOT NULL,
          exclude_patterns TEXT NOT NULL,
          detection_confidence TEXT NOT NULL,
          last_scan_started_at TEXT,
          last_scan_finished_at TEXT,
          last_scan_status TEXT,
          last_scan_message TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS projects (
          id TEXT PRIMARY KEY,
          provider_id TEXT,
          display_name TEXT NOT NULL,
          path TEXT,
          normalized_path_hash TEXT,
          first_seen_at TEXT NOT NULL,
          last_seen_at TEXT NOT NULL,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS conversations (
          id TEXT PRIMARY KEY,
          provider_id TEXT NOT NULL,
          project_id TEXT,
          external_conversation_id TEXT,
          display_name TEXT,
          first_seen_at TEXT NOT NULL,
          last_seen_at TEXT NOT NULL,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS usage_events (
          id TEXT PRIMARY KEY,
          provider_id TEXT NOT NULL,
          product_id TEXT,
          source_id TEXT NOT NULL,
          parser_id TEXT NOT NULL,
          parser_version TEXT NOT NULL,
          timestamp TEXT NOT NULL,
          project_id TEXT,
          conversation_id TEXT,
          message_id TEXT,
          request_id TEXT,
          model TEXT,
          input_tokens INTEGER NOT NULL DEFAULT 0,
          output_tokens INTEGER NOT NULL DEFAULT 0,
          cached_input_tokens INTEGER NOT NULL DEFAULT 0,
          cache_write_tokens INTEGER NOT NULL DEFAULT 0,
          cache_read_tokens INTEGER NOT NULL DEFAULT 0,
          reasoning_tokens INTEGER NOT NULL DEFAULT 0,
          tool_tokens INTEGER NOT NULL DEFAULT 0,
          unknown_tokens INTEGER NOT NULL DEFAULT 0,
          official_api_cost_usd REAL,
          pricing_catalog_id TEXT,
          pricing_match_confidence TEXT NOT NULL,
          source_file_path TEXT NOT NULL,
          source_file_modified_at TEXT NOT NULL,
          source_offset INTEGER,
          source_hash TEXT NOT NULL,
          raw_record_hash TEXT NOT NULL,
          confidence TEXT NOT NULL,
          warnings_json TEXT NOT NULL DEFAULT '[]',
          synced_at TEXT,
          sync_batch_id TEXT,
          sync_error TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS sync_config (
          id INTEGER PRIMARY KEY CHECK (id = 1),
          server_url TEXT NOT NULL DEFAULT 'https://metr.petarpetkov.com',
          auth_token TEXT,
          device_uuid TEXT,
          device_name TEXT,
          user_id TEXT,
          username TEXT,
          last_sync_at TEXT,
          sync_enabled INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS pricing_catalogs (
          id TEXT PRIMARY KEY,
          provider_id TEXT NOT NULL,
          model TEXT NOT NULL,
          aliases_json TEXT NOT NULL DEFAULT '[]',
          source_url TEXT,
          catalog_version TEXT NOT NULL,
          effective_from TEXT NOT NULL,
          currency TEXT NOT NULL DEFAULT 'USD',
          unit TEXT NOT NULL DEFAULT 'per_1m_tokens',
          input_per_1m REAL,
          output_per_1m REAL,
          cached_input_per_1m REAL,
          cache_write_per_1m REAL,
          cache_read_per_1m REAL,
          reasoning_per_1m REAL,
          tool_per_1m REAL,
          reasoning_billing TEXT,
          user_override INTEGER NOT NULL DEFAULT 0,
          notes TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS subscriptions (
          id TEXT PRIMARY KEY,
          provider_id TEXT NOT NULL,
          product_name TEXT NOT NULL,
          monthly_amount REAL NOT NULL,
          currency TEXT NOT NULL DEFAULT 'USD',
          billing_anchor_day INTEGER NOT NULL,
          billing_anchor_time TEXT NOT NULL DEFAULT '00:00:00',
          enabled INTEGER NOT NULL DEFAULT 1,
          notes TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_usage_events_timestamp ON usage_events(timestamp);
        CREATE INDEX IF NOT EXISTS idx_usage_events_provider ON usage_events(provider_id);
        CREATE INDEX IF NOT EXISTS idx_usage_events_project ON usage_events(project_id);
        CREATE INDEX IF NOT EXISTS idx_usage_events_model ON usage_events(model);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_usage_events_dedupe ON usage_events(id);
        CREATE INDEX IF NOT EXISTS idx_pricing_provider_model ON pricing_catalogs(provider_id, model);
        ",
    )?;
    add_column_if_missing(conn, "usage_events", "synced_at", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "sync_batch_id", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "sync_error", "TEXT")?;
    Ok(())
}

fn seed_defaults(conn: &Connection) -> rusqlite::Result<()> {
    for (id, name) in [
        ("openai", "OpenAI / Codex"),
        ("anthropic", "Claude"),
        ("cursor", "Cursor"),
        ("google", "Gemini"),
        ("cline", "Cline / Roo Code"),
        ("continue", "Continue"),
        ("aider", "Aider"),
        ("lmstudio", "LM Studio"),
        ("ollama", "Ollama"),
        ("cloudflare", "Cloudflare Workers AI"),
        ("kimi", "Kimi / Moonshot"),
        ("generic", "Generic JSONL"),
    ] {
        ensure_provider(conn, id, name)?;
    }
    seed_price(
        conn,
        "openai:gpt-5.1",
        "openai",
        "gpt-5.1",
        &["gpt-5.1-codex"],
        Some(1.25),
        Some(10.0),
        Some(0.125),
        None,
        None,
        "https://openai.com/api/pricing/",
    )?;
    seed_price(
        conn,
        "openai:gpt-5.5",
        "openai",
        "gpt-5.5",
        &[],
        Some(5.0),
        Some(30.0),
        Some(0.50),
        None,
        None,
        "https://openai.com/api/pricing/",
    )?;
    seed_price(
        conn,
        "openai:gpt-5.4",
        "openai",
        "gpt-5.4",
        &[],
        Some(2.50),
        Some(15.0),
        Some(0.25),
        None,
        None,
        "https://openai.com/api/pricing/",
    )?;
    seed_price(
        conn,
        "openai:gpt-5.3-codex",
        "openai",
        "gpt-5.3-codex",
        &[],
        Some(1.75),
        Some(14.0),
        Some(0.175),
        None,
        None,
        "https://developers.openai.com/api/docs/models/gpt-5.3-codex",
    )?;
    seed_price(
        conn,
        "openai:gpt-5.4-mini",
        "openai",
        "gpt-5.4-mini",
        &[],
        Some(0.75),
        Some(4.50),
        Some(0.075),
        None,
        None,
        "https://openai.com/api/pricing/",
    )?;
    seed_price(
        conn,
        "openai:gpt-5.1-mini",
        "openai",
        "gpt-5.1-mini",
        &["gpt-5.1-mini-codex"],
        Some(0.25),
        Some(2.0),
        Some(0.025),
        None,
        None,
        "https://openai.com/api/pricing/",
    )?;
    seed_price(
        conn,
        "anthropic:claude-sonnet-4.5",
        "anthropic",
        "claude-sonnet-4.5",
        &["claude-sonnet-4-5"],
        Some(3.0),
        Some(15.0),
        None,
        Some(6.0),
        Some(0.30),
        "https://docs.anthropic.com/en/docs/about-claude/pricing",
    )?;
    seed_price(
        conn,
        "anthropic:claude-sonnet-4-5-20250929",
        "anthropic",
        "claude-sonnet-4-5-20250929",
        &["claude-sonnet-4-5", "claude-sonnet-4.5"],
        Some(3.0),
        Some(15.0),
        None,
        Some(6.0),
        Some(0.30),
        "https://docs.anthropic.com/en/docs/about-claude/pricing",
    )?;
    seed_price(
        conn,
        "anthropic:claude-sonnet-4-6",
        "anthropic",
        "claude-sonnet-4-6",
        &["claude-sonnet-4.6"],
        Some(3.0),
        Some(15.0),
        None,
        Some(6.0),
        Some(0.30),
        "https://docs.anthropic.com/en/docs/about-claude/pricing",
    )?;
    seed_price(
        conn,
        "anthropic:claude-haiku-4-5-20251001",
        "anthropic",
        "claude-haiku-4-5-20251001",
        &["claude-haiku-4-5", "claude-haiku-4.5", "haiku"],
        Some(1.0),
        Some(5.0),
        None,
        Some(2.0),
        Some(0.10),
        "https://docs.anthropic.com/en/docs/about-claude/pricing",
    )?;
    for (id, model, aliases) in [
        (
            "anthropic:claude-opus-4-5-20251101",
            "claude-opus-4-5-20251101",
            &["claude-opus-4-5", "claude-opus-4.5"][..],
        ),
        (
            "anthropic:claude-opus-4-6",
            "claude-opus-4-6",
            &["claude-opus-4.6"][..],
        ),
        (
            "anthropic:claude-opus-4-7",
            "claude-opus-4-7",
            &["claude-opus-4.7"][..],
        ),
    ] {
        seed_price(
            conn,
            id,
            "anthropic",
            model,
            aliases,
            Some(5.0),
            Some(25.0),
            None,
            Some(10.0),
            Some(0.50),
            "https://docs.anthropic.com/en/docs/about-claude/pricing",
        )?;
    }
    seed_price(
        conn,
        "kimi:kimi-k2.6",
        "kimi",
        "kimi-k2.6",
        &[
            "kimi-k2.6:cloud",
            "kimi-for-coding",
            "kimi-code/kimi-for-coding",
            "Kimi-k2.6",
        ],
        Some(0.95),
        Some(4.0),
        Some(0.16),
        Some(0.95),
        Some(0.16),
        "https://www.kimi.com/resources/kimi-k2-6-pricing",
    )?;
    seed_price(
        conn,
        "google:gemini-2.5-pro",
        "google",
        "gemini-2.5-pro",
        &[],
        Some(1.25),
        Some(10.0),
        Some(0.31),
        None,
        None,
        "https://ai.google.dev/gemini-api/docs/pricing",
    )?;
    Ok(())
}

#[allow(clippy::too_many_arguments)]
fn seed_price(
    conn: &Connection,
    id: &str,
    provider_id: &str,
    model: &str,
    aliases: &[&str],
    input: Option<f64>,
    output: Option<f64>,
    cached: Option<f64>,
    cache_write: Option<f64>,
    cache_read: Option<f64>,
    source_url: &str,
) -> rusqlite::Result<()> {
    let now = now();
    conn.execute(
        "INSERT INTO pricing_catalogs
         (id, provider_id, model, aliases_json, source_url, catalog_version, effective_from,
          input_per_1m, output_per_1m, cached_input_per_1m, cache_write_per_1m, cache_read_per_1m,
          created_at, updated_at)
         VALUES (?1, ?2, ?3, ?4, ?5, 'seed-2026-05-08', '2026-05-08', ?6, ?7, ?8, ?9, ?10, ?11, ?11)
         ON CONFLICT(id) DO UPDATE SET
          provider_id = excluded.provider_id,
          model = excluded.model,
          aliases_json = excluded.aliases_json,
          source_url = excluded.source_url,
          catalog_version = excluded.catalog_version,
          effective_from = excluded.effective_from,
          currency = 'USD',
          input_per_1m = excluded.input_per_1m,
          output_per_1m = excluded.output_per_1m,
          cached_input_per_1m = excluded.cached_input_per_1m,
          cache_write_per_1m = excluded.cache_write_per_1m,
          cache_read_per_1m = excluded.cache_read_per_1m,
          user_override = 0,
          notes = NULL,
          updated_at = excluded.updated_at
         WHERE pricing_catalogs.user_override = 0
            OR pricing_catalogs.notes LIKE 'Detected locally%'
            OR pricing_catalogs.notes LIKE 'Available in local Codex model cache%'",
        params![
            id,
            provider_id,
            model,
            serde_json::to_string(aliases).unwrap(),
            source_url,
            input,
            output,
            cached,
            cache_write,
            cache_read,
            now
        ],
    )?;
    Ok(())
}

fn ensure_provider(conn: &Connection, id: &str, name: &str) -> rusqlite::Result<()> {
    let now = now();
    conn.execute(
        "INSERT OR IGNORE INTO providers (id, display_name, created_at, updated_at) VALUES (?1, ?2, ?3, ?3)",
        params![id, name, now],
    )?;
    Ok(())
}

struct CandidateSource {
    provider_id: &'static str,
    parser_id: &'static str,
    display_name: &'static str,
    path: PathBuf,
}

fn candidate_sources() -> Vec<CandidateSource> {
    let mut candidates = Vec::new();
    if let Some(home) = dirs::home_dir() {
        candidates.extend([
            CandidateSource {
                provider_id: "openai",
                parser_id: "codex",
                display_name: "Codex",
                path: home.join(".codex"),
            },
            CandidateSource {
                provider_id: "anthropic",
                parser_id: "claude",
                display_name: "Claude Code",
                path: home.join(".claude"),
            },
            CandidateSource {
                provider_id: "google",
                parser_id: "gemini",
                display_name: "Gemini",
                path: home.join(".gemini"),
            },
            CandidateSource {
                provider_id: "continue",
                parser_id: "continue",
                display_name: "Continue",
                path: home.join(".continue"),
            },
            CandidateSource {
                provider_id: "lmstudio",
                parser_id: "generic_json",
                display_name: "LM Studio",
                path: home.join(".lmstudio"),
            },
            CandidateSource {
                provider_id: "ollama",
                parser_id: "generic_jsonl",
                display_name: "Ollama",
                path: home.join(".ollama"),
            },
            CandidateSource {
                provider_id: "kimi",
                parser_id: "kimi",
                display_name: "Kimi Code",
                path: home.join(".kimi"),
            },
        ]);
    }
    if let Some(data) = dirs::data_dir() {
        candidates.push(CandidateSource {
            provider_id: "cursor",
            parser_id: "generic_jsonl",
            display_name: "Cursor",
            path: data.join("Cursor"),
        });
        candidates.push(CandidateSource {
            provider_id: "cline",
            parser_id: "generic_jsonl",
            display_name: "VS Code Cline/Roo",
            path: data.join("Code").join("User").join("globalStorage"),
        });
    }
    candidates
}

fn count_candidate_files(path: &Path) -> usize {
    WalkDir::new(path)
        .max_depth(5)
        .into_iter()
        .filter_map(Result::ok)
        .filter(|e| e.file_type().is_file())
        .filter(|e| is_candidate_file(e.path()))
        .take(501)
        .count()
}

fn is_candidate_file(path: &Path) -> bool {
    matches!(
        path.extension()
            .and_then(|e| e.to_str())
            .unwrap_or("")
            .to_ascii_lowercase()
            .as_str(),
        "json" | "jsonl" | "log" | "txt" | "md"
    )
}

fn infer_source(path: &Path) -> (String, String, String) {
    let text = path.to_string_lossy().to_ascii_lowercase();
    if text.contains(".claude") {
        ("anthropic".into(), "claude".into(), "Claude Code".into())
    } else if text.contains(".codex") {
        ("openai".into(), "codex".into(), "Codex".into())
    } else if text.contains(".gemini") {
        ("google".into(), "gemini".into(), "Gemini".into())
    } else if text.contains(".continue") {
        ("continue".into(), "generic_jsonl".into(), "Continue".into())
    } else if text.contains(".kimi") || text.contains("kimi-desktop") {
        ("kimi".into(), "kimi".into(), "Kimi Code".into())
    } else {
        (
            "generic".into(),
            "generic_jsonl".into(),
            "Generic JSONL".into(),
        )
    }
}

fn query_sources(conn: &Connection) -> Result<Vec<Source>, String> {
    let mut stmt = conn
        .prepare(
            "SELECT id, provider_id, parser_id, display_name, path, enabled, detection_confidence,
             last_scan_status, last_scan_message FROM log_sources ORDER BY display_name, path",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok(Source {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                parser_id: r.get(2)?,
                display_name: r.get(3)?,
                path: r.get(4)?,
                enabled: r.get::<_, i64>(5)? == 1,
                detection_confidence: r.get(6)?,
                last_scan_status: r.get(7)?,
                last_scan_message: r.get(8)?,
            })
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

fn query_source(conn: &Connection, id: &str) -> Result<Source, String> {
    conn.query_row(
        "SELECT id, provider_id, parser_id, display_name, path, enabled, detection_confidence,
         last_scan_status, last_scan_message FROM log_sources WHERE id = ?1",
        params![id],
        |r| {
            Ok(Source {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                parser_id: r.get(2)?,
                display_name: r.get(3)?,
                path: r.get(4)?,
                enabled: r.get::<_, i64>(5)? == 1,
                detection_confidence: r.get(6)?,
                last_scan_status: r.get(7)?,
                last_scan_message: r.get(8)?,
            })
        },
    )
    .map_err(to_string)
}

fn scan_source(conn: &Connection, source: &Source) -> Result<usize, String> {
    let started = now();
    conn.execute(
        "UPDATE log_sources SET last_scan_started_at = ?1, last_scan_status = 'scanning', last_scan_message = NULL WHERE id = ?2",
        params![started, source.id],
    )
    .map_err(to_string)?;
    let root = PathBuf::from(&source.path);
    if !root.exists() {
        conn.execute(
            "UPDATE log_sources SET last_scan_finished_at = ?1, last_scan_status = 'error', last_scan_message = 'Folder not found' WHERE id = ?2",
            params![now(), source.id],
        )
        .map_err(to_string)?;
        return Ok(0);
    }
    let mut imported = 0usize;
    for entry in WalkDir::new(&root)
        .max_depth(8)
        .into_iter()
        .filter_map(Result::ok)
    {
        if !entry.file_type().is_file() || !is_candidate_file(entry.path()) {
            continue;
        }
        let content = match fs::read_to_string(entry.path()) {
            Ok(content) => content,
            Err(_) => continue,
        };
        let modified = entry
            .metadata()
            .ok()
            .and_then(|m| m.modified().ok())
            .map(|t| chrono::DateTime::<Utc>::from(t).to_rfc3339())
            .unwrap_or_else(now);
        let source_hash = hash(&content);
        let events = parse_content(source, entry.path(), &content);
        for event in events {
            if insert_event(conn, source, entry.path(), &modified, &source_hash, event)? {
                imported += 1;
            }
        }
    }
    conn.execute(
        "UPDATE log_sources SET last_scan_finished_at = ?1, last_scan_status = 'ok', last_scan_message = ?2 WHERE id = ?3",
        params![now(), format!("Imported {imported} new events."), source.id],
    )
    .map_err(to_string)?;
    Ok(imported)
}

fn parse_content(source: &Source, path: &Path, content: &str) -> Vec<ParsedEvent> {
    let mut events = Vec::new();
    let mut offset = 0i64;
    let mut context_model: Option<String> = None;
    let mut context_cwd: Option<String> = None;
    let mut context_session: Option<String> = None;
    for line in content.lines() {
        let trimmed = line.trim();
        if trimmed.is_empty() {
            offset += line.len() as i64 + 1;
            continue;
        }
        if let Ok(value) = serde_json::from_str::<Value>(trimmed) {
            update_record_context(
                source,
                &value,
                &mut context_model,
                &mut context_cwd,
                &mut context_session,
            );
            if let Some(event) = parse_value(
                source,
                path,
                &value,
                Some(offset),
                trimmed,
                context_model.as_deref(),
                context_cwd.as_deref(),
                context_session.as_deref(),
            ) {
                events.push(event);
            }
        }
        offset += line.len() as i64 + 1;
    }
    if events.is_empty() {
        if let Ok(value) = serde_json::from_str::<Value>(content) {
            collect_json_events(source, path, &value, &mut events);
        }
    }
    events
}

fn collect_json_events(source: &Source, path: &Path, value: &Value, events: &mut Vec<ParsedEvent>) {
    if let Some(event) = parse_value(
        source,
        path,
        value,
        None,
        &value.to_string(),
        None,
        None,
        None,
    ) {
        events.push(event);
    }
    match value {
        Value::Array(items) => {
            for item in items {
                collect_json_events(source, path, item, events);
            }
        }
        Value::Object(map) => {
            for item in map.values() {
                if item.is_array() || item.is_object() {
                    collect_json_events(source, path, item, events);
                }
            }
        }
        _ => {}
    }
}

fn update_record_context(
    source: &Source,
    value: &Value,
    model: &mut Option<String>,
    cwd: &mut Option<String>,
    session: &mut Option<String>,
) {
    if let Some(payload) = value.get("payload") {
        if let Some(next_model) = str_field(payload, &["model"]) {
            *model = Some(next_model);
        }
        if let Some(next_cwd) = str_field(payload, &["cwd"]) {
            *cwd = Some(next_cwd);
        }
        if let Some(next_session) = str_field(payload, &["id", "session_id", "sessionId"]) {
            *session = Some(next_session);
        }
    }
    if let Some(next_model) = str_field(value, &["model", "model_name", "modelName"]) {
        *model = Some(next_model);
    }
    if let Some(next_cwd) = str_field(value, &["cwd", "working_directory", "workingDirectory"]) {
        *cwd = Some(next_cwd);
    }
    if source.provider_id == "kimi" {
        if let Some(next_cwd) = infer_project_from_value(value) {
            *cwd = Some(next_cwd);
        }
    }
    if let Some(next_session) = str_field(
        value,
        &[
            "session_id",
            "sessionId",
            "conversation_id",
            "conversationId",
        ],
    ) {
        *session = Some(next_session);
    }
}

fn usage_value(value: &Value) -> &Value {
    value
        .get("usage")
        .or_else(|| {
            value
                .get("message")
                .and_then(|message| message.get("usage"))
        })
        .or_else(|| {
            value
                .get("payload")
                .and_then(|payload| payload.get("info"))
                .and_then(|info| info.get("last_token_usage"))
        })
        .or_else(|| {
            value
                .get("payload")
                .and_then(|payload| payload.get("info"))
                .and_then(|info| info.get("total_token_usage"))
        })
        .or_else(|| {
            value
                .get("message")
                .and_then(|message| message.get("payload"))
                .and_then(|payload| payload.get("token_usage"))
        })
        .or_else(|| {
            value
                .get("payload")
                .and_then(|payload| payload.get("token_usage"))
        })
        .unwrap_or(value)
}

fn parse_value(
    source: &Source,
    path: &Path,
    value: &Value,
    source_offset: Option<i64>,
    raw: &str,
    context_model: Option<&str>,
    context_cwd: Option<&str>,
    context_session: Option<&str>,
) -> Option<ParsedEvent> {
    let usage = usage_value(value);
    let input = int_field(
        usage,
        &[
            "input_tokens",
            "prompt_tokens",
            "inputTokens",
            "promptTokens",
            "input_other",
            "prompt_eval_count",
            "prompt_eval_tokens",
            "num_prompt_tokens",
        ],
    );
    let output = int_field(
        usage,
        &[
            "output_tokens",
            "completion_tokens",
            "outputTokens",
            "completionTokens",
            "output",
            "eval_count",
            "eval_tokens",
            "num_completion_tokens",
        ],
    );
    let cached = int_field(
        usage,
        &["cached_input_tokens", "cached_tokens", "cachedInputTokens"],
    );
    let cache_write = int_field(
        usage,
        &[
            "cache_creation_input_tokens",
            "cache_write_tokens",
            "cacheWriteTokens",
            "input_cache_creation",
        ],
    );
    let cache_read = int_field(
        usage,
        &[
            "cache_read_input_tokens",
            "cache_read_tokens",
            "cacheReadTokens",
            "input_cache_read",
        ],
    );
    let reasoning = int_field(usage, &["reasoning_tokens", "reasoningTokens"]);
    let reasoning =
        reasoning + int_field(usage, &["reasoning_output_tokens", "reasoningOutputTokens"]);
    let tool = int_field(usage, &["tool_tokens", "toolTokens"]);
    let total = int_field(usage, &["total_tokens", "totalTokens"]);
    let known = input + output + cached + cache_write + cache_read + reasoning + tool;
    let unknown = if known == 0 { total } else { 0 };
    if known == 0 && unknown == 0 {
        return None;
    }
    let timestamp = timestamp_field(
        value,
        &["timestamp", "created_at", "createdAt", "time", "date"],
    )
    .or_else(|| timestamp_field(usage, &["timestamp", "created_at"]))
    .unwrap_or_else(now);
    let model = str_field(value, &["model", "model_name", "modelName"])
        .or_else(|| str_field(usage, &["model"]))
        .or_else(|| str_field(value.get("message").unwrap_or(&Value::Null), &["model"]))
        .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["model"]))
        .or_else(|| context_model.map(str::to_string))
        .or_else(|| default_model_for_source(source, value, usage));
    let project_path = str_field(
        value,
        &[
            "cwd",
            "working_directory",
            "workingDirectory",
            "project_path",
            "projectPath",
        ],
    )
    .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["cwd"]))
    .or_else(|| {
        if source.provider_id == "kimi" {
            infer_project_from_value(value)
        } else {
            None
        }
    })
    .or_else(|| context_cwd.map(str::to_string))
    .or_else(|| infer_project_from_path(path));
    Some(ParsedEvent {
        provider_id: source.provider_id.clone(),
        product_id: None,
        timestamp,
        project_path,
        conversation_id: str_field(
            value,
            &[
                "conversation_id",
                "conversationId",
                "session_id",
                "sessionId",
                "chat_id",
            ],
        )
        .or_else(|| {
            str_field(
                value.get("payload").unwrap_or(&Value::Null),
                &["id", "session_id", "sessionId"],
            )
        })
        .or_else(|| context_session.map(str::to_string)),
        message_id: str_field(value, &["message_id", "messageId", "id"]),
        request_id: str_field(value, &["request_id", "requestId"]),
        model,
        input_tokens: input,
        output_tokens: output,
        cached_input_tokens: cached,
        cache_write_tokens: cache_write,
        cache_read_tokens: cache_read,
        reasoning_tokens: reasoning,
        tool_tokens: tool,
        unknown_tokens: unknown,
        source_offset,
        raw_record_hash: hash(raw),
        confidence: if unknown > 0 { "low" } else { "medium" }.to_string(),
        warnings: if unknown > 0 {
            vec!["Only total tokens were available.".to_string()]
        } else {
            vec![]
        },
    })
}

fn insert_event(
    conn: &Connection,
    source: &Source,
    file_path: &Path,
    modified: &str,
    source_hash: &str,
    event: ParsedEvent,
) -> Result<bool, String> {
    let project_id = match &event.project_path {
        Some(path) => Some(upsert_project(
            conn,
            &event.provider_id,
            path,
            &event.timestamp,
        )?),
        None => None,
    };
    let conversation_id = upsert_conversation(conn, &event, project_id.as_deref())?;
    let (cost, pricing_id, pricing_match) = price_event(conn, &event)?;
    let id = hash(&format!(
        "{}|{}|{:?}|{:?}|{:?}|{}",
        source.provider_id,
        file_path.to_string_lossy(),
        event.source_offset,
        event.request_id,
        event.message_id,
        event.raw_record_hash
    ));
    let now = now();
    let changed = conn
        .execute(
            "INSERT OR IGNORE INTO usage_events
            (id, provider_id, product_id, source_id, parser_id, parser_version, timestamp, project_id, conversation_id,
             message_id, request_id, model, input_tokens, output_tokens, cached_input_tokens, cache_write_tokens,
             cache_read_tokens, reasoning_tokens, tool_tokens, unknown_tokens, official_api_cost_usd, pricing_catalog_id,
             pricing_match_confidence, source_file_path, source_file_modified_at, source_offset, source_hash,
             raw_record_hash, confidence, warnings_json, created_at, updated_at)
             VALUES (?1, ?2, ?3, ?4, ?5, '0.1.0', ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17,
             ?18, ?19, ?20, ?21, ?22, ?23, ?24, ?25, ?26, ?27, ?28, ?29, ?30, ?30)",
            params![
                id,
                event.provider_id,
                event.product_id,
                source.id,
                source.parser_id,
                event.timestamp,
                project_id,
                conversation_id,
                event.message_id,
                event.request_id,
                event.model,
                event.input_tokens,
                event.output_tokens,
                event.cached_input_tokens,
                event.cache_write_tokens,
                event.cache_read_tokens,
                event.reasoning_tokens,
                event.tool_tokens,
                event.unknown_tokens,
                cost,
                pricing_id,
                pricing_match,
                file_path.to_string_lossy(),
                modified,
                event.source_offset,
                source_hash,
                event.raw_record_hash,
                event.confidence,
                serde_json::to_string(&event.warnings).unwrap_or_else(|_| "[]".into()),
                now
            ],
        )
        .map_err(to_string)?;
    Ok(changed > 0)
}

fn price_event(
    conn: &Connection,
    event: &ParsedEvent,
) -> Result<(Option<f64>, Option<String>, String), String> {
    let pricing = find_pricing(conn, &event.provider_id, event.model.as_deref())?;
    if let Some(p) = pricing {
        if pricing_covers_event(event, &p) {
            Ok((
                Some(calculate_cost(event, &p)),
                Some(p.id),
                "exact".to_string(),
            ))
        } else {
            Ok((None, Some(p.id), "missing_price".to_string()))
        }
    } else {
        Ok((None, None, "missing".to_string()))
    }
}

fn pricing_covers_event(event: &ParsedEvent, pricing: &Pricing) -> bool {
    if event.unknown_tokens > 0 {
        return false;
    }
    if event.input_tokens > 0 && pricing.input_per_1m.is_none() {
        return false;
    }
    if event.output_tokens > 0 && pricing.output_per_1m.is_none() {
        return false;
    }
    if event.cached_input_tokens > 0
        && pricing
            .cached_input_per_1m
            .or(pricing.input_per_1m)
            .is_none()
    {
        return false;
    }
    if event.cache_write_tokens > 0
        && pricing
            .cache_write_per_1m
            .or(pricing.input_per_1m)
            .is_none()
    {
        return false;
    }
    if event.cache_read_tokens > 0
        && pricing
            .cache_read_per_1m
            .or(pricing.cached_input_per_1m)
            .is_none()
    {
        return false;
    }
    if event.reasoning_tokens > 0 && pricing.reasoning_per_1m.or(pricing.output_per_1m).is_none() {
        return false;
    }
    if event.tool_tokens > 0 && pricing.tool_per_1m.or(pricing.input_per_1m).is_none() {
        return false;
    }
    true
}

fn reprice_usage_events(conn: &Connection) -> rusqlite::Result<()> {
    #[derive(Clone)]
    struct EventForPricing {
        id: String,
        provider_id: String,
        model: Option<String>,
        input_tokens: i64,
        output_tokens: i64,
        cached_input_tokens: i64,
        cache_write_tokens: i64,
        cache_read_tokens: i64,
        reasoning_tokens: i64,
        tool_tokens: i64,
        unknown_tokens: i64,
    }

    let events = {
        let mut stmt = conn.prepare(
            "SELECT id, provider_id, model, input_tokens, output_tokens, cached_input_tokens,
             cache_write_tokens, cache_read_tokens, reasoning_tokens, tool_tokens, unknown_tokens
             FROM usage_events",
        )?;
        let rows = stmt.query_map([], |r| {
            Ok(EventForPricing {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                model: r.get(2)?,
                input_tokens: r.get(3)?,
                output_tokens: r.get(4)?,
                cached_input_tokens: r.get(5)?,
                cache_write_tokens: r.get(6)?,
                cache_read_tokens: r.get(7)?,
                reasoning_tokens: r.get(8)?,
                tool_tokens: r.get(9)?,
                unknown_tokens: r.get(10)?,
            })
        })?;
        rows.collect::<Result<Vec<_>, _>>()?
    };

    let updated_at = now();
    for event in events {
        let parsed = ParsedEvent {
            provider_id: event.provider_id,
            product_id: None,
            timestamp: updated_at.clone(),
            project_path: None,
            conversation_id: None,
            message_id: None,
            request_id: None,
            model: event.model,
            input_tokens: event.input_tokens,
            output_tokens: event.output_tokens,
            cached_input_tokens: event.cached_input_tokens,
            cache_write_tokens: event.cache_write_tokens,
            cache_read_tokens: event.cache_read_tokens,
            reasoning_tokens: event.reasoning_tokens,
            tool_tokens: event.tool_tokens,
            unknown_tokens: event.unknown_tokens,
            source_offset: None,
            raw_record_hash: String::new(),
            confidence: String::new(),
            warnings: vec![],
        };
        let (cost, pricing_id, pricing_match) = price_event(conn, &parsed).map_err(|e| {
            rusqlite::Error::ToSqlConversionFailure(Box::new(std::io::Error::new(
                std::io::ErrorKind::Other,
                e,
            )))
        })?;
        conn.execute(
            "UPDATE usage_events
             SET official_api_cost_usd = ?1, pricing_catalog_id = ?2, pricing_match_confidence = ?3, updated_at = ?4
             WHERE id = ?5",
            params![cost, pricing_id, pricing_match, updated_at, event.id],
        )?;
    }
    Ok(())
}

fn upsert_project(
    conn: &Connection,
    provider_id: &str,
    path: &str,
    timestamp: &str,
) -> Result<String, String> {
    let id = hash(&format!("{provider_id}|{}", path.to_ascii_lowercase()));
    let display = Path::new(path)
        .file_name()
        .and_then(|s| s.to_str())
        .filter(|s| !s.is_empty())
        .unwrap_or(path)
        .to_string();
    let now = now();
    conn.execute(
        "INSERT INTO projects (id, provider_id, display_name, path, normalized_path_hash, first_seen_at, last_seen_at, created_at, updated_at)
         VALUES (?1, ?2, ?3, ?4, ?1, ?5, ?5, ?6, ?6)
         ON CONFLICT(id) DO UPDATE SET last_seen_at = MAX(last_seen_at, excluded.last_seen_at), updated_at = excluded.updated_at",
        params![id, provider_id, display, path, timestamp, now],
    )
    .map_err(to_string)?;
    Ok(id)
}

fn upsert_conversation(
    conn: &Connection,
    event: &ParsedEvent,
    project_id: Option<&str>,
) -> Result<Option<String>, String> {
    let external = event.conversation_id.clone().unwrap_or_else(|| {
        hash(&format!(
            "{}|{}|{:?}",
            event.provider_id, event.timestamp, event.source_offset
        ))
    });
    let id = hash(&format!("{}|{}", event.provider_id, external));
    let now = now();
    conn.execute(
        "INSERT INTO conversations (id, provider_id, project_id, external_conversation_id, display_name, first_seen_at, last_seen_at, created_at, updated_at)
         VALUES (?1, ?2, ?3, ?4, ?4, ?5, ?5, ?6, ?6)
         ON CONFLICT(id) DO UPDATE SET last_seen_at = MAX(last_seen_at, excluded.last_seen_at), updated_at = excluded.updated_at",
        params![id, event.provider_id, project_id, external, event.timestamp, now],
    )
    .map_err(to_string)?;
    Ok(Some(id))
}

fn find_pricing(
    conn: &Connection,
    provider_id: &str,
    model: Option<&str>,
) -> Result<Option<Pricing>, String> {
    let Some(model) = model else {
        return Ok(None);
    };
    let exact = conn
        .query_row(
            "SELECT id, input_per_1m, output_per_1m, cached_input_per_1m, cache_write_per_1m, cache_read_per_1m, reasoning_per_1m, tool_per_1m
             FROM pricing_catalogs WHERE provider_id = ?1 AND lower(model) = lower(?2) LIMIT 1",
            params![provider_id, model],
            row_to_pricing,
        )
        .optional()
        .map_err(to_string)?;
    if exact.is_some() {
        return Ok(exact);
    }
    let mut stmt = conn
        .prepare(
            "SELECT id, input_per_1m, output_per_1m, cached_input_per_1m, cache_write_per_1m, cache_read_per_1m, reasoning_per_1m, tool_per_1m, aliases_json
             FROM pricing_catalogs WHERE provider_id = ?1",
        )
        .map_err(to_string)?;
    let mut rows = stmt.query(params![provider_id]).map_err(to_string)?;
    while let Some(row) = rows.next().map_err(to_string)? {
        let aliases_json: String = row.get(8).map_err(to_string)?;
        let aliases: Vec<String> = serde_json::from_str(&aliases_json).unwrap_or_default();
        if aliases.iter().any(|a| a.eq_ignore_ascii_case(model)) {
            return Ok(Some(Pricing {
                id: row.get(0).map_err(to_string)?,
                input_per_1m: row.get(1).map_err(to_string)?,
                output_per_1m: row.get(2).map_err(to_string)?,
                cached_input_per_1m: row.get(3).map_err(to_string)?,
                cache_write_per_1m: row.get(4).map_err(to_string)?,
                cache_read_per_1m: row.get(5).map_err(to_string)?,
                reasoning_per_1m: row.get(6).map_err(to_string)?,
                tool_per_1m: row.get(7).map_err(to_string)?,
            }));
        }
    }
    Ok(None)
}

fn row_to_pricing(row: &rusqlite::Row<'_>) -> rusqlite::Result<Pricing> {
    Ok(Pricing {
        id: row.get(0)?,
        input_per_1m: row.get(1)?,
        output_per_1m: row.get(2)?,
        cached_input_per_1m: row.get(3)?,
        cache_write_per_1m: row.get(4)?,
        cache_read_per_1m: row.get(5)?,
        reasoning_per_1m: row.get(6)?,
        tool_per_1m: row.get(7)?,
    })
}

fn calculate_cost(event: &ParsedEvent, pricing: &Pricing) -> f64 {
    let million = 1_000_000.0;
    (event.input_tokens as f64 / million) * pricing.input_per_1m.unwrap_or(0.0)
        + (event.output_tokens as f64 / million) * pricing.output_per_1m.unwrap_or(0.0)
        + (event.cached_input_tokens as f64 / million)
            * pricing
                .cached_input_per_1m
                .unwrap_or(pricing.input_per_1m.unwrap_or(0.0))
        + (event.cache_write_tokens as f64 / million)
            * pricing
                .cache_write_per_1m
                .unwrap_or(pricing.input_per_1m.unwrap_or(0.0))
        + (event.cache_read_tokens as f64 / million)
            * pricing
                .cache_read_per_1m
                .unwrap_or(pricing.cached_input_per_1m.unwrap_or(0.0))
        + (event.reasoning_tokens as f64 / million)
            * pricing
                .reasoning_per_1m
                .unwrap_or(pricing.output_per_1m.unwrap_or(0.0))
        + (event.tool_tokens as f64 / million)
            * pricing
                .tool_per_1m
                .unwrap_or(pricing.input_per_1m.unwrap_or(0.0))
}

fn query_provider_summaries(conn: &Connection) -> Result<Vec<ProviderSummary>, String> {
    let mut stmt = conn
        .prepare(
            "SELECT p.id, p.display_name,
              COALESCE(SUM(u.input_tokens),0), COALESCE(SUM(u.output_tokens),0), COALESCE(SUM(u.cached_input_tokens),0),
              COALESCE(SUM(u.cache_write_tokens),0), COALESCE(SUM(u.cache_read_tokens),0), COALESCE(SUM(u.reasoning_tokens),0),
              COALESCE(SUM(u.tool_tokens),0), COALESCE(SUM(u.unknown_tokens),0), COALESCE(SUM(u.official_api_cost_usd),0),
              (SELECT COALESCE(SUM(monthly_amount),0) FROM subscriptions s WHERE s.provider_id = p.id AND s.enabled = 1),
              (SELECT COUNT(*) FROM log_sources ls WHERE ls.provider_id = p.id),
              MAX(u.timestamp)
             FROM providers p
             LEFT JOIN usage_events u ON u.provider_id = p.id
             WHERE EXISTS (SELECT 1 FROM log_sources ls WHERE ls.provider_id = p.id)
                OR EXISTS (SELECT 1 FROM usage_events ue WHERE ue.provider_id = p.id)
                OR EXISTS (SELECT 1 FROM subscriptions s WHERE s.provider_id = p.id)
             GROUP BY p.id, p.display_name
             ORDER BY p.display_name",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            let totals = UsageTotals {
                input_tokens: r.get(2)?,
                output_tokens: r.get(3)?,
                cached_input_tokens: r.get(4)?,
                cache_write_tokens: r.get(5)?,
                cache_read_tokens: r.get(6)?,
                reasoning_tokens: r.get(7)?,
                tool_tokens: r.get(8)?,
                unknown_tokens: r.get(9)?,
                total_tokens: 0,
            }
            .with_total();
            let api = r.get::<_, f64>(10)?;
            let sub = r.get::<_, f64>(11)?;
            Ok(ProviderSummary {
                provider_id: r.get(0)?,
                display_name: r.get(1)?,
                totals,
                api_equivalent_cost: api,
                subscription_amount: sub,
                net_savings_vs_api: api - sub,
                source_count: r.get(12)?,
                last_seen: r.get(13)?,
            })
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

fn query_top_projects(conn: &Connection) -> Result<Vec<ProjectSummary>, String> {
    let mut stmt = conn
        .prepare(
            "SELECT pr.id, COALESCE(pr.provider_id, u.provider_id), pr.display_name, pr.path,
              COALESCE(SUM(u.input_tokens),0), COALESCE(SUM(u.output_tokens),0), COALESCE(SUM(u.cached_input_tokens),0),
              COALESCE(SUM(u.cache_write_tokens),0), COALESCE(SUM(u.cache_read_tokens),0), COALESCE(SUM(u.reasoning_tokens),0),
              COALESCE(SUM(u.tool_tokens),0), COALESCE(SUM(u.unknown_tokens),0), COALESCE(SUM(u.official_api_cost_usd),0),
              MAX(u.timestamp)
             FROM projects pr JOIN usage_events u ON u.project_id = pr.id
             GROUP BY pr.id ORDER BY COALESCE(SUM(u.official_api_cost_usd),0) DESC, MAX(u.timestamp) DESC LIMIT 20",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok(ProjectSummary {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                display_name: r.get(2)?,
                path: r.get(3)?,
                totals: UsageTotals {
                    input_tokens: r.get(4)?,
                    output_tokens: r.get(5)?,
                    cached_input_tokens: r.get(6)?,
                    cache_write_tokens: r.get(7)?,
                    cache_read_tokens: r.get(8)?,
                    reasoning_tokens: r.get(9)?,
                    tool_tokens: r.get(10)?,
                    unknown_tokens: r.get(11)?,
                    total_tokens: 0,
                }
                .with_total(),
                api_equivalent_cost: r.get(12)?,
                last_seen: r.get(13)?,
            })
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

fn query_recent_sessions(conn: &Connection) -> Result<Vec<SessionSummary>, String> {
    let mut stmt = conn
        .prepare(
            "SELECT u.id, u.provider_id, pr.display_name, u.model, u.timestamp,
             (u.input_tokens + u.output_tokens + u.cached_input_tokens + u.cache_write_tokens + u.cache_read_tokens + u.reasoning_tokens + u.tool_tokens + u.unknown_tokens),
             u.official_api_cost_usd, u.confidence
             FROM usage_events u LEFT JOIN projects pr ON pr.id = u.project_id
             ORDER BY u.timestamp DESC LIMIT 30",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok(SessionSummary {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                project_name: r.get(2)?,
                model: r.get(3)?,
                timestamp: r.get(4)?,
                total_tokens: r.get(5)?,
                api_equivalent_cost: r.get(6)?,
                confidence: r.get(7)?,
            })
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

fn sum_usage(providers: &[ProviderSummary]) -> UsageTotals {
    let mut totals = UsageTotals::default();
    for p in providers {
        totals.input_tokens += p.totals.input_tokens;
        totals.output_tokens += p.totals.output_tokens;
        totals.cached_input_tokens += p.totals.cached_input_tokens;
        totals.cache_write_tokens += p.totals.cache_write_tokens;
        totals.cache_read_tokens += p.totals.cache_read_tokens;
        totals.reasoning_tokens += p.totals.reasoning_tokens;
        totals.tool_tokens += p.totals.tool_tokens;
        totals.unknown_tokens += p.totals.unknown_tokens;
    }
    totals.with_total()
}

impl UsageTotals {
    fn with_total(mut self) -> Self {
        self.total_tokens = self.input_tokens
            + self.output_tokens
            + self.cached_input_tokens
            + self.cache_write_tokens
            + self.cache_read_tokens
            + self.reasoning_tokens
            + self.tool_tokens
            + self.unknown_tokens;
        self
    }
}

fn int_field(value: &Value, names: &[&str]) -> i64 {
    for name in names {
        if let Some(number) = value.get(*name).and_then(|v| v.as_i64()) {
            return number;
        }
        if let Some(number) = value.get(*name).and_then(|v| v.as_u64()) {
            return number as i64;
        }
    }
    0
}

fn timestamp_field(value: &Value, names: &[&str]) -> Option<String> {
    for name in names {
        let Some(field) = value.get(*name) else {
            continue;
        };
        if let Some(text) = field.as_str() {
            return Some(text.to_string());
        }
        if let Some(seconds) = field.as_i64() {
            return unix_timestamp_to_rfc3339(seconds as f64);
        }
        if let Some(seconds) = field.as_u64() {
            return unix_timestamp_to_rfc3339(seconds as f64);
        }
        if let Some(seconds) = field.as_f64() {
            return unix_timestamp_to_rfc3339(seconds);
        }
    }
    None
}

fn unix_timestamp_to_rfc3339(value: f64) -> Option<String> {
    if !value.is_finite() || value <= 0.0 {
        return None;
    }
    let seconds = if value > 10_000_000_000.0 {
        value / 1000.0
    } else {
        value
    };
    let whole = seconds.trunc() as i64;
    let nanos = ((seconds.fract() * 1_000_000_000.0).round() as u32).min(999_999_999);
    Utc.timestamp_opt(whole, nanos)
        .single()
        .map(|dt| dt.to_rfc3339())
}

fn str_field(value: &Value, names: &[&str]) -> Option<String> {
    for name in names {
        if let Some(text) = value.get(*name).and_then(|v| v.as_str()) {
            return Some(text.to_string());
        }
    }
    None
}

fn default_model_for_source(source: &Source, _value: &Value, _usage: &Value) -> Option<String> {
    match source.provider_id.as_str() {
        "kimi" => Some("kimi-for-coding".to_string()),
        _ => None,
    }
}

fn infer_project_from_value(value: &Value) -> Option<String> {
    let mut candidates = Vec::new();
    collect_project_candidates(value, &mut candidates);
    candidates.into_iter().next()
}

fn collect_project_candidates(value: &Value, candidates: &mut Vec<String>) {
    match value {
        Value::String(text) => {
            for path in absolute_paths_in_text(text) {
                if let Some(project) = project_root_from_path(Path::new(&path)) {
                    if !candidates.iter().any(|c| c == &project) {
                        candidates.push(project);
                    }
                }
            }
        }
        Value::Array(items) => {
            for item in items {
                collect_project_candidates(item, candidates);
            }
        }
        Value::Object(map) => {
            for item in map.values() {
                collect_project_candidates(item, candidates);
            }
        }
        _ => {}
    }
}

fn absolute_paths_in_text(text: &str) -> Vec<String> {
    let mut paths = Vec::new();
    let mut start = 0;
    while let Some(relative) = text[start..].find("/Users/") {
        let absolute_start = start + relative;
        let mut absolute_end = text.len();
        for (index, ch) in text[absolute_start..].char_indices() {
            if matches!(
                ch,
                '"' | '\'' | '\n' | '\r' | '\t' | '<' | '>' | '[' | ']' | '{' | '}'
            ) {
                absolute_end = absolute_start + index;
                break;
            }
        }
        let path = text[absolute_start..absolute_end]
            .trim_end_matches(|c: char| matches!(c, ',' | ':' | ';' | ')' | '.'))
            .to_string();
        if !path.is_empty() {
            paths.push(path);
        }
        start = absolute_end.saturating_add(1);
        if start >= text.len() {
            break;
        }
    }
    paths
}

fn infer_project_from_path(path: &Path) -> Option<String> {
    if let Some(project) = project_root_from_path(path) {
        return Some(project);
    }
    let parts: Vec<_> = path
        .components()
        .map(|c| c.as_os_str().to_string_lossy().to_string())
        .collect();
    for marker in ["projects", "project", "workspaces", "workspace"] {
        if let Some(index) = parts.iter().position(|p| p.eq_ignore_ascii_case(marker)) {
            if let Some(name) = parts.get(index + 1) {
                return Some(name.clone());
            }
        }
    }
    path.parent()
        .and_then(|p| p.to_str())
        .map(|s| s.to_string())
}

fn project_root_from_path(path: &Path) -> Option<String> {
    let parts: Vec<_> = path
        .components()
        .map(|c| c.as_os_str().to_string_lossy().to_string())
        .collect();
    if parts.iter().any(|p| {
        p.eq_ignore_ascii_case(".kimi")
            || p.eq_ignore_ascii_case(".codex")
            || p.eq_ignore_ascii_case(".claude")
    }) {
        return None;
    }
    for marker in [
        "Developer",
        "iDeveloper",
        "projects",
        "project",
        "workspaces",
        "workspace",
    ] {
        if let Some(index) = parts.iter().position(|p| p.eq_ignore_ascii_case(marker)) {
            if parts.get(index + 1).is_some() {
                return Some(join_path_parts(&parts[..=index + 1]));
            }
        }
    }
    if parts.len() >= 4 && parts[1] == "Users" {
        let first = &parts[3];
        if !first.starts_with('.') && first != "Library" && first != "Downloads" {
            return Some(join_path_parts(&parts[..=3]));
        }
    }
    None
}

fn join_path_parts(parts: &[String]) -> String {
    if parts.first().is_some_and(|p| p == "/") {
        format!("/{}", parts[1..].join("/"))
    } else {
        parts.join("/")
    }
}

fn provider_display_name(provider_id: &str) -> &str {
    match provider_id {
        "openai" => "OpenAI / Codex",
        "anthropic" => "Claude",
        "cursor" => "Cursor",
        "google" => "Gemini",
        "cline" => "Cline / Roo Code",
        "continue" => "Continue",
        "aider" => "Aider",
        "lmstudio" => "LM Studio",
        "ollama" => "Ollama",
        "cloudflare" => "Cloudflare Workers AI",
        "kimi" => "Kimi / Moonshot",
        _ => "Generic JSONL",
    }
}

#[allow(dead_code)]
fn current_cycle(anchor_day: u32) -> (String, String) {
    let today = Local::now().date_naive();
    let start = cycle_date(today.year(), today.month(), anchor_day);
    let start = if today < start {
        let (year, month) = previous_month(today.year(), today.month());
        cycle_date(year, month, anchor_day)
    } else {
        start
    };
    let (next_year, next_month) = next_month(start.year(), start.month());
    let end = cycle_date(next_year, next_month, anchor_day);
    (
        Local
            .from_local_datetime(&start.and_hms_opt(0, 0, 0).unwrap())
            .unwrap()
            .to_rfc3339(),
        Local
            .from_local_datetime(&end.and_hms_opt(0, 0, 0).unwrap())
            .unwrap()
            .to_rfc3339(),
    )
}

fn cycle_date(year: i32, month: u32, anchor_day: u32) -> NaiveDate {
    let last = last_day_of_month(year, month);
    NaiveDate::from_ymd_opt(year, month, anchor_day.min(last)).unwrap()
}

fn last_day_of_month(year: i32, month: u32) -> u32 {
    let (ny, nm) = next_month(year, month);
    (NaiveDate::from_ymd_opt(ny, nm, 1).unwrap() - chrono::Duration::days(1)).day()
}

fn next_month(year: i32, month: u32) -> (i32, u32) {
    if month == 12 {
        (year + 1, 1)
    } else {
        (year, month + 1)
    }
}

fn previous_month(year: i32, month: u32) -> (i32, u32) {
    if month == 1 {
        (year - 1, 12)
    } else {
        (year, month - 1)
    }
}

fn hash(input: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(input.as_bytes());
    format!("{:x}", hasher.finalize())
}

fn now() -> String {
    Utc::now().to_rfc3339()
}

fn to_string<E: std::fmt::Display>(err: E) -> String {
    err.to_string()
}
