//! Tauri desktop app: window setup and the commands the UI invokes.
//! Everything else lives in the shared core (`lib.rs`).

use super::*;
use tauri::{Emitter, Manager, State};

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
async fn detect_sources() -> Result<Vec<DetectedSource>, String> {
    tauri::async_runtime::spawn_blocking(|| {
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
    })
    .await
    .map_err(to_string)?
}

#[tauri::command]
async fn list_sources(state: State<'_, AppState>) -> Result<Vec<Source>, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        query_sources(&conn)
    })
    .await
}

#[tauri::command]
async fn add_source(state: State<'_, AppState>, input: AddSourceInput) -> Result<Source, String> {
    run_blocking(state, move |state| {
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
        conn.execute(
            "DELETE FROM dismissed_source_paths WHERE path_key = ?1",
            params![path_key(&path)],
        )
        .map_err(to_string)?;
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
    })
    .await
}

#[tauri::command]
async fn remove_source(state: State<'_, AppState>, source_id: String) -> Result<(), String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        remove_source_row(&conn, &source_id)
    })
    .await
}

#[tauri::command]
async fn clear_parsed_data(state: State<'_, AppState>) -> Result<(), String> {
    run_blocking(state, move |state| {
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
    })
    .await
}

#[tauri::command]
async fn list_projects(state: State<'_, AppState>) -> Result<Value, String> {
    run_blocking(state, project_list).await
}

#[tauri::command]
async fn rename_project(state: State<'_, AppState>, project_id: String, custom_name: Option<String>) -> Result<Value, String> {
    run_blocking(state, move |state| {
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
        project_list(state)
    })
    .await
}

#[tauri::command]
async fn merge_projects(state: State<'_, AppState>, target_project_id: String, source_project_ids: Vec<String>) -> Result<Value, String> {
    run_blocking(state, move |state| {
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
        project_list(state)
    })
    .await
}

#[tauri::command]
async fn unmerge_project(state: State<'_, AppState>, project_id: String) -> Result<Value, String> {
    run_blocking(state, move |state| {
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
        project_list(state)
    })
    .await
}

#[tauri::command]
async fn open_project_path(state: State<'_, AppState>, path: String) -> Result<(), String> {
    run_blocking(state, move |state| {
        let canonical = validate_project_path(&path, state)?;
        opener::open(&canonical).map_err(|e| format!("Failed to open folder: {}", e))
    })
    .await
}

#[tauri::command]
async fn rescan_all(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        ensure_claude_shared_source(&conn)?;
        ensure_codex_sources(&conn)?;
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
        ensure_claude_shared_source(&conn)?;
        ensure_codex_sources(&conn)?;
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
async fn rescan_source(state: State<'_, AppState>, source_id: String) -> Result<Value, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        let source = query_source(&conn, &source_id)?;
        let imported = scan_source(&conn, &source, false)?;
        Ok(serde_json::json!({ "imported": imported }))
    })
    .await
}

#[tauri::command]
async fn get_dashboard_summary(state: State<'_, AppState>) -> Result<DashboardSummary, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        dashboard_summary(&conn)
    })
    .await
}

#[tauri::command]
async fn get_recent_sessions(
    state: State<'_, AppState>,
    provider_id: Option<String>,
    offset: usize,
    limit: usize,
) -> Result<RecentSessionsResult, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        let (sessions, total_count) = query_recent_sessions(&conn, provider_id.as_deref(), offset, limit)?;
        Ok(RecentSessionsResult { sessions, total_count })
    })
    .await
}

#[tauri::command]
async fn list_subscriptions(state: State<'_, AppState>) -> Result<Vec<Subscription>, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        let mut stmt = conn
            .prepare(
                "SELECT id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, enabled,
                 start_date, end_date, autorenew
                 FROM subscriptions ORDER BY provider_id, product_name, start_date",
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
                    start_date: r.get(7)?,
                    end_date: r.get(8)?,
                    autorenew: r.get::<_, i64>(9)? == 1,
                })
            })
            .map_err(to_string)?;
        rows.collect::<Result<Vec<_>, _>>().map_err(to_string)
    })
    .await
}

#[tauri::command]
async fn create_subscription(
    state: State<'_, AppState>,
    input: SubscriptionInput,
) -> Result<Subscription, String> {
    run_blocking(state, move |state| {
        let (start, end) = validate_subscription_input(&input)?;
        let conn = state.db.blocking_lock();
        ensure_provider(
            &conn,
            &input.provider_id,
            provider_display_name(&input.provider_id),
        )
        .map_err(to_string)?;
        if subscription_overlaps(
            &conn,
            &input.provider_id,
            &input.product_name,
            start,
            end,
            None,
        )? {
            return Err(
                "A subscription for this provider and account already overlaps the selected dates."
                    .to_string(),
            );
        }
        let id = Uuid::new_v4().to_string();
        let now = now();
        conn.execute(
            "INSERT INTO subscriptions
            (id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, billing_anchor_time,
             start_date, end_date, autorenew, enabled, created_at, updated_at)
            VALUES (?1, ?2, ?3, ?4, ?5, ?6, '00:00:00', ?7, ?8, ?9, 1, ?10, ?10)",
            params![
                id,
                input.provider_id,
                input.product_name,
                input.monthly_amount,
                input.currency,
                input.billing_anchor_day,
                input.start_date,
                input.end_date,
                if input.autorenew { 1 } else { 0 },
                now
            ],
        )
        .map_err(to_string)?;
        conn.query_row(
            "SELECT id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, enabled,
             start_date, end_date, autorenew
             FROM subscriptions WHERE id = ?1",
            params![id],
            row_to_subscription,
        )
        .map_err(to_string)
    })
    .await
}

#[tauri::command]
async fn update_subscription(
    state: State<'_, AppState>,
    id: String,
    input: SubscriptionInput,
) -> Result<Subscription, String> {
    run_blocking(state, move |state| {
        let (start, end) = validate_subscription_input(&input)?;
        let conn = state.db.blocking_lock();
        ensure_provider(
            &conn,
            &input.provider_id,
            provider_display_name(&input.provider_id),
        )
        .map_err(to_string)?;
        if subscription_overlaps(
            &conn,
            &input.provider_id,
            &input.product_name,
            start,
            end,
            Some(&id),
        )? {
            return Err(
                "A subscription for this provider and account already overlaps the selected dates."
                    .to_string(),
            );
        }
        conn.execute(
            "UPDATE subscriptions SET
             provider_id = ?1, product_name = ?2, monthly_amount = ?3, currency = ?4,
             billing_anchor_day = ?5, start_date = ?6, end_date = ?7, autorenew = ?8,
             updated_at = ?9
             WHERE id = ?10",
            params![
                input.provider_id,
                input.product_name,
                input.monthly_amount,
                input.currency,
                input.billing_anchor_day,
                input.start_date,
                input.end_date,
                if input.autorenew { 1 } else { 0 },
                now(),
                id
            ],
        )
        .map_err(to_string)?;
        conn.query_row(
            "SELECT id, provider_id, product_name, monthly_amount, currency, billing_anchor_day, enabled,
             start_date, end_date, autorenew
             FROM subscriptions WHERE id = ?1",
            params![id],
            row_to_subscription,
        )
        .map_err(to_string)
    })
    .await
}

#[tauri::command]
async fn delete_subscription(state: State<'_, AppState>, id: String) -> Result<(), String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        conn.execute("DELETE FROM subscriptions WHERE id = ?1", params![id])
            .map_err(to_string)?;
        Ok(())
    })
    .await
}

#[tauri::command]
async fn list_pricing_catalog(state: State<'_, AppState>) -> Result<Vec<Value>, String> {
    run_blocking(state, move |state| {
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
    })
    .await
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
async fn list_missing_models(state: State<'_, AppState>) -> Result<Vec<Value>, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        query_missing_models(&conn)
    })
    .await
}

#[tauri::command]
async fn pull_pricing(state: State<'_, AppState>) -> Result<Value, String> {
    let db = state.db.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let conn = db.blocking_lock();
        let count = pull_pricing_from_server(&conn)?;
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
                 reasoning_per_1m, tool_per_1m, source_url, catalog_version, effective_from
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
                    "effective_from": r.get::<_, Option<String>>(12)?,
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

#[tauri::command]
async fn configure_sync_server(state: State<'_, AppState>, server_url: String) -> Result<SyncStatus, String> {
    run_blocking(state, move |state| {
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
    })
    .await
}

#[tauri::command]
async fn get_project_root(state: State<'_, AppState>) -> Result<Option<String>, String> {
    run_blocking(state, move |state| {
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
    })
    .await
}

#[tauri::command]
async fn set_project_root(state: State<'_, AppState>, project_root: Option<String>) -> Result<SyncStatus, String> {
    run_blocking(state, move |state| {
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
    })
    .await
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
        login_with_password(&conn, &input, None)
    })
    .await
    .map_err(|e| e.to_string())?
}

#[tauri::command]
async fn logout_sync(state: State<'_, AppState>) -> Result<SyncStatus, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        logout(&conn)
    })
    .await
}

#[tauri::command]
async fn get_sync_status(state: State<'_, AppState>) -> Result<SyncStatus, String> {
    run_blocking(state, move |state| {
        let conn = state.db.blocking_lock();
        get_sync_config(&conn)
    })
    .await
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
        let _ = ensure_subscription_renewals(&conn).map_err(|e| e.to_string());
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
        let _ = ensure_subscription_renewals(&conn).map_err(|e| e.to_string());
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

#[tauri::command]
async fn debug_sync_state(state: State<'_, AppState>) -> Result<Value, String> {
    run_blocking(state, move |state| {
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
    })
    .await
}

/// Runs a command's DB work on the blocking pool: sync commands run on the main
/// thread, so waiting there for the DB lock during a scan freezes the window.
async fn run_blocking<T: Send + 'static>(
    state: State<'_, AppState>,
    f: impl FnOnce(&AppState) -> Result<T, String> + Send + 'static,
) -> Result<T, String> {
    let state = state.inner().clone();
    tauri::async_runtime::spawn_blocking(move || f(&state))
        .await
        .map_err(to_string)?
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
            configure_connection(&conn)?;
            migrate(&conn)?;
            seed_defaults(&conn)?;
            ensure_subscription_renewals(&conn).map_err(|e| Box::new(e) as Box<dyn std::error::Error>)?;
            ensure_claude_shared_source(&conn).map_err(|e| Box::new(std::io::Error::other(e)) as Box<dyn std::error::Error>)?;
            ensure_codex_sources(&conn).map_err(|e| Box::new(std::io::Error::other(e)) as Box<dyn std::error::Error>)?;
            let db = Arc::new(Mutex::new(conn));
            app.manage(AppState {
                db: db.clone(),
            });
            // Run expensive maintenance in background, sharing the app mutex so it
            // cannot race a scan through a second SQLite connection.
            tauri::async_runtime::spawn_blocking(move || {
                let conn = db.blocking_lock();
                let _ = cleanup_known_bad_imports(&conn);
                let _ = recalculate_event_costs(&conn);
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
            update_subscription,
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
