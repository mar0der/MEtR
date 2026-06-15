use chrono::{Datelike, Local, NaiveDate, TimeZone, Utc};
use rusqlite::{params, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::error::Error;
use std::fs;
use std::io::{BufRead, BufReader};
use std::path::{Path, PathBuf};
use std::sync::Arc;
use tauri::{Emitter, Manager, State};
use tokio::sync::Mutex;
use uuid::Uuid;
use walkdir::WalkDir;

const MAX_SCAN_FILES_PER_SOURCE: usize = 50_000;
const MAX_SOURCE_TOTAL_BYTES: u64 = 5 * 1024 * 1024 * 1024; // 5 GB
const MAX_LINE_LENGTH: usize = 1_048_576; // 1 MB
const MAX_JSON_DEPTH: usize = 64;
const PARSER_VERSION: &str = "0.1.9";
const KEYRING_SERVICE: &str = "com.petarpetkov.metr.sync";
const KEYRING_USERNAME: &str = "auth_token";
const OFFICIAL_SERVER_HOST: &str = "metr.petarpetkov.com";

struct AppState {
    db: Arc<Mutex<Connection>>,
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
struct SyncStatus {
    configured: bool,
    server_url: String,
    logged_in: bool,
    username: Option<String>,
    device_name: Option<String>,
    last_sync_at: Option<String>,
    last_sync_attempt_at: Option<String>,
    pending_events: i64,
    sync_error_count: i64,
    last_sync_error: Option<String>,
    sync_enabled: bool,
    project_root: Option<String>,
}

#[derive(Debug, Deserialize)]
struct LoginInput {
    login: String,
    password: String,
    server_url: String,
}

#[derive(Debug, Serialize)]
struct SyncResult {
    uploaded: usize,
    batches: usize,
    subscriptions_uploaded: usize,
    errors: Vec<String>,
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
    first_seen: Option<String>,
    last_seen: Option<String>,
}

#[derive(Debug, Serialize)]
struct SessionSummary {
    id: String,
    provider_id: String,
    project_name: Option<String>,
    project_path: Option<String>,
    model: Option<String>,
    event_type: Option<String>,
    timestamp: String,
    input_tokens: i64,
    effective_input_tokens: i64,
    output_tokens: i64,
    cached_tokens: i64,
    total_tokens: i64,
    input_cost: Option<f64>,
    output_cost: Option<f64>,
    cached_cost: Option<f64>,
    other_cost: Option<f64>,
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
    event_type: Option<String>,
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
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .setup(|app| {
            let db_path = app
                .path()
                .app_data_dir()
                .unwrap_or_else(|_| std::env::current_dir().unwrap().join(".metr-data"));
            fs::create_dir_all(&db_path)?;
            let conn = Connection::open(db_path.join("metr.db"))?;
            migrate(&conn)?;
            seed_defaults(&conn)?;
            app.manage(AppState {
                db: Arc::new(Mutex::new(conn)),
            });
            // Run expensive maintenance in background so UI loads instantly
            let maint_db_path = db_path.join("metr.db");
            tauri::async_runtime::spawn_blocking(move || {
                if let Ok(conn) = Connection::open(&maint_db_path) {
                    let _ = cleanup_known_bad_imports(&conn);
                    let _ = recalculate_event_costs(&conn);
                }
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
            rescan_all_full,
            rescan_source,
            get_dashboard_summary,
            get_recent_sessions,
            list_subscriptions,
            create_subscription,
            delete_subscription,
            list_pricing_catalog,
            clear_parsed_data,
            list_projects,
            rename_project,
            merge_projects,
            unmerge_project,
            open_project_path,
            list_missing_models,
            add_pricing,
            pull_pricing,
            push_pricing,
            get_sync_status,
            configure_sync_server,
            get_project_root,
            set_project_root,
            rebuild_projects,
            login_sync,
            logout_sync,
            sync_now,
            full_resync,
            debug_sync_state
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
    let conn = state.db.blocking_lock();
    query_sources(&conn)
}

#[tauri::command]
fn add_source(state: State<AppState>, input: AddSourceInput) -> Result<Source, String> {
    let path = validate_source_path(&input.path)?;
    let input = AddSourceInput {
        path: path.to_string_lossy().to_string(),
        ..input
    };
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
    let conn = state.db.blocking_lock();
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
    let conn = state.db.blocking_lock();
    conn.execute("DELETE FROM log_sources WHERE id = ?1", params![source_id])
        .map_err(to_string)?;
    Ok(())
}

#[tauri::command]
fn clear_parsed_data(state: State<AppState>) -> Result<(), String> {
    let conn = state.db.blocking_lock();
    conn.execute_batch(
        "
        DELETE FROM usage_events;
        DELETE FROM conversations;
        DELETE FROM projects;
        DELETE FROM indexed_files;
        UPDATE log_sources
        SET last_scan_started_at = NULL,
            last_scan_finished_at = NULL,
            last_scan_status = NULL,
            last_scan_message = 'Parsed data cleared. Run Rescan to rebuild.';
        ",
    )
    .map_err(to_string)?;
    Ok(())
}

#[tauri::command]
fn list_projects(state: State<AppState>) -> Result<Value, String> {
    let conn = state.db.blocking_lock();
    let mut stmt = conn
        .prepare(
            "SELECT p.id, p.provider_id, p.display_name, p.path, pm.custom_name, pm.merged_into_project_id
             FROM projects p
             LEFT JOIN project_management pm ON pm.id = p.id
             ORDER BY COALESCE(pm.custom_name, p.display_name) ASC, p.path ASC",
        )
        .map_err(to_string)?;
    let rows: Vec<Value> = stmt
        .query_map([], |r| {
            let id: String = r.get(0)?;
            let provider_id: String = r.get(1)?;
            let display_name: String = r.get(2)?;
            let path: Option<String> = r.get(3)?;
            let custom_name: Option<String> = r.get(4)?;
            let merged_into: Option<String> = r.get(5)?;
            Ok(serde_json::json!({
                "id": id,
                "provider_id": provider_id,
                "display_name": display_name,
                "path": path,
                "custom_name": custom_name,
                "merged_into_project_id": merged_into,
                "effective_name": custom_name.as_deref().unwrap_or(&display_name),
            }))
        })
        .map_err(to_string)?
        .collect::<Result<Vec<_>, _>>()
        .map_err(to_string)?;
    Ok(serde_json::json!({ "projects": rows }))
}

#[tauri::command]
fn rename_project(state: State<AppState>, project_id: String, custom_name: Option<String>) -> Result<Value, String> {
    {
        let conn = state.db.blocking_lock();
        let provider_id: String = conn
            .query_row("SELECT provider_id FROM projects WHERE id = ?1", params![project_id], |r| r.get(0))
            .map_err(|_| "Project not found")?;
        let now = now();
        if let Some(name) = custom_name.as_deref().filter(|s| !s.trim().is_empty()) {
            conn.execute(
                "INSERT INTO project_management (id, provider_id, custom_name, created_at, updated_at)
                 VALUES (?1, ?2, ?3, ?4, ?4)
                 ON CONFLICT(id) DO UPDATE SET custom_name = excluded.custom_name, updated_at = excluded.updated_at",
                params![project_id, provider_id, name.trim(), now],
            )
            .map_err(to_string)?;
        } else {
            conn.execute(
                "UPDATE project_management SET custom_name = NULL, updated_at = ?1 WHERE id = ?2",
                params![now, project_id],
            )
            .map_err(to_string)?;
        }
        // Apply the new name to the projects table so existing queries see it immediately.
        apply_project_management(&conn).map_err(to_string)?;
    }
    list_projects(state)
}

#[tauri::command]
fn merge_projects(state: State<AppState>, target_project_id: String, source_project_ids: Vec<String>) -> Result<Value, String> {
    {
        let mut conn = state.db.blocking_lock();
        let tx = conn.transaction().map_err(to_string)?;
        validate_merge_target(&tx, &target_project_id)?;
        let target_provider: String = tx
            .query_row("SELECT provider_id FROM projects WHERE id = ?1", params![&target_project_id], |r| r.get(0))
            .map_err(|_| "Target project not found".to_string())?;
        let now = now();
        for source_id in &source_project_ids {
            if source_id == &target_project_id {
                return Err("Cannot merge a project into itself.".to_string());
            }
            let source_provider: String = tx
                .query_row("SELECT provider_id FROM projects WHERE id = ?1", params![source_id], |r| r.get(0))
                .map_err(|_| format!("Source project {} not found", source_id))?;
            if source_provider != target_provider {
                return Err(format!(
                    "Project {} belongs to a different provider than the target project.",
                    source_id
                ));
            }
            tx.execute(
                "INSERT INTO project_management (id, provider_id, merged_into_project_id, created_at, updated_at)
                 VALUES (?1, ?2, ?3, ?4, ?4)
                 ON CONFLICT(id) DO UPDATE SET merged_into_project_id = excluded.merged_into_project_id, updated_at = excluded.updated_at",
                params![source_id, source_provider, target_project_id, now],
            )
            .map_err(to_string)?;
        }
        apply_project_management(&tx).map_err(to_string)?;
        tx.commit().map_err(to_string)?;
    }
    list_projects(state)
}

fn validate_merge_target(conn: &Connection, target_id: &str) -> Result<(), String> {
    let merged_into: Option<String> = conn
        .query_row(
            "SELECT merged_into_project_id FROM project_management WHERE id = ?1",
            params![target_id],
            |r| r.get(0),
        )
        .optional()
        .map_err(to_string)?;
    if merged_into.is_some() {
        return Err("Target project is itself merged into another project.".to_string());
    }
    Ok(())
}

#[tauri::command]
fn unmerge_project(state: State<AppState>, project_id: String) -> Result<Value, String> {
    {
        let mut conn = state.db.blocking_lock();
        let tx = conn.transaction().map_err(to_string)?;
        let target_id: Option<String> = tx
            .query_row(
                "SELECT merged_into_project_id FROM project_management WHERE id = ?1",
                params![&project_id],
                |r| r.get(0),
            )
            .optional()
            .map_err(to_string)?;
        tx.execute(
            "UPDATE project_management SET merged_into_project_id = NULL, updated_at = ?1 WHERE id = ?2",
            params![now(), project_id],
        )
        .map_err(to_string)?;
        // Restore events and conversations that were moved from this source project back to it.
        tx.execute(
            "UPDATE usage_events SET project_id = ?1, merged_from_project_id = NULL WHERE merged_from_project_id = ?1",
            params![&project_id],
        )
        .map_err(to_string)?;
        tx.execute(
            "UPDATE conversations SET project_id = ?1, merged_from_project_id = NULL WHERE merged_from_project_id = ?1",
            params![&project_id],
        )
        .map_err(to_string)?;
        // Ensure the source project row is restored if it was previously deleted.
        if let Some(target_id) = target_id {
            let _ = tx.execute(
                "INSERT OR IGNORE INTO projects (id, provider_id, display_name, path, normalized_path_hash, first_seen_at, last_seen_at, created_at, updated_at)
                 SELECT ?1, provider_id, display_name, path, normalized_path_hash, first_seen_at, last_seen_at, created_at, updated_at
                 FROM projects WHERE id = ?2",
                params![&project_id, target_id],
            );
        }
        apply_project_management(&tx).map_err(to_string)?;
        tx.commit().map_err(to_string)?;
    }
    list_projects(state)
}

#[tauri::command]
fn open_project_path(state: State<AppState>, path: String) -> Result<(), String> {
    let canonical = validate_project_path(&path, &state)?;
    opener::open(&canonical).map_err(|e| format!("Failed to open folder: {}", e))
}

fn validate_project_path(path: &str, state: &AppState) -> Result<PathBuf, String> {
    let trimmed = path.trim();
    if trimmed.is_empty() {
        return Err("Path is empty.".to_string());
    }
    if trimmed.starts_with('-') {
        return Err("Path cannot start with '-'.".to_string());
    }
    if trimmed.contains("://") || trimmed.starts_with("\\\\") {
        return Err("URL-like or network paths are not allowed.".to_string());
    }

    let expanded = expand_tilde(trimmed);
    let canonical = std::fs::canonicalize(&expanded)
        .map_err(|e| format!("Path does not exist: {}", e))?;
    if !canonical.is_dir() {
        return Err("Path is not a directory.".to_string());
    }

    let conn = state.db.blocking_lock();
    let project_root = project_root_from_conn(&conn);
    let home = dirs::home_dir();
    let allowed = match (project_root.as_deref(), home.as_deref()) {
        (Some(root), Some(h)) => canonical.starts_with(root) || canonical.starts_with(h),
        (Some(root), None) => canonical.starts_with(root),
        (None, Some(h)) => canonical.starts_with(h),
        (None, None) => false,
    };
    if !allowed {
        return Err(
            "Path is outside the configured project root or home directory.".to_string(),
        );
    }
    Ok(canonical)
}

#[tauri::command]
async fn rescan_all(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let sources = query_sources(&conn)?;
        let mut imported = 0usize;
        for source in sources.into_iter().filter(|s| s.enabled) {
            imported += scan_source(&conn, &source, false)?;
        }
        Ok::<_, String>(serde_json::json!({ "imported": imported }))
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
async fn rescan_all_full(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let sources = query_sources(&conn)?;
        let mut imported = 0usize;
        for source in sources.into_iter().filter(|s| s.enabled) {
            imported += scan_source(&conn, &source, true)?;
        }
        Ok::<_, String>(serde_json::json!({ "imported": imported }))
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
fn rescan_source(state: State<AppState>, source_id: String) -> Result<Value, String> {
    let conn = state.db.blocking_lock();
    let source = query_source(&conn, &source_id)?;
    let imported = scan_source(&conn, &source, false)?;
    Ok(serde_json::json!({ "imported": imported }))
}

#[tauri::command]
fn get_dashboard_summary(state: State<AppState>) -> Result<DashboardSummary, String> {
    let conn = state.db.blocking_lock();
    let providers = query_provider_summaries(&conn)?;
    let top_projects = query_top_projects(&conn)?;
    let (recent_sessions, _) = query_recent_sessions(&conn, None, 0, 30)?;
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

#[derive(Debug, Serialize)]
struct RecentSessionsResult {
    sessions: Vec<SessionSummary>,
    total_count: i64,
}

#[tauri::command]
fn get_recent_sessions(
    state: State<AppState>,
    provider_id: Option<String>,
    offset: usize,
    limit: usize,
) -> Result<RecentSessionsResult, String> {
    let conn = state.db.blocking_lock();
    let (sessions, total_count) = query_recent_sessions(&conn, provider_id.as_deref(), offset, limit)?;
    Ok(RecentSessionsResult { sessions, total_count })
}

#[tauri::command]
fn list_subscriptions(state: State<AppState>) -> Result<Vec<Subscription>, String> {
    let conn = state.db.blocking_lock();
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
    if input.monthly_amount <= 0.0 {
        return Err("Monthly amount must be greater than zero.".to_string());
    }
    if input.billing_anchor_day < 1 || input.billing_anchor_day > 28 {
        return Err("Billing anchor day must be between 1 and 28 (not all months have 29-31 days).".to_string());
    }
    let conn = state.db.blocking_lock();
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
    conn.query_row(
        "SELECT id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, enabled
         FROM subscriptions WHERE id = ?1",
        params![id],
        |r| {
            Ok(Subscription {
                id: r.get(0)?,
                provider_id: r.get(1)?,
                product_name: r.get(2)?,
                monthly_amount: r.get(3)?,
                currency: r.get(4)?,
                billing_anchor_day: r.get(5)?,
                enabled: r.get::<_, i64>(6)? == 1,
            })
        },
    )
    .map_err(to_string)
}

#[tauri::command]
fn delete_subscription(state: State<AppState>, id: String) -> Result<(), String> {
    let conn = state.db.blocking_lock();
    conn.execute("DELETE FROM subscriptions WHERE id = ?1", params![id])
        .map_err(to_string)?;
    Ok(())
}

#[tauri::command]
fn list_pricing_catalog(state: State<AppState>) -> Result<Vec<Value>, String> {
    let conn = state.db.blocking_lock();
    let mut stmt = conn
        .prepare(
            "SELECT id, provider_id, model, aliases_json, input_per_1m, output_per_1m,
             cached_input_per_1m, cache_write_per_1m, cache_read_per_1m, source_url
             FROM pricing_catalogs
             WHERE input_per_1m IS NOT NULL OR output_per_1m IS NOT NULL
                OR cached_input_per_1m IS NOT NULL OR cache_write_per_1m IS NOT NULL
                OR cache_read_per_1m IS NOT NULL OR reasoning_per_1m IS NOT NULL
                OR tool_per_1m IS NOT NULL OR user_override = 1
             ORDER BY provider_id, model",
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

#[derive(Debug, Deserialize)]
struct AddPricingInput {
    provider_id: String,
    model: String,
    input_per_1m: Option<f64>,
    output_per_1m: Option<f64>,
    cached_input_per_1m: Option<f64>,
    cache_write_per_1m: Option<f64>,
    cache_read_per_1m: Option<f64>,
    reasoning_per_1m: Option<f64>,
    tool_per_1m: Option<f64>,
    source_url: Option<String>,
}

#[tauri::command]
async fn add_pricing(state: State<'_, AppState>, input: AddPricingInput) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let id = format!("{}:{}", input.provider_id, input.model.to_ascii_lowercase());
        let now_ts = now();
        conn.execute(
            "INSERT INTO pricing_catalogs
             (id, provider_id, model, aliases_json, source_url, catalog_version, effective_from,
              input_per_1m, output_per_1m, cached_input_per_1m, cache_write_per_1m, cache_read_per_1m,
              reasoning_per_1m, tool_per_1m, user_override, created_at, updated_at)
             VALUES (?1, ?2, ?3, '[]', ?4, 'user', '2026-01-01', ?5, ?6, ?7, ?8, ?9, ?10, ?11, 1, ?12, ?12)
             ON CONFLICT(id) DO UPDATE SET
               input_per_1m = excluded.input_per_1m,
               output_per_1m = excluded.output_per_1m,
               cached_input_per_1m = excluded.cached_input_per_1m,
               cache_write_per_1m = excluded.cache_write_per_1m,
               cache_read_per_1m = excluded.cache_read_per_1m,
               reasoning_per_1m = excluded.reasoning_per_1m,
               tool_per_1m = excluded.tool_per_1m,
               source_url = excluded.source_url,
               user_override = 1,
               updated_at = excluded.updated_at",
            params![
                id,
                input.provider_id,
                input.model,
                input.source_url,
                input.input_per_1m,
                input.output_per_1m,
                input.cached_input_per_1m,
                input.cache_write_per_1m,
                input.cache_read_per_1m,
                input.reasoning_per_1m,
                input.tool_per_1m,
                now_ts
            ],
        )
        .map_err(to_string)?;
        recalculate_event_costs(&conn).map_err(to_string)?;
        Ok::<_, String>(serde_json::json!({ "id": id, "updated": true }))
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
fn list_missing_models(state: State<AppState>) -> Result<Vec<Value>, String> {
    let conn = state.db.blocking_lock();
    let mut stmt = conn
        .prepare(
            "SELECT u.provider_id, u.model, COUNT(*) as event_count
             FROM usage_events u
             WHERE u.model IS NOT NULL AND u.model != ''
               AND NOT EXISTS (
                 SELECT 1 FROM pricing_catalogs p
                 WHERE p.provider_id = u.provider_id
                   AND (lower(p.model) = lower(u.model)
                        OR EXISTS (
                          SELECT 1 FROM json_each(p.aliases_json)
                          WHERE lower(json_each.value) = lower(u.model)
                        ))
               )
             GROUP BY u.provider_id, u.model
             ORDER BY event_count DESC",
        )
        .map_err(to_string)?;
    let rows = stmt
        .query_map([], |r| {
            Ok(serde_json::json!({
                "provider_id": r.get::<_, String>(0)?,
                "model": r.get::<_, String>(1)?,
                "event_count": r.get::<_, i64>(2)?,
            }))
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

#[tauri::command]
async fn pull_pricing(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let count = pull_pricing_from_server(&conn)?;
        recalculate_event_costs(&conn).map_err(to_string)?;
        Ok::<_, String>(serde_json::json!({ "pulled": count }))
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
async fn push_pricing(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        ensure_sync_config(&conn)?;
        let server_url: String = conn
            .query_row(
                "SELECT server_url FROM sync_config WHERE id = 1",
                [],
                |r| r.get(0),
            )
            .map_err(to_string)?;
        let token = get_sync_token(&conn)?.ok_or("Not logged in. Please log in first.")?;

        let mut stmt = conn
            .prepare(
                "SELECT provider_id, model, aliases_json, input_per_1m, output_per_1m,
                 cached_input_per_1m, cache_write_per_1m, cache_read_per_1m,
                 reasoning_per_1m, tool_per_1m, source_url, catalog_version
                 FROM pricing_catalogs",
            )
            .map_err(to_string)?;
        let prices: Vec<Value> = stmt
            .query_map([], |r| {
                let aliases_json: String = r.get::<_, String>(2)?;
                let aliases: Vec<String> = serde_json::from_str(&aliases_json).unwrap_or_default();
                Ok(serde_json::json!({
                    "provider_id": r.get::<_, String>(0)?,
                    "model": r.get::<_, String>(1)?,
                    "aliases_json": aliases,
                    "input_per_1m": r.get::<_, Option<f64>>(3)?,
                    "output_per_1m": r.get::<_, Option<f64>>(4)?,
                    "cached_input_per_1m": r.get::<_, Option<f64>>(5)?,
                    "cache_write_per_1m": r.get::<_, Option<f64>>(6)?,
                    "cache_read_per_1m": r.get::<_, Option<f64>>(7)?,
                    "reasoning_per_1m": r.get::<_, Option<f64>>(8)?,
                    "tool_per_1m": r.get::<_, Option<f64>>(9)?,
                    "source_url": r.get::<_, Option<String>>(10)?,
                    "catalog_version": r.get::<_, Option<String>>(11)?,
                }))
            })
            .map_err(to_string)?
            .collect::<Result<Vec<_>, _>>()
            .map_err(to_string)?;

        if prices.is_empty() {
            return Ok::<_, String>(serde_json::json!({ "pushed": 0 }));
        }

        let base_url = server_url.trim_end_matches('/');
        let client = reqwest::blocking::Client::new();
        let resp = client
            .post(format!("{}/api/v1/sync/pricing", base_url))
            .header("Authorization", format!("Bearer {}", token))
            .header("Accept", "application/json")
            .json(&serde_json::json!({ "prices": prices }))
            .send()
            .map_err(|e| format!("Pricing push request failed: {}", e))?;

        if !resp.status().is_success() {
            let body = resp.text().unwrap_or_default();
            return Err(format!("Pricing push failed: {}", body));
        }

        let data: Value = resp.json().map_err(|e| format!("Invalid pricing push response: {}", e))?;
        let pushed = data.get("synced").and_then(Value::as_u64).unwrap_or(0);
        Ok::<_, String>(serde_json::json!({ "pushed": pushed }))
    })
    .await
    .map_err(|e| e.to_string())?
}

fn ensure_sync_config(conn: &Connection) -> Result<(), String> {
    let exists: bool = conn
        .query_row("SELECT 1 FROM sync_config WHERE id = 1", [], |_| Ok(true))
        .unwrap_or(false);
    if !exists {
        let now = now();
        let default_root = default_project_root();
        conn.execute(
            "INSERT INTO sync_config (id, server_url, project_root, created_at, updated_at)
             VALUES (1, 'https://metr.petarpetkov.com', ?1, ?2, ?2)",
            params![default_root, now],
        )
        .map_err(to_string)?;
    } else {
        // Backfill the default project root for existing users who got a NULL column.
        let is_null: bool = conn
            .query_row(
                "SELECT project_root IS NULL FROM sync_config WHERE id = 1",
                [],
                |r| r.get(0),
            )
            .unwrap_or(false);
        if is_null {
            let default_root = default_project_root();
            let _ = conn.execute(
                "UPDATE sync_config SET project_root = ?1, updated_at = ?2 WHERE id = 1",
                params![default_root, now()],
            );
        }
    }
    Ok(())
}

fn default_project_root() -> Option<String> {
    if std::env::consts::OS == "macos" {
        dirs::home_dir().map(|h| h.join("Developer").to_string_lossy().to_string())
    } else {
        None
    }
}

fn keyring_entry() -> Result<keyring::Entry, keyring::Error> {
    keyring::Entry::new(KEYRING_SERVICE, KEYRING_USERNAME)
}

fn get_sync_token(_conn: &Connection) -> Result<Option<String>, String> {
    match keyring_entry() {
        Ok(entry) => match entry.get_password() {
            Ok(token) if token.is_empty() => Ok(None),
            Ok(token) => Ok(Some(token)),
            Err(keyring::Error::NoEntry) => Ok(None),
            Err(e) => Err(format!("Failed to read sync token from keychain: {}", e)),
        },
        Err(e) => Err(format!("Failed to access keychain: {}", e)),
    }
}

fn set_sync_token(_conn: &Connection, token: &str) -> Result<(), String> {
    let entry = keyring_entry().map_err(|e| format!("Failed to access keychain: {}", e))?;
    entry
        .set_password(token)
        .map_err(|e| format!("Failed to store sync token in keychain: {}", e))
}

fn delete_sync_token(_conn: &Connection) -> Result<(), String> {
    match keyring_entry() {
        Ok(entry) => match entry.delete_credential() {
            Ok(()) => Ok(()),
            Err(keyring::Error::NoEntry) => Ok(()),
            Err(e) => Err(format!("Failed to delete sync token from keychain: {}", e)),
        },
        Err(e) => Err(format!("Failed to access keychain: {}", e)),
    }
}

fn validate_server_url(url: &str) -> Result<(String, Option<String>), String> {
    let trimmed = url.trim();
    if trimmed.is_empty() {
        return Err("Server URL is required.".to_string());
    }
    let parsed = reqwest::Url::parse(trimmed)
        .map_err(|e| format!("Invalid server URL: {}", e))?;
    if parsed.scheme() != "https" {
        return Err("Server URL must use HTTPS.".to_string());
    }
    let host = parsed
        .host_str()
        .ok_or("Server URL must include a host.")?
        .to_lowercase();
    let warning = if host != OFFICIAL_SERVER_HOST {
        Some(format!(
            "You are connecting to an unofficial server ({}). The official server is {}.",
            host, OFFICIAL_SERVER_HOST
        ))
    } else {
        None
    };
    Ok((trimmed.to_string(), warning))
}

fn validate_source_path(path: &str) -> Result<PathBuf, String> {
    let trimmed = path.trim();
    if trimmed.is_empty() {
        return Err("Path is empty.".to_string());
    }
    if trimmed.starts_with('-') {
        return Err("Path cannot start with '-'.".to_string());
    }
    if trimmed.contains("://") || trimmed.starts_with("\\\\") {
        return Err("URL-like or network paths are not allowed.".to_string());
    }

    let expanded = expand_tilde(trimmed);
    let canonical = std::fs::canonicalize(&expanded)
        .map_err(|e| format!("Path does not exist: {}", e))?;
    if !canonical.is_dir() {
        return Err("Path is not a directory.".to_string());
    }

    if is_dangerous_root(&canonical) {
        return Err(format!(
            "Refusing to add protected system directory: {}",
            canonical.display()
        ));
    }
    Ok(canonical)
}

fn is_dangerous_root(path: &Path) -> bool {
    let dangerous: Vec<PathBuf> = {
        #[cfg(target_family = "unix")]
        {
            vec!["/", "/etc", "/System", "/usr", "/bin", "/sbin", "/opt", "/var", "/tmp", "/dev", "/home", "/Users"]
                .into_iter()
                .map(PathBuf::from)
                .collect()
        }
        #[cfg(target_os = "windows")]
        {
            vec![
                "C:\\",
                "C:\\Windows",
                "C:\\Program Files",
                "C:\\Program Files (x86)",
                "C:\\ProgramData",
                "C:\\Users",
            ]
            .into_iter()
            .map(PathBuf::from)
            .collect()
        }
    };
    dangerous.iter().any(|root| {
        std::fs::canonicalize(root)
            .map(|r| r == *path)
            .unwrap_or(false)
    })
}

fn get_sync_config(conn: &Connection) -> Result<SyncStatus, String> {
    ensure_sync_config(conn)?;
    let row = conn
        .query_row(
            "SELECT server_url, device_name, username, last_sync_at, sync_enabled, last_sync_error, last_sync_attempt_at, project_root
             FROM sync_config WHERE id = 1",
            [],
            |r| {
                Ok((
                    r.get::<_, String>(0)?,
                    r.get::<_, Option<String>>(1)?,
                    r.get::<_, Option<String>>(2)?,
                    r.get::<_, Option<String>>(3)?,
                    r.get::<_, i64>(4)? == 1,
                    r.get::<_, Option<String>>(5)?,
                    r.get::<_, Option<String>>(6)?,
                    r.get::<_, Option<String>>(7)?,
                ))
            },
        )
        .map_err(to_string)?;
    let token = get_sync_token(conn)?;

    let pending: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM usage_events WHERE synced_at IS NULL",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);

    let sync_error_count: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM usage_events WHERE synced_at IS NULL AND sync_error IS NOT NULL",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);

    Ok(SyncStatus {
        configured: true,
        server_url: row.0,
        logged_in: token.is_some(),
        username: row.2,
        device_name: row.1,
        last_sync_at: row.3,
        last_sync_attempt_at: row.6,
        pending_events: pending,
        sync_error_count,
        last_sync_error: row.5,
        sync_enabled: row.4,
        project_root: row.7,
    })
}

#[tauri::command]
fn configure_sync_server(state: State<AppState>, server_url: String) -> Result<SyncStatus, String> {
    let (validated, warning) = validate_server_url(&server_url)?;
    let conn = state.db.blocking_lock();
    ensure_sync_config(&conn)?;
    let now = now();
    conn.execute(
        "UPDATE sync_config SET server_url = ?1, updated_at = ?2 WHERE id = 1",
        params![validated, now],
    )
    .map_err(to_string)?;
    if let Some(warning) = warning {
        eprintln!("[configure_sync_server] {}", warning);
    }
    get_sync_config(&conn)
}

#[tauri::command]
fn get_project_root(state: State<AppState>) -> Result<Option<String>, String> {
    let conn = state.db.blocking_lock();
    ensure_sync_config(&conn)?;
    let root: Option<String> = conn
        .query_row(
            "SELECT project_root FROM sync_config WHERE id = 1",
            [],
            |r| r.get(0),
        )
        .map_err(to_string)?;
    Ok(root)
}

#[tauri::command]
fn set_project_root(state: State<AppState>, project_root: Option<String>) -> Result<SyncStatus, String> {
    let conn = state.db.blocking_lock();
    ensure_sync_config(&conn)?;
    let now = now();
    let value = project_root.as_deref().unwrap_or("");
    conn.execute(
        "UPDATE sync_config SET project_root = ?1, updated_at = ?2 WHERE id = 1",
        params![value, now],
    )
    .map_err(to_string)?;
    get_sync_config(&conn)
}

#[tauri::command]
async fn rebuild_projects(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        ensure_sync_config(&conn)?;
        let custom_root = project_root_from_conn(&conn);

        // Clear existing project assignments.
        conn.execute("UPDATE usage_events SET project_id = NULL", [])
            .map_err(to_string)?;
        conn.execute("UPDATE conversations SET project_id = NULL", [])
            .map_err(to_string)?;
        conn.execute("DELETE FROM projects", [])
            .map_err(to_string)?;

        // Re-infer projects for all events, preferring the original cwd/project_path
        // captured during parsing (stored in source_project_path) over the log file path.
        let mut stmt = conn
            .prepare("SELECT id, provider_id, source_file_path, source_project_path, timestamp FROM usage_events")
            .map_err(to_string)?;
        let rows: Vec<(String, String, String, Option<String>, String)> = stmt
            .query_map([], |r| {
                Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
            })
            .map_err(to_string)?
            .collect::<Result<Vec<_>, _>>()
            .map_err(to_string)?;
        drop(stmt);

        for (id, provider_id, source_file_path, source_project_path, timestamp) in &rows {
            let raw_path = source_project_path
                .clone()
                .map(|p| expand_tilde(&p).to_string_lossy().to_string())
                .or_else(|| infer_project_from_path(Path::new(&source_file_path)));
            let project_path = if let Some(root) = custom_root.as_deref() {
                if let Some(p) = raw_path
                    .as_deref()
                    .and_then(|p| project_under_root(Path::new(p), root))
                {
                    Some(p)
                } else {
                    project_under_root(Path::new(&source_file_path), root)
                }
            } else {
                raw_path
            };

            if let Some(path) = project_path {
                let project_id = upsert_project(&conn, provider_id, &path, timestamp)?;
                conn.execute(
                    "UPDATE usage_events SET project_id = ?1 WHERE id = ?2",
                    params![project_id, id],
                )
                .map_err(to_string)?;
            }
        }

        // Rebuild conversation project links from their events.
        conn.execute(
            "UPDATE conversations
             SET project_id = (
                 SELECT project_id FROM usage_events
                 WHERE usage_events.conversation_id = conversations.id AND project_id IS NOT NULL
                 ORDER BY timestamp ASC LIMIT 1
             )",
            [],
        )
        .map_err(to_string)?;

        apply_project_management(&conn).map_err(to_string)?;

        Ok::<_, String>(serde_json::json!({ "rebuilt": rows.len() }))
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
async fn login_sync(state: State<'_, AppState>, input: LoginInput) -> Result<SyncStatus, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        ensure_sync_config(&conn)?;

        let device_name = format!(
            "{}-{}",
            std::env::consts::OS,
            whoami::fallible::hostname().unwrap_or_else(|_| "unknown".to_string())
        );

        let (validated, _warning) = validate_server_url(&input.server_url)?;

        let client = reqwest::blocking::Client::new();
        let url = format!("{}/api/v1/auth/login", validated.trim_end_matches('/'));
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
            "UPDATE sync_config SET server_url = ?1, auth_token = NULL, username = ?2, device_name = ?3, sync_enabled = 1, updated_at = ?4 WHERE id = 1",
            params![validated, username, device_name, now],
        )
        .map_err(to_string)?;
        set_sync_token(&conn, token)?;
        let _ = pull_pricing_from_server(&conn);
        recalculate_event_costs(&conn).map_err(to_string)?;

        get_sync_config(&conn)
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
fn logout_sync(state: State<AppState>) -> Result<SyncStatus, String> {
    let conn = state.db.blocking_lock();
    ensure_sync_config(&conn)?;

    let server_url: String = conn
        .query_row(
            "SELECT server_url FROM sync_config WHERE id = 1",
            [],
            |r| r.get(0),
        )
        .map_err(to_string)?;
    if let Some(token) = get_sync_token(&conn)? {
        let _ = reqwest::blocking::Client::new()
            .post(format!(
                "{}/api/v1/auth/logout",
                server_url.trim_end_matches('/')
            ))
            .header("Authorization", format!("Bearer {}", token))
            .header("Accept", "application/json")
            .send();
    }

    delete_sync_token(&conn)?;
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
    let conn = state.db.blocking_lock();
    get_sync_config(&conn)
}

#[tauri::command]
async fn sync_now(state: State<'_, AppState>, app: tauri::AppHandle) -> Result<SyncResult, String> {
    let db = state.db.clone();
    {
        let conn = db.lock().await;
        let now = now();
        conn.execute(
            "UPDATE sync_config SET last_sync_attempt_at = ?1, updated_at = ?1 WHERE id = 1",
            params![now],
        )
        .map_err(to_string)?;
    }

    let (progress_tx, progress_rx) = std::sync::mpsc::channel::<(usize, usize)>();
    let progress_tx_clone = progress_tx.clone();

    let sync_task = tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let result = perform_sync(&conn, false, |uploaded, total| {
            let _ = progress_tx_clone.send((uploaded, total));
        });
        let _ = pull_pricing_from_server(&conn);
        result
    });

    let emit_task = tauri::async_runtime::spawn(async move {
        while let Ok((uploaded, total)) = progress_rx.recv() {
            let _ = app.emit(
                "sync-progress",
                serde_json::json!({ "uploaded": uploaded, "total": total }),
            );
        }
    });

    let result = match sync_task.await {
        Ok(Ok(result)) => Ok(result),
        Ok(Err(err)) => {
            let conn = state.db.lock().await;
            let _ = conn.execute(
                "UPDATE sync_config SET last_sync_error = ?1, updated_at = ?2 WHERE id = 1",
                params![&err, now()],
            );
            Err(err)
        }
        Err(e) => {
            let err = format!("Sync task failed: {:?}", e);
            let conn = state.db.lock().await;
            let _ = conn.execute(
                "UPDATE sync_config SET last_sync_error = ?1, updated_at = ?2 WHERE id = 1",
                params![&err, now()],
            );
            Err(err)
        }
    };
    drop(progress_tx);
    let _ = emit_task.await;
    result
}

#[tauri::command]
async fn full_resync(state: State<'_, AppState>, app: tauri::AppHandle) -> Result<SyncResult, String> {
    let db = state.db.clone();
    {
        let conn = db.lock().await;
        let now = now();
        conn.execute(
            "UPDATE sync_config SET last_sync_attempt_at = ?1, updated_at = ?1 WHERE id = 1",
            params![now],
        )
        .map_err(to_string)?;
    }

    let (progress_tx, progress_rx) = std::sync::mpsc::channel::<(usize, usize)>();
    let progress_tx_clone = progress_tx.clone();

    let sync_task = tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let result = perform_sync(&conn, true, |uploaded, total| {
            let _ = progress_tx_clone.send((uploaded, total));
        });
        let _ = pull_pricing_from_server(&conn);
        result
    });

    let emit_task = tauri::async_runtime::spawn(async move {
        while let Ok((uploaded, total)) = progress_rx.recv() {
            let _ = app.emit(
                "sync-progress",
                serde_json::json!({ "uploaded": uploaded, "total": total }),
            );
        }
    });

    let result = match sync_task.await {
        Ok(Ok(result)) => Ok(result),
        Ok(Err(err)) => {
            let conn = state.db.lock().await;
            let _ = conn.execute(
                "UPDATE sync_config SET last_sync_error = ?1, updated_at = ?2 WHERE id = 1",
                params![&err, now()],
            );
            Err(err)
        }
        Err(e) => {
            let err = format!("Sync task failed: {:?}", e);
            let conn = state.db.lock().await;
            let _ = conn.execute(
                "UPDATE sync_config SET last_sync_error = ?1, updated_at = ?2 WHERE id = 1",
                params![&err, now()],
            );
            Err(err)
        }
    };
    drop(progress_tx);
    let _ = emit_task.await;
    result
}

fn perform_sync<F>(conn: &Connection, force_all: bool, mut progress: F) -> Result<SyncResult, String>
where
    F: FnMut(usize, usize),
{
    ensure_sync_config(conn)?;

    let server_url: String = conn
        .query_row(
            "SELECT server_url FROM sync_config WHERE id = 1",
            [],
            |r| r.get(0),
        )
        .map_err(to_string)?;
    let token = get_sync_token(conn)?.ok_or("Not logged in. Please log in first.")?;
    let device_uuid: Option<String> = conn
        .query_row(
            "SELECT device_uuid FROM sync_config WHERE id = 1",
            [],
            |r| r.get(0),
        )
        .optional()
        .map_err(to_string)?
        .flatten();

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
    let client = reqwest::blocking::Client::builder()
        .timeout(std::time::Duration::from_secs(60))
        .build()
        .map_err(|e| format!("Failed to build HTTP client: {}", e))?;
    let auth_header = format!("Bearer {}", token);

    let device_name = conn
        .query_row(
            "SELECT device_name FROM sync_config WHERE id = 1",
            [],
            |r| r.get::<_, String>(0),
        )
        .unwrap_or_else(|_| format!("{}-unknown", std::env::consts::OS));

    let os_version = {
        let d = whoami::distro();
        if d.is_empty() {
            whoami::platform().to_string()
        } else {
            d
        }
    };
    let reg_resp = client
        .post(format!("{}/api/v1/devices/register", base_url))
        .header("Authorization", &auth_header)
        .header("Accept", "application/json")
        .json(&serde_json::json!({
            "device_uuid": device_uuid,
            "display_name": device_name,
            "platform": std::env::consts::OS,
            "hostname_hash": hash(&whoami::fallible::hostname().unwrap_or_else(|_| "unknown".to_string())),
            "os_version": os_version,
            "app_version": env!("CARGO_PKG_VERSION"),
        }))
        .send()
        .map_err(|e| format!("Device registration failed: {}", e))?;

    if !reg_resp.status().is_success() {
        let body = reg_resp.text().unwrap_or_default();
        return Err(format!("Device registration failed: {}", body));
    }

    let mut errors = Vec::new();
    let subscriptions_uploaded = match sync_subscriptions(conn, &client, base_url, &auth_header) {
        Ok(count) => count,
        Err(error) => {
            errors.push(format!("Subscription sync failed: {error}"));
            0
        }
    };

    if force_all {
        conn.execute(
            "UPDATE usage_events
             SET synced_at = NULL, sync_batch_id = NULL, sync_error = NULL, updated_at = ?1",
            params![now()],
        )
        .map_err(to_string)?;
    }

    let total_pending: usize = conn
        .query_row(
            "SELECT COUNT(*) FROM usage_events WHERE synced_at IS NULL",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);

    println!("[Sync] Starting sync. Pending events: {}, force_all: {}", total_pending, force_all);

    let mut total_uploaded = 0usize;
    let mut batch_count = 0usize;

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
                 LIMIT 100",
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
            .map_err(|e| {
                let mut detail = format!("Sync request failed ({} events, ~{} bytes): {}", events.len(), serde_json::to_string(&events).unwrap_or_default().len(), e);
                if let Some(source) = e.source() {
                    detail.push_str(&format!(" | caused by: {}", source));
                }
                detail
            })?;

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
            println!("[Sync] Batch {} uploaded successfully ({} events). Total: {}/{}", batch_count, events.len(), total_uploaded, total_pending);
            progress(total_uploaded, total_pending);
        } else {
            let status = resp.status();
            let body = resp.text().unwrap_or_default();
            let error_msg = format!("Batch {} failed (HTTP {}): {}", batch_count + 1, status, body);
            println!("[Sync] {}", error_msg);
            errors.push(error_msg);
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
    let last_error = errors.last().cloned();
    conn.execute(
        "UPDATE sync_config SET last_sync_at = ?1, last_sync_error = ?2, updated_at = ?1 WHERE id = 1",
        params![now, last_error],
    )
    .map_err(to_string)?;

    if !errors.is_empty() {
        println!("[Sync] Completed with errors: {:?}", errors);
    } else {
        println!("[Sync] Completed successfully. Uploaded {} events in {} batches.", total_uploaded, batch_count);
    }

    Ok(SyncResult {
        uploaded: total_uploaded,
        batches: batch_count,
        subscriptions_uploaded,
        errors,
    })
}

#[tauri::command]
fn debug_sync_state(state: State<AppState>) -> Result<Value, String> {
    let conn = state.db.blocking_lock();

    let pending: i64 = conn
        .query_row("SELECT COUNT(*) FROM usage_events WHERE synced_at IS NULL", [], |r| r.get(0))
        .unwrap_or(0);

    let with_error: i64 = conn
        .query_row("SELECT COUNT(*) FROM usage_events WHERE synced_at IS NULL AND sync_error IS NOT NULL", [], |r| r.get(0))
        .unwrap_or(0);

    let total_events: i64 = conn
        .query_row("SELECT COUNT(*) FROM usage_events", [], |r| r.get(0))
        .unwrap_or(0);

    let mut stmt = conn
        .prepare(
            "SELECT id, provider_id, timestamp, model, source_file_path, sync_error
             FROM usage_events
             WHERE synced_at IS NULL
             ORDER BY timestamp DESC
             LIMIT 10",
        )
        .map_err(to_string)?;

    let sample_events: Vec<Value> = stmt
        .query_map([], |r| {
            let source_file_path: Option<String> = r.get(4)?;
            let redacted_path = source_file_path.as_deref().map(|p| {
                Path::new(p)
                    .file_name()
                    .and_then(|n| n.to_str())
                    .unwrap_or("<redacted>")
                    .to_string()
            });
            Ok(serde_json::json!({
                "id": r.get::<_, String>(0)?,
                "provider_id": r.get::<_, String>(1)?,
                "timestamp": r.get::<_, String>(2)?,
                "model": r.get::<_, Option<String>>(3)?,
                "source_file_basename": redacted_path,
                "sync_error": r.get::<_, Option<String>>(5)?,
            }))
        })
        .map_err(to_string)?
        .collect::<Result<Vec<_>, _>>()
        .map_err(to_string)?;

    let sync_config: Value = conn
        .query_row(
            "SELECT server_url, auth_token IS NOT NULL, username, last_sync_at, last_sync_error, sync_enabled, NULL, last_sync_attempt_at
             FROM sync_config WHERE id = 1",
            [],
            |r| {
                Ok(serde_json::json!({
                    "server_url": r.get::<_, String>(0)?,
                    "logged_in": r.get::<_, i64>(1)? == 1,
                    "username": r.get::<_, Option<String>>(2)?,
                    "last_sync_at": r.get::<_, Option<String>>(3)?,
                    "last_sync_error": r.get::<_, Option<String>>(4)?,
                    "sync_enabled": r.get::<_, i64>(5)? == 1,
                    "device_uuid": None::<Option<String>>,
                    "last_sync_attempt_at": r.get::<_, Option<String>>(7)?,
                }))
            },
        )
        .unwrap_or_else(|_| serde_json::json!({"error": "sync_config not initialized"}));

    Ok(serde_json::json!({
        "pending_events": pending,
        "events_with_sync_error": with_error,
        "total_events": total_events,
        "sample_pending_events": sample_events,
        "sync_config": sync_config,
    }))
}

fn sync_subscriptions(
    conn: &Connection,
    client: &reqwest::blocking::Client,
    base_url: &str,
    auth_header: &str,
) -> Result<usize, String> {
    let mut stmt = conn
        .prepare(
            "SELECT id, provider_id, product_name, monthly_amount, currency,
             billing_anchor_day, enabled, notes
             FROM subscriptions
             ORDER BY provider_id, product_name",
        )
        .map_err(to_string)?;

    let subscriptions: Vec<Value> = stmt
        .query_map([], |r| {
            let enabled: i64 = r.get(6)?;
            Ok(serde_json::json!({
                "source_subscription_id": r.get::<_, String>(0)?,
                "provider_id": r.get::<_, String>(1)?,
                "product_name": r.get::<_, String>(2)?,
                "monthly_amount": r.get::<_, f64>(3)?,
                "currency": r.get::<_, String>(4)?,
                "billing_anchor_day": r.get::<_, i64>(5)?,
                "enabled": enabled == 1,
                "notes": r.get::<_, Option<String>>(7)?,
            }))
        })
        .map_err(to_string)?
        .collect::<Result<Vec<_>, _>>()
        .map_err(to_string)?;

    if subscriptions.is_empty() {
        return Ok(0);
    }

    let subscription_count = subscriptions.len();
    let resp = client
        .post(format!("{}/api/v1/sync/subscriptions", base_url))
        .header("Authorization", auth_header)
        .header("Accept", "application/json")
        .json(&serde_json::json!({
            "subscriptions": subscriptions,
        }))
        .send()
        .map_err(|e| format!("Subscription sync request failed: {}", e))?;

    let status = resp.status();
    if !status.is_success() {
        let body = resp.text().unwrap_or_default();
        return Err(format!("{status}: {body}"));
    }

    let data: Value = resp
        .json()
        .map_err(|e| format!("Invalid subscription sync response: {}", e))?;

    Ok(data
        .get("synced")
        .and_then(|value| value.as_u64())
        .unwrap_or(subscription_count as u64) as usize)
}

fn pull_pricing_from_server(conn: &Connection) -> Result<usize, String> {
    ensure_sync_config(conn)?;
    let server_url: String = conn
        .query_row(
            "SELECT server_url FROM sync_config WHERE id = 1",
            [],
            |r| r.get(0),
        )
        .map_err(to_string)?;
    let token = get_sync_token(conn)?.ok_or("Not logged in. Please log in first.")?;

    let base_url = server_url.trim_end_matches('/');
    let client = reqwest::blocking::Client::new();
    let data: Value = client
        .get(format!("{}/api/v1/sync/settings", base_url))
        .header("Authorization", format!("Bearer {}", token))
        .header("Accept", "application/json")
        .send()
        .map_err(|e| format!("Pricing pull request failed: {}", e))?
        .json()
        .map_err(|e| format!("Invalid pricing pull response: {}", e))?;

    let prices = data
        .get("model_prices")
        .and_then(Value::as_array)
        .ok_or("Missing model_prices in sync settings response".to_string())?;

    if prices.is_empty() {
        // Do not delete any local server-managed pricing when the server returns an empty list.
        return Ok(0);
    }

    let mut pulled = 0usize;
    let now_ts = now();
    for item in prices {
        let provider_id = item.get("provider_id").and_then(Value::as_str).unwrap_or("");
        let model = item.get("model").and_then(Value::as_str).unwrap_or("");
        if provider_id.is_empty() || model.is_empty() {
            continue;
        }

        let aliases_json = match item.get("aliases_json") {
            Some(Value::String(s)) => s.clone(),
            Some(Value::Array(arr)) => serde_json::to_string(arr).unwrap_or_else(|_| "[]".to_string()),
            _ => "[]".to_string(),
        };
        let source_url = item.get("source_url").and_then(Value::as_str);
        let input = json_f64(item.get("input_per_1m"));
        let output = json_f64(item.get("output_per_1m"));
        let cached = json_f64(item.get("cached_input_per_1m"));
        let cache_write = json_f64(item.get("cache_write_per_1m"));
        let cache_read = json_f64(item.get("cache_read_per_1m"));
        let reasoning = json_f64(item.get("reasoning_per_1m"));
        let tool = json_f64(item.get("tool_per_1m"));
        let catalog_version = item
            .get("catalog_version")
            .and_then(Value::as_str)
            .unwrap_or("server-sync");
        let effective_from = item
            .get("effective_from")
            .and_then(Value::as_str)
            .unwrap_or("2026-01-01");
        let id = format!("{}:{}", provider_id, model.to_ascii_lowercase());

        conn.execute(
            "INSERT INTO pricing_catalogs
             (id, provider_id, model, aliases_json, source_url, catalog_version, effective_from,
              input_per_1m, output_per_1m, cached_input_per_1m, cache_write_per_1m, cache_read_per_1m,
              reasoning_per_1m, tool_per_1m, created_at, updated_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?15)
             ON CONFLICT(id) DO UPDATE SET
               aliases_json = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.aliases_json ELSE pricing_catalogs.aliases_json END,
               source_url = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.source_url ELSE pricing_catalogs.source_url END,
               input_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.input_per_1m ELSE pricing_catalogs.input_per_1m END,
               output_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.output_per_1m ELSE pricing_catalogs.output_per_1m END,
               cached_input_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.cached_input_per_1m ELSE pricing_catalogs.cached_input_per_1m END,
               cache_write_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.cache_write_per_1m ELSE pricing_catalogs.cache_write_per_1m END,
               cache_read_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.cache_read_per_1m ELSE pricing_catalogs.cache_read_per_1m END,
               reasoning_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.reasoning_per_1m ELSE pricing_catalogs.reasoning_per_1m END,
               tool_per_1m = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.tool_per_1m ELSE pricing_catalogs.tool_per_1m END,
               catalog_version = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.catalog_version ELSE pricing_catalogs.catalog_version END,
               effective_from = CASE WHEN pricing_catalogs.user_override = 0 THEN excluded.effective_from ELSE pricing_catalogs.effective_from END,
               updated_at = excluded.updated_at",
            params![
                id,
                provider_id,
                model,
                aliases_json,
                source_url,
                catalog_version,
                effective_from,
                input,
                output,
                cached,
                cache_write,
                cache_read,
                reasoning,
                tool,
                now_ts
            ],
        )
        .map_err(to_string)?;
        pulled += 1;
    }

    // Delete non-user-override prices that are no longer returned by the server.
    // This keeps the catalog clean — only used models + user overrides remain.
    let mut stmt = conn
        .prepare("SELECT id FROM pricing_catalogs WHERE user_override = 0")
        .map_err(to_string)?;
    let existing_ids: Vec<String> = stmt
        .query_map([], |r| r.get::<_, String>(0))
        .map_err(to_string)?
        .collect::<Result<Vec<_>, _>>()
        .map_err(to_string)?;
    drop(stmt);

    let mut kept_ids = std::collections::HashSet::new();
    for item in prices {
        let provider_id = item.get("provider_id").and_then(Value::as_str).unwrap_or("");
        let model = item.get("model").and_then(Value::as_str).unwrap_or("");
        if !provider_id.is_empty() && !model.is_empty() {
            kept_ids.insert(format!("{}:{}", provider_id, model.to_ascii_lowercase()));
        }
    }

    let removed: usize = existing_ids
        .iter()
        .filter(|id| !kept_ids.contains(id.as_str()))
        .filter_map(|id| {
            conn.execute("DELETE FROM pricing_catalogs WHERE id = ?1", params![id])
                .ok()
        })
        .count();

    if removed > 0 {
        println!("[Pricing] Removed {} unused server prices from local catalog", removed);
    }

    Ok(pulled)
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
          event_type TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS indexed_files (
          id TEXT PRIMARY KEY,
          source_id TEXT NOT NULL,
          path TEXT NOT NULL,
          size_bytes INTEGER NOT NULL,
          modified_at TEXT NOT NULL,
          parser_id TEXT NOT NULL,
          parser_version TEXT NOT NULL,
          last_scan_status TEXT NOT NULL,
          last_scan_message TEXT,
          last_scanned_at TEXT NOT NULL,
          UNIQUE(source_id, path)
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
        CREATE TABLE IF NOT EXISTS sync_config (
          id INTEGER PRIMARY KEY CHECK (id = 1),
          server_url TEXT NOT NULL DEFAULT 'https://metr.petarpetkov.com',
          auth_token TEXT,
          user_id TEXT,
          username TEXT,
          device_uuid TEXT,
          device_name TEXT,
          last_sync_at TEXT,
          sync_enabled INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_usage_events_timestamp ON usage_events(timestamp);
        CREATE INDEX IF NOT EXISTS idx_usage_events_provider ON usage_events(provider_id);
        CREATE INDEX IF NOT EXISTS idx_usage_events_project ON usage_events(project_id);
        CREATE INDEX IF NOT EXISTS idx_usage_events_model ON usage_events(model);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_usage_events_dedupe ON usage_events(id);
        CREATE INDEX IF NOT EXISTS idx_indexed_files_source_path ON indexed_files(source_id, path);
        CREATE INDEX IF NOT EXISTS idx_pricing_provider_model ON pricing_catalogs(provider_id, model);
        ",
    )?;
    add_column_if_missing(conn, "usage_events", "synced_at", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "sync_batch_id", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "sync_error", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "event_type", "TEXT")?;
    add_column_if_missing(conn, "sync_config", "last_sync_error", "TEXT")?;
    add_column_if_missing(conn, "sync_config", "last_sync_attempt_at", "TEXT")?;
    add_column_if_missing(conn, "sync_config", "project_root", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "source_project_path", "TEXT")?;
    add_column_if_missing(conn, "usage_events", "merged_from_project_id", "TEXT")?;
    add_column_if_missing(conn, "conversations", "merged_from_project_id", "TEXT")?;
    create_project_management_table(conn)?;
    Ok(())
}

fn create_project_management_table(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(
        "
        CREATE TABLE IF NOT EXISTS project_management (
          id TEXT PRIMARY KEY,
          provider_id TEXT NOT NULL,
          custom_name TEXT,
          merged_into_project_id TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_project_management_merged ON project_management(merged_into_project_id);
        ",
    )
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
        ("kimi", "Kimi / Moonshot"),
        ("ollama", "Ollama"),
        ("lmstudio", "LM Studio"),
        ("generic", "Generic JSONL"),
    ] {
        ensure_provider(conn, id, name)?;
    }
    Ok(())
}

fn cleanup_known_bad_imports(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(
        "
        DELETE FROM usage_events
        WHERE replace(lower(source_file_path), '/', '\\') LIKE '%\\.codex\\tmp\\%'
           OR replace(lower(source_file_path), '/', '\\') LIKE '%\\.codex\\plugins\\%'
           OR replace(lower(source_file_path), '/', '\\') LIKE '%\\plugin-eval\\%';

        DELETE FROM conversations
        WHERE id NOT IN (
          SELECT DISTINCT conversation_id FROM usage_events WHERE conversation_id IS NOT NULL
        );

        DELETE FROM projects
        WHERE id NOT IN (
          SELECT DISTINCT project_id FROM usage_events WHERE project_id IS NOT NULL
        );
        ",
    )
}

fn recalculate_event_costs(conn: &Connection) -> rusqlite::Result<()> {
    let mut stmt = conn.prepare(
        "SELECT id, provider_id, model, input_tokens, output_tokens, cached_input_tokens,
         cache_write_tokens, cache_read_tokens, reasoning_tokens, tool_tokens, unknown_tokens
         FROM usage_events",
    )?;
    let rows = stmt.query_map([], |r| {
        Ok((
            r.get::<_, String>(0)?,
            r.get::<_, String>(1)?,
            r.get::<_, Option<String>>(2)?,
            ParsedEvent {
                provider_id: r.get(1)?,
                product_id: None,
                timestamp: String::new(),
                project_path: None,
                conversation_id: None,
                message_id: None,
                request_id: None,
                model: r.get(2)?,
                event_type: None,
                input_tokens: r.get(3)?,
                output_tokens: r.get(4)?,
                cached_input_tokens: r.get(5)?,
                cache_write_tokens: r.get(6)?,
                cache_read_tokens: r.get(7)?,
                reasoning_tokens: r.get(8)?,
                tool_tokens: r.get(9)?,
                unknown_tokens: r.get(10)?,
                source_offset: None,
                raw_record_hash: String::new(),
                confidence: String::new(),
                warnings: vec![],
            },
        ))
    })?;
    let mut updates = Vec::new();
    for row in rows {
        let (id, provider_id, model, event) = row?;
        if let Some(pricing) = find_pricing_sql(conn, &provider_id, model.as_deref())? {
            updates.push((id, calculate_cost(&event, &pricing), pricing.id));
        }
    }
    drop(stmt);
    for (id, cost, pricing_id) in updates {
        conn.execute(
            "UPDATE usage_events
             SET official_api_cost_usd = ?1,
                 pricing_catalog_id = ?2,
                 pricing_match_confidence = 'exact',
                 updated_at = ?3
             WHERE id = ?4",
            params![cost, pricing_id, now(), id],
        )?;
    }
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
        candidates.push(CandidateSource {
            provider_id: "kimi",
            parser_id: "generic_jsonl",
            display_name: "Kimi / Moonshot",
            path: data.join("Kimi"),
        });
    }
    if let Some(home) = dirs::home_dir() {
        candidates.push(CandidateSource {
            provider_id: "kimi",
            parser_id: "generic_jsonl",
            display_name: "Kimi / Moonshot",
            path: home.join(".kimi"),
        });
        candidates.push(CandidateSource {
            provider_id: "kimi",
            parser_id: "generic_jsonl",
            display_name: "Kimi / Moonshot",
            path: home.join(".moonshot"),
        });
        candidates.push(CandidateSource {
            provider_id: "ollama",
            parser_id: "generic_jsonl",
            display_name: "Ollama",
            path: home.join(".ollama"),
        });
        candidates.push(CandidateSource {
            provider_id: "lmstudio",
            parser_id: "generic_jsonl",
            display_name: "LM Studio",
            path: home.join(".lmstudio"),
        });
    }
    candidates
}

fn count_candidate_files(path: &Path) -> usize {
    WalkDir::new(path)
        .max_depth(5)
        .follow_links(false)
        .into_iter()
        .filter_map(Result::ok)
        .filter(|e| e.file_type().is_file())
        .filter(|e| is_candidate_file(e.path()))
        .take(5_001)
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

fn is_skipped_dir(path: &Path) -> bool {
    let Some(name) = path.file_name().and_then(|n| n.to_str()) else {
        return false;
    };
    matches!(
        name.to_ascii_lowercase().as_str(),
        "node_modules"
            | ".git"
            | ".tmp"
            | "tmp"
            | "temp"
            | "plugins"
            | "target"
            | "dist"
            | "build"
            | ".next"
            | "vendor"
            | "cache"
            | "caches"
            | "blob_storage"
            | "gpu_cache"
            | "code cache"
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

fn scan_source(conn: &Connection, source: &Source, full_scan: bool) -> Result<usize, String> {
    let started = now();
    conn.execute(
        "UPDATE log_sources SET last_scan_started_at = ?1, last_scan_status = 'scanning', last_scan_message = NULL WHERE id = ?2",
        params![started, source.id],
    )
    .map_err(to_string)?;
    let custom_root = project_root_from_conn(conn);
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
    let mut scanned_files = 0usize;
    let mut skipped_unchanged = 0usize;
    let mut skipped_after_limit = false;
    let mut total_bytes_scanned: u64 = 0;
    for entry in WalkDir::new(&root)
        .max_depth(8)
        .follow_links(false)
        .into_iter()
        .filter_entry(|e| !e.file_type().is_dir() || !is_skipped_dir(e.path()))
        .filter_map(Result::ok)
    {
        if !entry.file_type().is_file() || !is_candidate_file(entry.path()) {
            continue;
        }
        if scanned_files >= MAX_SCAN_FILES_PER_SOURCE {
            skipped_after_limit = true;
            break;
        }
        let metadata = match entry.metadata() {
            Ok(metadata) => metadata,
            Err(_) => continue,
        };
        if total_bytes_scanned + metadata.len() > MAX_SOURCE_TOTAL_BYTES {
            skipped_after_limit = true;
            break;
        }
        total_bytes_scanned += metadata.len();
        let modified = metadata
            .modified()
            .ok()
            .map(|t| chrono::DateTime::<Utc>::from(t).to_rfc3339())
            .unwrap_or_else(now);
        if !full_scan
            && indexed_file_is_current(conn, source, entry.path(), metadata.len(), &modified)?
        {
            skipped_unchanged += 1;
            continue;
        }
        scanned_files += 1;
        let source_hash = hash(&format!(
            "{}|{}|{}",
            entry.path().to_string_lossy(),
            metadata.len(),
            modified
        ));
        let events = match parse_file_streaming(source, entry.path()) {
            Ok(events) => events,
            Err(_) => continue,
        };
        for event in events {
            if insert_event(conn, source, entry.path(), &modified, &source_hash, event, custom_root.as_deref())? {
                imported += 1;
            }
        }
        upsert_indexed_file(
            conn,
            source,
            entry.path(),
            metadata.len(),
            &modified,
            "ok",
            Some("Indexed successfully."),
        )?;
    }
    conn.execute(
        "UPDATE log_sources SET last_scan_finished_at = ?1, last_scan_status = 'ok', last_scan_message = ?2 WHERE id = ?3",
        params![
            now(),
            format!(
                "{} {scanned_files} file(s), imported {imported} new model calls{}{}{}.",
                if full_scan {
                    "Full scanned"
                } else {
                    "Scanned changed/new"
                },
                if skipped_unchanged > 0 {
                    format!(", skipped {skipped_unchanged} unchanged")
                } else {
                    String::new()
                },
                if skipped_after_limit {
                    format!(", stopped at the {MAX_SCAN_FILES_PER_SOURCE} file / {} GB safety limit",
                        MAX_SOURCE_TOTAL_BYTES / (1024 * 1024 * 1024)
                    )
                } else {
                    String::new()
                },
                if total_bytes_scanned > 0 {
                    format!(", total {} MB scanned", total_bytes_scanned / (1024 * 1024)
                    )
                } else {
                    String::new()
                }
            ),
            source.id
        ],
    )
    .map_err(to_string)?;
    Ok(imported)
}

fn indexed_file_is_current(
    conn: &Connection,
    source: &Source,
    path: &Path,
    size_bytes: u64,
    modified_at: &str,
) -> Result<bool, String> {
    let path = path.to_string_lossy().to_string();
    let exists: Option<i64> = conn
        .query_row(
            "SELECT 1 FROM indexed_files
             WHERE source_id = ?1
               AND path = ?2
               AND size_bytes = ?3
               AND modified_at = ?4
               AND parser_id = ?5
               AND parser_version = ?6
             LIMIT 1",
            params![
                source.id,
                path,
                size_bytes as i64,
                modified_at,
                source.parser_id,
                PARSER_VERSION
            ],
            |r| r.get(0),
        )
        .optional()
        .map_err(to_string)?;
    Ok(exists.is_some())
}

fn upsert_indexed_file(
    conn: &Connection,
    source: &Source,
    path: &Path,
    size_bytes: u64,
    modified_at: &str,
    status: &str,
    message: Option<&str>,
) -> Result<(), String> {
    let path = path.to_string_lossy().to_string();
    let id = hash(&format!("{}|{}", source.id, path));
    conn.execute(
        "INSERT INTO indexed_files
         (id, source_id, path, size_bytes, modified_at, parser_id, parser_version,
          last_scan_status, last_scan_message, last_scanned_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10)
         ON CONFLICT(source_id, path) DO UPDATE SET
           size_bytes = excluded.size_bytes,
           modified_at = excluded.modified_at,
           parser_id = excluded.parser_id,
           parser_version = excluded.parser_version,
           last_scan_status = excluded.last_scan_status,
           last_scan_message = excluded.last_scan_message,
           last_scanned_at = excluded.last_scanned_at",
        params![
            id,
            source.id,
            path,
            size_bytes as i64,
            modified_at,
            source.parser_id,
            PARSER_VERSION,
            status,
            message,
            now()
        ],
    )
    .map_err(to_string)?;
    Ok(())
}

fn parse_content(source: &Source, path: &Path, content: &str) -> Vec<ParsedEvent> {
    let mut events = Vec::new();
    let mut offset = 0i64;
    let mut codex_context = CodexParseContext::default();
    let mut generic_context = GenericParseContext::default();
    for line in content.lines() {
        if line.len() > MAX_LINE_LENGTH {
            offset += line.len() as i64 + 1;
            continue;
        }
        let trimmed = line.trim();
        parse_line_into_events(
            source,
            path,
            trimmed,
            Some(offset),
            &mut codex_context,
            &mut generic_context,
            &mut events,
        );
        offset += line.len() as i64 + 1;
    }
    if events.is_empty() {
        if let Ok(value) = serde_json::from_str::<Value>(content) {
            collect_json_events(source, path, &value, &mut events, 0);
        }
    }
    events
}

fn parse_file_streaming(source: &Source, path: &Path) -> Result<Vec<ParsedEvent>, String> {
    let file = fs::File::open(path).map_err(to_string)?;
    let metadata = file.metadata().map_err(to_string)?;
    let reader = BufReader::new(file);
    let mut events = Vec::new();
    let mut offset = 0i64;
    let mut codex_context = CodexParseContext::default();
    let mut generic_context = GenericParseContext::default();

    for line in reader.lines() {
        let line = line.map_err(to_string)?;
        if line.len() > MAX_LINE_LENGTH {
            offset += line.len() as i64 + 1;
            continue;
        }
        let trimmed = line.trim();
        parse_line_into_events(
            source,
            path,
            trimmed,
            Some(offset),
            &mut codex_context,
            &mut generic_context,
            &mut events,
        );
        offset += line.len() as i64 + 1;
    }

    // If no JSONL events were found and the file is small enough, try parsing it as a
    // single JSON object/array. Files larger than 100 MB are not read into memory.
    if events.is_empty() && metadata.len() <= 100 * 1024 * 1024 {
        if let Ok(content) = fs::read_to_string(path) {
            events.extend(parse_content(source, path, &content));
        }
    }

    Ok(events)
}

fn parse_line_into_events(
    source: &Source,
    path: &Path,
    trimmed: &str,
    offset: Option<i64>,
    codex_context: &mut CodexParseContext,
    generic_context: &mut GenericParseContext,
    events: &mut Vec<ParsedEvent>,
) {
    if trimmed.is_empty() {
        return;
    }
    if let Ok(value) = serde_json::from_str::<Value>(trimmed) {
        update_codex_context(codex_context, &value);
        update_generic_context(source, generic_context, &value);
        let parsed = if source.parser_id == "codex" {
            parse_codex_value(source, path, &value, offset, trimmed, codex_context)
        } else {
            parse_value(source, path, &value, offset, trimmed, Some(generic_context))
        };
        if let Some(event) = parsed {
            events.push(event);
        }
    }
}

#[derive(Default)]
struct CodexParseContext {
    cwd: Option<String>,
    session_id: Option<String>,
    model: Option<String>,
}

fn update_codex_context(context: &mut CodexParseContext, value: &Value) {
    if let Some(cwd) = value.pointer("/payload/cwd").and_then(Value::as_str) {
        context.cwd = Some(cwd.to_string());
    }
    if let Some(id) = value.pointer("/payload/id").and_then(Value::as_str) {
        context.session_id = Some(id.to_string());
    }
    if let Some(cwd) = value
        .pointer("/payload/turn_context/cwd")
        .and_then(Value::as_str)
    {
        context.cwd = Some(cwd.to_string());
    }
    if let Some(model) = value.pointer("/payload/model").and_then(Value::as_str) {
        context.model = Some(model.to_string());
    }
    if let Some(model) = value
        .pointer("/payload/turn_context/model")
        .and_then(Value::as_str)
    {
        context.model = Some(model.to_string());
    }
}

#[derive(Default)]
struct GenericParseContext {
    cwd: Option<String>,
    session_id: Option<String>,
    model: Option<String>,
}

fn update_generic_context(_source: &Source, context: &mut GenericParseContext, value: &Value) {
    if let Some(payload) = value.get("payload") {
        if let Some(next_model) = str_field(payload, &["model"]) {
            context.model = Some(next_model);
        }
        if let Some(next_cwd) = str_field(payload, &["cwd"]) {
            context.cwd = Some(next_cwd);
        }
        if let Some(next_session) = str_field(payload, &["id", "session_id", "sessionId"]) {
            context.session_id = Some(next_session);
        }
    }
    if let Some(next_model) = str_field(value, &["model", "model_name", "modelName"]) {
        context.model = Some(next_model);
    }
    if let Some(next_cwd) = str_field(value, &["cwd", "working_directory", "workingDirectory"]) {
        context.cwd = Some(next_cwd);
    }
}

fn default_model_for_source(source: &Source) -> Option<String> {
    match source.provider_id.as_str() {
        "kimi" => Some("kimi-for-coding".to_string()),
        _ => None,
    }
}

fn parse_codex_value(
    source: &Source,
    path: &Path,
    value: &Value,
    source_offset: Option<i64>,
    raw: &str,
    context: &CodexParseContext,
) -> Option<ParsedEvent> {
    let usage = value.pointer("/payload/info/last_token_usage")?;
    let input = int_field(usage, &["input_tokens", "prompt_tokens"]);
    let output = int_field(usage, &["output_tokens", "completion_tokens"]);
    // Look for cached tokens at top level or nested in details objects
    let cached = {
        let v = int_field(usage, &["cached_input_tokens", "cached_tokens"]);
        if v > 0 {
            v
        } else {
            usage.pointer("/prompt_tokens_details/cached_tokens")
                .and_then(Value::as_i64)
                .unwrap_or(0)
        }
    };
    let cache_write = int_field(usage, &["cache_creation_input_tokens", "cache_write_tokens"]);
    let cache_read = int_field(usage, &["cache_read_input_tokens", "cache_read_tokens"]);
    let reasoning = int_field(usage, &["reasoning_output_tokens", "reasoning_tokens"]);
    let total = int_field(usage, &["total_tokens"]);
    let known = input + output + cached + cache_write + cache_read + reasoning;
    let unknown = if known == 0 { total } else { 0 };
    if known == 0 && unknown == 0 {
        return None;
    }
    let detected_provider = str_field(value, &["provider", "provider_id", "providerId", "source", "source_type", "sourceType", "app", "client"])
        .or_else(|| str_field(usage, &["provider", "provider_id", "providerId", "source"]));
    let event_type = str_field(value.get("message").unwrap_or(&Value::Null), &["type"])
        .or_else(|| str_field(value, &["type", "event_type", "eventType", "kind"]))
        .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["type", "event_type", "eventType", "kind"]));
    Some(ParsedEvent {
        provider_id: detected_provider.unwrap_or_else(|| source.provider_id.clone()),
        product_id: None,
        timestamp: timestamp_field(value, &["timestamp"]).unwrap_or_else(now),
        project_path: context
            .cwd
            .clone()
            .or_else(|| infer_project_from_path(path))
            .or_else(|| path.file_stem().and_then(|s| s.to_str()).map(|s| s.to_string())),
        conversation_id: context.session_id.clone(),
        message_id: str_field(value, &["id"]),
        request_id: None,
        model: context.model.clone().or_else(|| {
            value
                .pointer("/payload/rate_limits/limit_id")
                .and_then(Value::as_str)
                .map(str::to_string)
        }),
        event_type,
        input_tokens: input,
        output_tokens: output,
        cached_input_tokens: cached,
        cache_write_tokens: cache_write,
        cache_read_tokens: cache_read,
        reasoning_tokens: reasoning,
        tool_tokens: 0,
        unknown_tokens: unknown,
        source_offset,
        raw_record_hash: hash(raw),
        confidence: if unknown > 0 { "low" } else { "high" }.to_string(),
        warnings: if unknown > 0 {
            vec!["Only total tokens were available.".to_string()]
        } else {
            vec![]
        },
    })
}

fn collect_json_events(
    source: &Source,
    path: &Path,
    value: &Value,
    events: &mut Vec<ParsedEvent>,
    depth: usize,
) {
    if depth > MAX_JSON_DEPTH {
        return;
    }
    if let Some(event) = parse_value(source, path, value, None, &value.to_string(), None) {
        events.push(event);
    }
    match value {
        Value::Array(items) => {
            for item in items {
                collect_json_events(source, path, item, events, depth + 1);
            }
        }
        Value::Object(map) => {
            for item in map.values() {
                if item.is_array() || item.is_object() {
                    collect_json_events(source, path, item, events, depth + 1);
                }
            }
        }
        _ => {}
    }
}

fn parse_value(
    source: &Source,
    path: &Path,
    value: &Value,
    source_offset: Option<i64>,
    raw: &str,
    context: Option<&GenericParseContext>,
) -> Option<ParsedEvent> {
    // Look for usage in various nested locations
    let usage = value.get("usage")
        .or_else(|| value.get("token_usage"))
        .or_else(|| value.get("api_usage"))
        .or_else(|| value.get("tokens"))
        .or_else(|| value.get("usage_stats"))
        .or_else(|| value.pointer("/message/usage"))
        .or_else(|| value.pointer("/message/payload/token_usage"))
        .or_else(|| value.pointer("/payload/token_usage"))
        .unwrap_or(value);
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
    let tool = int_field(usage, &["tool_tokens", "toolTokens"]);
    let total = int_field(usage, &["total_tokens", "totalTokens"]);
    let known = input + output + cached + cache_write + cache_read + reasoning + tool;
    let unknown = if known == 0 { total } else { 0 };
    if known == 0 && unknown == 0 {
        return None;
    }
    let timestamp = timestamp_field(
        value,
        &["timestamp", "created_at", "createdAt", "time", "date", "start_time", "startTime", "started_at", "startedAt", "end_time", "endTime", "ended_at", "endedAt"],
    )
    .or_else(|| timestamp_field(usage, &["timestamp", "created_at", "createdAt", "start_time", "startTime"]))
    .unwrap_or_else(now);
    let model = str_field(value, &["model", "model_name", "modelName", "model_id", "modelId", "model_version", "modelVersion"])
        .or_else(|| str_field(usage, &["model", "model_id", "modelId"]))
        .or_else(|| str_field(value.get("message").unwrap_or(&Value::Null), &["model"]))
        .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["model"]))
        .or_else(|| context.and_then(|c| c.model.clone()))
        .or_else(|| default_model_for_source(source));
    let event_type = str_field(value.get("message").unwrap_or(&Value::Null), &["type"])
        .or_else(|| str_field(value, &["type", "event_type", "eventType", "kind"]))
        .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["type", "event_type", "eventType", "kind"]));
    let project_path = str_field(
        value,
        &[
            "cwd",
            "working_directory",
            "workingDirectory",
            "project_path",
            "projectPath",
            "work_dir",
            "workDir",
            "directory",
            "dir",
            "folder",
            "workspace",
            "workspace_path",
            "workspacePath",
        ],
    )
    .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["cwd"]))
    .or_else(|| context.and_then(|c| c.cwd.clone()))
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
        .or_else(|| str_field(value.get("payload").unwrap_or(&Value::Null), &["id", "session_id", "sessionId"]))
        .or_else(|| context.and_then(|c| c.session_id.clone())),
        message_id: str_field(value, &["message_id", "messageId", "id"]),
        request_id: str_field(value, &["request_id", "requestId"]),
        model,
        event_type,
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
    custom_root: Option<&Path>,
) -> Result<bool, String> {
    ensure_provider(conn, &event.provider_id, provider_display_name(&event.provider_id))
        .map_err(to_string)?;

    // Resolve project path, honoring a user-configured project root.
    let project_path = resolve_event_project_path(&event, file_path, custom_root);
    let project_id = match project_path {
        Some(path) => Some(upsert_project(
            conn,
            &event.provider_id,
            &path,
            &event.timestamp,
        )?),
        None => None,
    };
    let conversation_id = upsert_conversation(conn, &event, project_id.as_deref())?;
    let pricing = find_pricing(conn, &event.provider_id, event.model.as_deref())?;
    let (cost, pricing_id, pricing_match) = if let Some(p) = pricing {
        (
            Some(calculate_cost(&event, &p)),
            Some(p.id),
            "exact".to_string(),
        )
    } else {
        (None, None, "missing".to_string())
    };
    // Deduplicate: when request_id or message_id is present, use them as the
    // stable key instead of raw_record_hash. This prevents duplicate events
    // when a provider logs the same API call multiple times with different
    // content (e.g. Claude Code text + tool_use split across two JSONL lines).
    let id = if event.request_id.is_some() || event.message_id.is_some() {
        hash(&format!(
            "{}|{}|{:?}|{:?}",
            source.provider_id,
            file_path.to_string_lossy(),
            event.request_id,
            event.message_id,
        ))
    } else {
        hash(&format!(
            "{}|{}|{:?}|{:?}|{:?}|{}",
            source.provider_id,
            file_path.to_string_lossy(),
            event.source_offset,
            event.request_id,
            event.message_id,
            event.raw_record_hash
        ))
    };
    let now = now();
    let changed = conn
        .execute(
            "INSERT OR IGNORE INTO usage_events
            (id, provider_id, product_id, source_id, parser_id, parser_version, timestamp, project_id, conversation_id,
             message_id, request_id, model, event_type, input_tokens, output_tokens, cached_input_tokens, cache_write_tokens,
             cache_read_tokens, reasoning_tokens, tool_tokens, unknown_tokens, official_api_cost_usd, pricing_catalog_id,
             pricing_match_confidence, source_file_path, source_file_modified_at, source_offset, source_hash,
             raw_record_hash, source_project_path, confidence, warnings_json, created_at, updated_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17, ?18,
             ?19, ?20, ?21, ?22, ?23, ?24, ?25, ?26, ?27, ?28, ?29, ?30, ?31, ?32, ?33, ?33)",
            params![
                id,
                event.provider_id,
                event.product_id,
                source.id,
                source.parser_id,
                PARSER_VERSION,
                event.timestamp,
                project_id,
                conversation_id,
                event.message_id,
                event.request_id,
                event.model,
                event.event_type,
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
                event.project_path.as_deref(),
                event.confidence,
                serde_json::to_string(&event.warnings).unwrap_or_else(|_| "[]".into()),
                now
            ],
        )
        .map_err(to_string)?;
    Ok(changed > 0)
}

fn upsert_project(
    conn: &Connection,
    provider_id: &str,
    path: &str,
    timestamp: &str,
) -> Result<String, String> {
    let id = hash(&format!("{provider_id}|{}", path.to_ascii_lowercase()));
    let auto_display = Path::new(path)
        .file_name()
        .and_then(|s| s.to_str())
        .filter(|s| !s.is_empty())
        .unwrap_or(path)
        .to_string();
    let display: String = conn
        .query_row(
            "SELECT custom_name FROM project_management WHERE id = ?1 AND custom_name IS NOT NULL",
            params![id],
            |r| r.get::<_, String>(0),
        )
        .unwrap_or(auto_display);
    let now = now();
    conn.execute(
        "INSERT INTO projects (id, provider_id, display_name, path, normalized_path_hash, first_seen_at, last_seen_at, created_at, updated_at)
         VALUES (?1, ?2, ?3, ?4, ?1, ?5, ?5, ?6, ?6)
         ON CONFLICT(id) DO UPDATE SET display_name = CASE WHEN excluded.display_name != display_name THEN excluded.display_name ELSE display_name END,
                                          last_seen_at = MAX(last_seen_at, excluded.last_seen_at), updated_at = excluded.updated_at",
        params![id, provider_id, display, path, timestamp, now],
    )
    .map_err(to_string)?;
    Ok(id)
}

fn apply_project_management(conn: &Connection) -> rusqlite::Result<()> {
    // Apply custom names to projects table.
    conn.execute(
        "UPDATE projects
         SET display_name = (
             SELECT pm.custom_name FROM project_management pm
             WHERE pm.id = projects.id AND pm.custom_name IS NOT NULL
         )
         WHERE id IN (
             SELECT id FROM project_management WHERE custom_name IS NOT NULL
         )",
        [],
    )?;

    // Apply merges: redirect events and conversations to the target project.
    // Keep the source project row intact so unmerge can restore it.
    let mut stmt = conn.prepare(
        "SELECT id, merged_into_project_id FROM project_management
         WHERE merged_into_project_id IS NOT NULL"
    )?;
    let merges: Vec<(String, String)> = stmt
        .query_map([], |r| Ok((r.get(0)?, r.get(1)?)))?
        .collect::<Result<Vec<_>, _>>()?;
    drop(stmt);

    for (source_id, target_id) in merges {
        conn.execute(
            "UPDATE usage_events SET project_id = ?1, merged_from_project_id = COALESCE(merged_from_project_id, ?2) WHERE project_id = ?2",
            params![target_id, source_id],
        )?;
        conn.execute(
            "UPDATE conversations SET project_id = ?1, merged_from_project_id = COALESCE(merged_from_project_id, ?2) WHERE project_id = ?2",
            params![target_id, source_id],
        )?;
        // Do not delete the source project row; unmerge relies on its existence.
    }

    Ok(())
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
    find_pricing_sql(conn, provider_id, model).map_err(to_string)
}

fn find_pricing_sql(
    conn: &Connection,
    provider_id: &str,
    model: Option<&str>,
) -> rusqlite::Result<Option<Pricing>> {
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
        ?;
    if exact.is_some() {
        return Ok(exact);
    }
    let mut stmt = conn
        .prepare(
            "SELECT id, input_per_1m, output_per_1m, cached_input_per_1m, cache_write_per_1m, cache_read_per_1m, reasoning_per_1m, tool_per_1m, aliases_json
             FROM pricing_catalogs WHERE provider_id = ?1",
        )
        ?;
    let mut rows = stmt.query(params![provider_id])?;
    while let Some(row) = rows.next()? {
        let aliases_json: String = row.get(8)?;
        let aliases: Vec<String> = serde_json::from_str(&aliases_json).unwrap_or_default();
        if aliases.iter().any(|a| a.eq_ignore_ascii_case(model)) {
            return Ok(Some(Pricing {
                id: row.get(0)?,
                input_per_1m: row.get(1)?,
                output_per_1m: row.get(2)?,
                cached_input_per_1m: row.get(3)?,
                cache_write_per_1m: row.get(4)?,
                cache_read_per_1m: row.get(5)?,
                reasoning_per_1m: row.get(6)?,
                tool_per_1m: row.get(7)?,
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
    let parts = calculate_cost_parts(event, pricing);
    parts.0 + parts.1 + parts.2 + parts.3
}

fn calculate_cost_parts(event: &ParsedEvent, pricing: &Pricing) -> (f64, f64, f64, f64) {
    let million = 1_000_000.0;

    // Provider-specific token counting semantics:
    //
    // Anthropic / Claude:
    //   - input_tokens = ONLY uncached/new tokens (disjoint from cache)
    //   - cache_read_input_tokens and cache_creation_input_tokens are separate
    //   → NO subtraction needed
    //
    // OpenAI, Kimi/Moonshot, Gemini, DeepSeek, and most OpenAI-compatible APIs:
    //   - prompt_tokens / input_tokens = TOTAL including cached subset
    //   - cached_tokens / cachedContentTokenCount = subset of input
    //   → MUST subtract cached from input to avoid double-counting
    //
    // Heuristic: if we see Anthropic-style cache_read/cache_write fields,
    // assume input is already uncached. Otherwise, if cached_input_tokens > 0,
    // subtract it from input_tokens.
    let has_anthropic_style_cache =
        event.cache_read_tokens > 0 || event.cache_write_tokens > 0;
    let effective_input = if !has_anthropic_style_cache && event.cached_input_tokens > 0 {
        (event.input_tokens - event.cached_input_tokens).max(0)
    } else {
        event.input_tokens
    };

    let input_cost = (effective_input as f64 / million) * pricing.input_per_1m.unwrap_or(0.0);
    let output_cost = (event.output_tokens as f64 / million) * pricing.output_per_1m.unwrap_or(0.0);
    let cached_cost = (event.cached_input_tokens as f64 / million)
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
                .unwrap_or(pricing.cached_input_per_1m.unwrap_or(0.0));
    let other_cost = (event.reasoning_tokens as f64 / million)
        * pricing
            .reasoning_per_1m
            .unwrap_or(pricing.output_per_1m.unwrap_or(0.0))
        + (event.tool_tokens as f64 / million)
            * pricing
                .tool_per_1m
                .unwrap_or(pricing.input_per_1m.unwrap_or(0.0));

    (input_cost, output_cost, cached_cost, other_cost)
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
             MIN(u.timestamp), MAX(u.timestamp)
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
                first_seen: r.get(13)?,
                last_seen: r.get(14)?,
            })
        })
        .map_err(to_string)?;
    rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
}

fn query_recent_sessions(conn: &Connection, provider_filter: Option<&str>, offset: usize, limit: usize) -> Result<(Vec<SessionSummary>, i64), String> {
    let where_clause = if provider_filter.is_some() { "WHERE u.provider_id = ?1" } else { "" };
    let count_sql = format!(
        "SELECT COUNT(*) FROM usage_events u {}",
        where_clause
    );
    let total_count: i64 = if let Some(pid) = provider_filter {
        conn.query_row(&count_sql, [pid], |r| r.get(0))
    } else {
        conn.query_row(&count_sql, [], |r| r.get(0))
    }.map_err(to_string)?;

    let sql = format!(
        "SELECT u.id, u.provider_id, pr.display_name, pr.path, u.model, u.event_type, u.timestamp,
         u.input_tokens, (u.input_tokens - u.cached_input_tokens), u.output_tokens,
         (u.cached_input_tokens + u.cache_write_tokens + u.cache_read_tokens),
         ((CASE WHEN u.cache_read_tokens > 0 OR u.cache_write_tokens > 0 THEN u.input_tokens ELSE max(u.input_tokens - u.cached_input_tokens, 0) END) + u.output_tokens + u.cached_input_tokens + u.cache_write_tokens + u.cache_read_tokens + u.reasoning_tokens + u.tool_tokens + u.unknown_tokens),
         u.official_api_cost_usd, u.confidence,
         u.cached_input_tokens, u.cache_write_tokens, u.cache_read_tokens, u.reasoning_tokens, u.tool_tokens, u.unknown_tokens
         FROM usage_events u LEFT JOIN projects pr ON pr.id = u.project_id
         {}
         ORDER BY u.timestamp DESC LIMIT ?{} OFFSET ?{}",
        where_clause,
        if provider_filter.is_some() { 2 } else { 1 },
        if provider_filter.is_some() { 3 } else { 2 }
    );
    let mut stmt = conn.prepare(&sql).map_err(to_string)?;
    let map_row = |r: &rusqlite::Row| -> rusqlite::Result<SessionSummary> {
        let provider_id: String = r.get(1)?;
        let model: Option<String> = r.get(4)?;
        let event = ParsedEvent {
            provider_id: provider_id.clone(),
            product_id: None,
            timestamp: r.get(6)?,
            project_path: None,
            conversation_id: None,
            message_id: None,
            request_id: None,
            model: model.clone(),
            event_type: r.get(5)?,
            input_tokens: r.get(7)?,
            output_tokens: r.get(9)?,
            cached_input_tokens: r.get(14)?,
            cache_write_tokens: r.get(15)?,
            cache_read_tokens: r.get(16)?,
            reasoning_tokens: r.get(17)?,
            tool_tokens: r.get(18)?,
            unknown_tokens: r.get(19)?,
            source_offset: None,
            raw_record_hash: String::new(),
            confidence: String::new(),
            warnings: vec![],
        };
        let cost_parts = find_pricing_sql(conn, &provider_id, model.as_deref())
            .ok()
            .flatten()
            .map(|pricing| calculate_cost_parts(&event, &pricing));
        Ok(SessionSummary {
            id: r.get(0)?,
            provider_id,
            project_name: r.get(2)?,
            project_path: r.get(3)?,
            model,
            event_type: r.get(5)?,
            timestamp: r.get(6)?,
            input_tokens: r.get(7)?,
            effective_input_tokens: (event.input_tokens
                - if event.cache_read_tokens > 0 || event.cache_write_tokens > 0 { 0 } else { event.cached_input_tokens })
                .max(0),
            output_tokens: r.get(9)?,
            cached_tokens: r.get(10)?,
            total_tokens: r.get(11)?,
            input_cost: cost_parts.map(|parts| parts.0),
            output_cost: cost_parts.map(|parts| parts.1),
            cached_cost: cost_parts.map(|parts| parts.2),
            other_cost: cost_parts.map(|parts| parts.3),
            api_equivalent_cost: r.get(12)?,
            confidence: r.get(13)?,
        })
    };
    let rows = if let Some(pid) = provider_filter {
        stmt.query_map(rusqlite::params![pid, limit as i64, offset as i64], map_row)
    } else {
        stmt.query_map(rusqlite::params![limit as i64, offset as i64], map_row)
    }.map_err(to_string)?;
    let sessions = rows.collect::<Result<Vec<_>, _>>().map_err(to_string)?;
    Ok((sessions, total_count))
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
        self.total_tokens = (self.input_tokens - self.cached_input_tokens).max(0)
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
            return number.min(i64::MAX as u64) as i64;
        }
    }
    0
}

fn str_field(value: &Value, names: &[&str]) -> Option<String> {
    for name in names {
        if let Some(text) = value.get(*name).and_then(|v| v.as_str()) {
            return Some(text.to_string());
        }
    }
    None
}

fn timestamp_field(value: &Value, names: &[&str]) -> Option<String> {
    for name in names {
        let Some(field) = value.get(*name) else {
            continue;
        };
        match field {
            Value::String(text) => {
                if let Some(timestamp) = parse_unix_timestamp(text.parse::<f64>().ok()) {
                    return Some(timestamp);
                }
                if !text.trim().is_empty() {
                    return Some(text.to_string());
                }
            }
            Value::Number(number) => {
                if let Some(timestamp) = parse_unix_timestamp(number.as_f64()) {
                    return Some(timestamp);
                }
            }
            _ => {}
        }
    }
    None
}

fn parse_unix_timestamp(value: Option<f64>) -> Option<String> {
    let mut seconds = value?;
    if !seconds.is_finite() || seconds <= 0.0 {
        return None;
    }
    if seconds > 1_000_000_000_000_000.0 {
        seconds /= 1_000_000.0;
    } else if seconds > 1_000_000_000_000.0 {
        seconds /= 1000.0;
    }

    let mut whole_seconds = seconds.floor() as i64;
    let mut nanos = (((seconds - whole_seconds as f64) * 1_000_000.0).round() as u32) * 1000;
    if nanos >= 1_000_000_000 {
        whole_seconds += 1;
        nanos = 0;
    }

    Utc.timestamp_opt(whole_seconds, nanos)
        .single()
        .map(|timestamp| timestamp.to_rfc3339())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn timestamp_field_accepts_unix_seconds_with_fraction() {
        let value = json!({"timestamp": 1777317104.005388});

        let timestamp = timestamp_field(&value, &["timestamp"]).unwrap();

        assert!(timestamp.starts_with("2026-04-27T19:11:44.005388"));
    }

    #[test]
    fn timestamp_field_accepts_unix_milliseconds() {
        let value = json!({"timestamp": 1777317104005.0});

        assert_eq!(
            timestamp_field(&value, &["timestamp"]),
            Some("2026-04-27T19:11:44.005+00:00".to_string())
        );
    }
}

fn infer_project_from_path(path: &Path) -> Option<String> {
    // For Kimi: session folder name is MD5 of working directory
    if let Some(project) = kimi_project_from_path(path) {
        return Some(project);
    }
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
    // Don't return raw parent paths for dot-tool dirs — they produce UUIDs or junk names
    let path_str = path.to_string_lossy();
    if path_str.contains("/.kimi/") || path_str.contains("/.codex/") || path_str.contains("/.claude/") {
        return None;
    }
    path.parent()
        .and_then(|p| p.to_str())
        .map(|s| s.to_string())
}

/// Kimi stores sessions at ~/.kimi/sessions/<md5(workdir)>/<conv>/wire.jsonl
/// Read ~/.kimi/kimi.json work_dirs and map session hash -> project name.
fn kimi_project_from_path(path: &Path) -> Option<String> {
    let path_str = path.to_string_lossy();
    if !path_str.contains("/.kimi/sessions/") {
        return None;
    }
    // Extract session hash from path: .../.kimi/sessions/<hash>/...
    let parts: Vec<&str> = path_str.split('/').collect();
    let session_idx = parts.iter().position(|p| *p == ".kimi")?;
    let sessions_idx = parts.get(session_idx + 1)?;
    if *sessions_idx != "sessions" {
        return None;
    }
    let session_hash = parts.get(session_idx + 2)?;
    let work_dirs = kimi_work_dirs()?;
    for work_dir in work_dirs {
        let hash = format!("{:x}", md5::compute(&work_dir));
        if hash == *session_hash {
            return project_root_from_path(Path::new(&work_dir));
        }
    }
    None
}

fn kimi_work_dirs() -> Option<Vec<String>> {
    let home = dirs::home_dir()?;
    let config_path = home.join(".kimi").join("kimi.json");
    let content = std::fs::read_to_string(config_path).ok()?;
    let value: Value = serde_json::from_str(&content).ok()?;
    let mut dirs = Vec::new();
    if let Some(work_dirs) = value.get("work_dirs").and_then(|v| v.as_array()) {
        for entry in work_dirs {
            if let Some(path) = entry.get("path").and_then(|v| v.as_str()) {
                dirs.push(path.to_string());
            }
        }
    }
    if dirs.is_empty() {
        None
    } else {
        Some(dirs)
    }
}

fn find_project_root_by_markers(start_path: &Path) -> Option<String> {
    const MARKERS: &[&str] = &[
        ".git",
        "package.json",
        "Cargo.toml",
        "pyproject.toml",
        "composer.json",
        "go.mod",
        "pom.xml",
        "build.gradle",
        "tsconfig.json",
    ];
    let mut current = Some(start_path);
    while let Some(dir) = current {
        if let Ok(entries) = std::fs::read_dir(dir) {
            for entry in entries.flatten() {
                if let Ok(meta) = entry.metadata() {
                    if meta.is_file() {
                        if let Some(name) = entry.file_name().to_str() {
                            if MARKERS.contains(&name) {
                                return dir.to_str().map(|s| s.to_string());
                            }
                        }
                    }
                }
            }
        }
        current = dir.parent();
    }
    None
}

fn is_dot_tool_dir(name: &str) -> bool {
    matches!(
        name.to_ascii_lowercase().as_str(),
        ".nvm" | ".cargo" | ".rustup" | ".npm" | ".yarn" | ".pnpm" | ".composer" | ".gem" | ".rbenv" | ".pyenv" | ".venv" | ".virtualenvs" | ".local" | ".config" | ".cache" | ".docker" | ".kube" | ".aws" | ".ssh" | ".gnupg" | ".m2" | ".gradle" | ".android" | ".cocoapods" | ".fastlane" | ".homebrew" | ".oh-my-zsh" | ".tldr" | ".tmux" | ".vim" | ".nvim" | ".emacs.d" | ".vscode" | ".cursor"
    )
}

fn project_root_from_path(path: &Path) -> Option<String> {
    // Reject paths that contain spaces (usually shell command fragments)
    if path.to_string_lossy().contains(' ') {
        return None;
    }

    // First: try marker-based detection for true project root
    if let Some(root) = find_project_root_by_markers(path) {
        let root_path = Path::new(&root);
        // Reject dot-tool directories like ~/.nvm
        if let Some(file_name) = root_path.file_name().and_then(|s| s.to_str()) {
            if is_dot_tool_dir(file_name) {
                return None;
            }
        }
        return Some(root);
    }
    // Fallback to heuristic path component analysis
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
        if !first.starts_with('.') && first != "Library" && first != "Downloads" && !is_dot_tool_dir(first) {
            return Some(join_path_parts(&parts[..=3]));
        }
    }
    None
}

fn expand_tilde(path: &str) -> PathBuf {
    if path.starts_with("~/") || path == "~" {
        dirs::home_dir()
            .map(|h| if path == "~" { h } else { h.join(&path[2..]) })
            .unwrap_or_else(|| PathBuf::from(path))
    } else {
        PathBuf::from(path)
    }
}

fn project_root_from_conn(conn: &Connection) -> Option<PathBuf> {
    let stored: Option<String> = conn
        .query_row(
            "SELECT project_root FROM sync_config WHERE id = 1",
            [],
            |r| r.get(0),
        )
        .ok()?;
    let stored = stored?;
    if stored.trim().is_empty() {
        return None;
    }
    Some(expand_tilde(&stored))
}

fn project_under_root(path: &Path, root: &Path) -> Option<String> {
    let stripped = path.strip_prefix(root).ok()?;
    let first = stripped.components().next()?;
    let name = first.as_os_str().to_string_lossy().to_string();
    if name.is_empty() || name.starts_with('.') {
        return None;
    }
    Some(root.join(&name).to_string_lossy().to_string())
}

fn resolve_event_project_path(
    event: &ParsedEvent,
    file_path: &Path,
    custom_root: Option<&Path>,
) -> Option<String> {
    let candidate = event
        .project_path
        .clone()
        .map(|p| expand_tilde(&p).to_string_lossy().to_string())
        .or_else(|| infer_project_from_path(file_path));

    if let Some(root) = custom_root {
        // If the candidate is inside the configured root, truncate to root/<first_dir>.
        if let Some(c) = candidate.as_deref().and_then(|p| project_under_root(Path::new(p), root)) {
            return Some(c);
        }
        // Otherwise try to derive directly from the file path under the root.
        if let Some(p) = project_under_root(file_path, root) {
            return Some(p);
        }
    }

    candidate
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
        "kimi" => "Kimi / Moonshot",
        "ollama" => "Ollama",
        "lmstudio" => "LM Studio",
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

/// Parse a serde_json::Value as f64, handling both numbers and numeric strings.
fn json_f64(value: Option<&Value>) -> Option<f64> {
    match value {
        Some(Value::Number(n)) => n.as_f64(),
        Some(Value::String(s)) => s.parse().ok(),
        _ => None,
    }
}
