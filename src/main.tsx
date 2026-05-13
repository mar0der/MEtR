import React, { useEffect, useMemo, useState } from "react";
import { createRoot } from "react-dom/client";
import { invoke } from "@tauri-apps/api/core";
import { getVersion } from "@tauri-apps/api/app";
import {
  Activity,
  Cloud,
  Database,
  FolderPlus,
  FolderOpen,
  RefreshCw,
  Settings,
  ShieldCheck,
  Trash2,
  WalletCards,
  Download,
  AlertCircle
} from "lucide-react";
import "./styles.css";
import { checkForUpdates } from "./updater";

type UsageTotals = {
  input_tokens: number;
  output_tokens: number;
  cached_input_tokens: number;
  cache_write_tokens: number;
  cache_read_tokens: number;
  reasoning_tokens: number;
  tool_tokens: number;
  unknown_tokens: number;
  total_tokens: number;
};

type ProviderSummary = {
  provider_id: string;
  display_name: string;
  totals: UsageTotals;
  api_equivalent_cost: number;
  subscription_amount: number;
  net_savings_vs_api: number;
  source_count: number;
  last_seen: string | null;
};

type ProjectSummary = {
  id: string;
  provider_id: string;
  display_name: string;
  path: string | null;
  totals: UsageTotals;
  api_equivalent_cost: number;
  first_seen: string | null;
  last_seen: string | null;
};

type SessionSummary = {
  id: string;
  provider_id: string;
  project_name: string | null;
  model: string | null;
  event_type: string | null;
  timestamp: string;
  input_tokens: number;
  effective_input_tokens: number;
  output_tokens: number;
  cached_tokens: number;
  total_tokens: number;
  input_cost: number | null;
  output_cost: number | null;
  cached_cost: number | null;
  other_cost: number | null;
  api_equivalent_cost: number | null;
  confidence: string;
};

type PaginatedSessions = {
  sessions: SessionSummary[];
  total_count: number;
};

type DashboardSummary = {
  providers: ProviderSummary[];
  totals: UsageTotals;
  subscriptions_total: number;
  api_equivalent_total: number;
  net_savings_vs_api: number;
  break_even_progress: number | null;
  top_projects: ProjectSummary[];
  recent_sessions: SessionSummary[];
};

type Source = {
  id: string;
  provider_id: string;
  parser_id: string;
  display_name: string;
  path: string;
  enabled: boolean;
  detection_confidence: string;
  last_scan_status: string | null;
  last_scan_message: string | null;
};

type DetectedSource = {
  provider_id: string;
  parser_id: string;
  display_name: string;
  path: string;
  confidence: string;
  found_file_count: number;
  notes: string;
};

type Subscription = {
  id: string;
  provider_id: string;
  product_name: string;
  monthly_amount: number;
  currency: string;
  billing_anchor_day: number;
  enabled: boolean;
};

type PricingEntry = {
  id: string;
  provider_id: string;
  model: string;
  aliases: string[];
  input_per_1m: number | null;
  output_per_1m: number | null;
  cached_input_per_1m: number | null;
  cache_write_per_1m: number | null;
  cache_read_per_1m: number | null;
  source_url: string | null;
};

type MissingModel = {
  provider_id: string;
  model: string;
  event_count: number;
};

type SyncStatus = {
  configured: boolean;
  server_url: string;
  logged_in: boolean;
  username: string | null;
  device_name: string | null;
  last_sync_at: string | null;
  pending_events: number;
  sync_enabled: boolean;
};

type SyncResult = {
  uploaded: number;
  batches: number;
  subscriptions_uploaded: number;
  errors: string[];
};

type Tab = "all" | "settings" | string;
type SubscriptionForm = {
  provider_id: string;
  product_name: string;
  monthly_amount: string;
  currency: string;
  billing_anchor_day: string;
};

function providerForTab(tab: Tab): string | undefined {
  return tab === "all" || tab === "settings" ? undefined : tab;
}

const demoSummary: DashboardSummary = {
  providers: [],
  totals: emptyTotals(),
  subscriptions_total: 0,
  api_equivalent_total: 0,
  net_savings_vs_api: 0,
  break_even_progress: null,
  top_projects: [],
  recent_sessions: []
};

function App() {
  const [summary, setSummary] = useState<DashboardSummary>(demoSummary);
  const [sources, setSources] = useState<Source[]>([]);
  const [detected, setDetected] = useState<DetectedSource[]>([]);
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [pricing, setPricing] = useState<PricingEntry[]>([]);
  const [missingModels, setMissingModels] = useState<MissingModel[]>([]);
  const [activeTab, setActiveTab] = useState<Tab>("all");
  const [status, setStatus] = useState("Ready");
  const [loading, setLoading] = useState(false);
  const [manualPath, setManualPath] = useState("");
  const [subForm, setSubForm] = useState({
    provider_id: "openai",
    product_name: "ChatGPT",
    monthly_amount: "20",
    currency: "USD",
    billing_anchor_day: "13"
  });
  const [syncStatus, setSyncStatus] = useState<SyncStatus | null>(null);
  const [syncForm, setSyncForm] = useState({
    server_url: "https://metr.petarpetkov.com",
    login: "",
    password: ""
  });
  const [syncLoading, setSyncLoading] = useState(false);
  const [pricingLoading, setPricingLoading] = useState(false);
  const [appVersion, setAppVersion] = useState<string>("");
  const [newPriceForm, setNewPriceForm] = useState<Record<string, { input: string; output: string }>>({});
  const [paginatedSessions, setPaginatedSessions] = useState<PaginatedSessions>({ sessions: [], total_count: 0 });
  const [sessionPage, setSessionPage] = useState(1);
  const SESSIONS_PER_PAGE = 50;

  // Refs to avoid stale closures in setInterval
  const activeTabRef = React.useRef(activeTab);
  const sessionPageRef = React.useRef(sessionPage);
  activeTabRef.current = activeTab;
  sessionPageRef.current = sessionPage;

  // Ref to guard against out-of-order fetchSessions responses
  const sessionRequestIdRef = React.useRef(0);
  const previousSessionTabRef = React.useRef(activeTab);

  const fetchSessions = async (page: number, provider?: string) => {
    const requestId = ++sessionRequestIdRef.current;
    const requestedProvider = provider ?? null;
    try {
      const result = await api<PaginatedSessions>("get_recent_sessions", {
        providerId: requestedProvider,
        offset: (page - 1) * SESSIONS_PER_PAGE,
        limit: SESSIONS_PER_PAGE,
      });
      // Ignore stale responses that no longer match the visible tab/page.
      if (
        sessionRequestIdRef.current !== requestId ||
        requestedProvider !== (providerForTab(activeTabRef.current) ?? null) ||
        page !== sessionPageRef.current
      ) {
        return;
      }
      setPaginatedSessions(result);
    } catch (error) {
      setStatus(message(error));
    }
  };

  const refresh = async (showBusy = true) => {
    if (showBusy) setLoading(true);
    try {
      const [nextSummary, nextSources, nextSubs, nextPricing, nextMissing, nextSync] = await Promise.all([
        api<DashboardSummary>("get_dashboard_summary"),
        api<Source[]>("list_sources"),
        api<Subscription[]>("list_subscriptions"),
        api<PricingEntry[]>("list_pricing_catalog"),
        api<MissingModel[]>("list_missing_models"),
        api<SyncStatus>("get_sync_status")
      ]);
      setSummary(nextSummary);
      setSources(nextSources);
      setSubscriptions(nextSubs);
      setPricing(nextPricing);
      setMissingModels(nextMissing);
      setSyncStatus(nextSync);
      if (nextSync.logged_in && nextSync.server_url) {
        setSyncForm((s) => ({ ...s, server_url: nextSync.server_url }));
      }
      await fetchSessions(sessionPageRef.current, providerForTab(activeTabRef.current));
      setStatus("Data refreshed");
    } catch (error) {
      setStatus(message(error));
    } finally {
      if (showBusy) setLoading(false);
    }
  };

  useEffect(() => {
    // Defer initial data load so the UI renders first (prevents white screen on slow DB ops)
    const initTimer = window.setTimeout(() => {
      refresh(false).catch((err) => {
        console.error("[MEtR] Initial refresh failed:", err);
        setStatus("Failed to load data. Try restarting the app.");
      });
    }, 100);
    getVersion().then((v) => setAppVersion(v)).catch(() => setAppVersion(""));
    return () => clearTimeout(initTimer);
  }, []);

  useEffect(() => {
    const refreshId = window.setInterval(() => {
      void refresh(false);
    }, 30_000);
    const rescanId = window.setInterval(async () => {
      try {
        await api("rescan_all");
        await refresh(false);
      } catch {
        // Keep background polling quiet in the UI; manual controls still show status.
      }
    }, 300_000);
    const updateCheckId = window.setTimeout(() => {
      void checkForUpdates(false);
    }, 5000);
    return () => {
      window.clearInterval(refreshId);
      window.clearInterval(rescanId);
      window.clearTimeout(updateCheckId);
    };
  }, []);

  const providerTabs = useMemo(() => {
    const ids = new Set<string>();
    (summary.providers || []).forEach((p) => ids.add(p.provider_id));
    (sources || []).forEach((s) => ids.add(s.provider_id));
    return Array.from(ids).map((id) => ({
      id,
      label: summary.providers.find((p) => p.provider_id === id)?.display_name ?? id
    }));
  }, [summary.providers, sources]);

  const activeProvider = summary.providers.find((p) => p.provider_id === activeTab);
  const filteredProjects =
    activeTab === "all" || activeTab === "settings"
      ? summary.top_projects
      : summary.top_projects.filter((p) => p.provider_id === activeTab);

  useEffect(() => {
    if (activeTab === "settings") {
      return;
    }

    if (previousSessionTabRef.current !== activeTab) {
      previousSessionTabRef.current = activeTab;
      sessionRequestIdRef.current += 1;
      setPaginatedSessions({ sessions: [], total_count: 0 });
      if (sessionPage !== 1) {
        setSessionPage(1);
        return;
      }
    }

    fetchSessions(sessionPage, providerForTab(activeTab));
  }, [activeTab, sessionPage]);

  const runDetection = async () => {
    setLoading(true);
    try {
      const result = await api<DetectedSource[]>("detect_sources");
      setDetected(result);
      setStatus(result.length ? `Detected ${result.length} source folder(s)` : "No standard source folders found");
    } catch (error) {
      setStatus(message(error));
    } finally {
      setLoading(false);
    }
  };

  const addDetected = async (source: DetectedSource) => {
    await api("add_source", {
      input: {
        path: source.path,
        provider_id: source.provider_id,
        parser_id: source.parser_id,
        display_name: source.display_name
      }
    });
    await refresh(false);
  };

  const addManual = async () => {
    if (!manualPath.trim()) return;
    await api("add_source", { input: { path: manualPath.trim() } });
    setManualPath("");
    await refresh(false);
  };

  const rescanAll = async () => {
    setLoading(true);
    setStatus("Scanning new and changed files...");
    try {
      const result = await api<{ imported: number }>("rescan_all");
      setStatus(`Scan complete. Imported ${result.imported} new event(s).`);
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setLoading(false);
    }
  };

  const fullRescanAll = async () => {
    setLoading(true);
    setStatus("Full rescan running...");
    try {
      const result = await api<{ imported: number }>("rescan_all_full");
      setStatus(`Full rescan complete. Imported ${result.imported} new event(s).`);
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setLoading(false);
    }
  };

  const clearAllData = async () => {
    if (!window.confirm("WARNING: This will permanently delete all events, projects, conversations, and scan history. Your source folders and subscriptions will remain. This cannot be undone.")) {
      return;
    }
    setLoading(true);
    setStatus("Clearing all data...");
    try {
      await api("clear_parsed_data");
      setStatus("All data cleared. Run Full Rescan to rebuild from source files.");
      setPaginatedSessions({ sessions: [], total_count: 0 });
      setSessionPage(1);
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setLoading(false);
    }
  };

  const addSubscription = async () => {
    await api("create_subscription", {
      input: {
        provider_id: subForm.provider_id,
        product_name: subForm.product_name,
        monthly_amount: Number(subForm.monthly_amount),
        currency: subForm.currency,
        billing_anchor_day: Number(subForm.billing_anchor_day)
      }
    });
    await refresh(false);
  };

  const doLogin = async () => {
    setSyncLoading(true);
    try {
      const result = await api<SyncStatus>("login_sync", {
        input: {
          login: syncForm.login,
          password: syncForm.password,
          server_url: syncForm.server_url
        }
      });
      setSyncStatus(result);
      setStatus(`Logged in as ${result.username ?? syncForm.login}`);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setSyncLoading(false);
    }
  };

  const doLogout = async () => {
    setSyncLoading(true);
    try {
      const result = await api<SyncStatus>("logout_sync");
      setSyncStatus(result);
      setStatus("Logged out");
    } catch (error) {
      setStatus(message(error));
    } finally {
      setSyncLoading(false);
    }
  };

  const doSync = async () => {
    setSyncLoading(true);
    setStatus("Syncing...");
    try {
      const result = await api<SyncResult>("sync_now");
      const warning = result.errors.length ? `, ${result.errors.length} warning(s)` : "";
      setStatus(`Synced ${result.uploaded} event(s), ${result.subscriptions_uploaded} subscription(s)${warning}`);
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setSyncLoading(false);
    }
  };

  const doFullResync = async () => {
    if (!window.confirm("Full resync will re-upload local events to repair server-side project, model, and cost fields. Continue?")) {
      return;
    }
    setSyncLoading(true);
    setStatus("Running full resync...");
    try {
      const result = await api<SyncResult>("full_resync");
      const warning = result.errors.length ? `, ${result.errors.length} warning(s)` : "";
      setStatus(`Full resync sent ${result.uploaded} event(s), ${result.subscriptions_uploaded} subscription(s)${warning}`);
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setSyncLoading(false);
    }
  };

  const doPullPricing = async () => {
    setPricingLoading(true);
    setStatus("Pulling prices from server...");
    try {
      const result = await api<{ pulled: number }>("pull_pricing");
      setStatus(`Pulled ${result.pulled} price(s) from server`);
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setPricingLoading(false);
    }
  };

  const doPushPricing = async () => {
    setPricingLoading(true);
    setStatus("Pushing local prices to server...");
    try {
      const result = await api<{ pushed: number }>("push_pricing");
      setStatus(`Pushed ${result.pushed} price(s) to server`);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setPricingLoading(false);
    }
  };

  const addLocalPricing = async (providerId: string, model: string) => {
    const key = `${providerId}::${model}`;
    const form = newPriceForm[key];
    if (!form || !form.input || !form.output) return;
    setPricingLoading(true);
    setStatus(`Adding price for ${model}...`);
    try {
      await api("add_pricing", {
        input: {
          provider_id: providerId,
          model: model,
          input_per_1m: Number(form.input),
          output_per_1m: Number(form.output),
        }
      });
      setStatus(`Price added for ${model}`);
      setNewPriceForm((prev) => {
        const next = { ...prev };
        delete next[key];
        return next;
      });
      await refresh(false);
    } catch (error) {
      setStatus(message(error));
    } finally {
      setPricingLoading(false);
    }
  };

  return (
    <main className="app-shell">
      <header className="titlebar">
        <div>
          <h1>MEtR <span className="version-badge">v{appVersion}</span></h1>
          <p>Local LLM usage, subscriptions, and API-equivalent cost.</p>
        </div>
        <div className="actions">
          <button className="icon-button" onClick={() => void refresh(true)} disabled={loading} title="Refresh">
            <RefreshCw size={17} className={loading ? "spin" : undefined} />
          </button>
          <button className="icon-button" onClick={() => void checkForUpdates(true)} disabled={loading} title="Check for Updates">
            <Download size={17} />
          </button>
          <button className="primary-button" onClick={rescanAll} disabled={loading}>
            <Activity size={16} className={loading ? "spin" : undefined} />
            {loading ? "Working..." : "Scan New"}
          </button>
          <button className="secondary-button" onClick={fullRescanAll} disabled={loading}>
            <Database size={16} className={loading ? "spin" : undefined} />
            {loading ? "Updating..." : "Full Rescan"}
          </button>
        </div>
      </header>

      <nav className="tabs">
        <button className={activeTab === "all" ? "active" : ""} onClick={() => setActiveTab("all")}>
          All
        </button>
        {providerTabs.map((tab) => (
          <button key={tab.id} className={activeTab === tab.id ? "active" : ""} onClick={() => setActiveTab(tab.id)}>
            {tab.label}
          </button>
        ))}
        <button className={activeTab === "settings" ? "active" : ""} onClick={() => setActiveTab("settings")}>
          <Settings size={15} />
          Settings
        </button>
      </nav>

      <div className="status-line">
        <span className="status-message">{loading ? <span className="spinner" /> : null}{status}</span>
        <span className="privacy">
          {syncStatus?.logged_in ? (
            <><Cloud size={14} /> Connected to {syncStatus.server_url.replace(/^https:\/\//, "")}</>
          ) : (
            <><ShieldCheck size={14} /> Local database only</>
          )}
        </span>
      </div>

      {activeTab === "settings" ? (
        <ErrorBoundary fallback={<ErrorView />}>
          <SettingsView
            sources={sources}
            detected={detected}
            subscriptions={subscriptions}
            pricing={pricing}
            missingModels={missingModels}
            manualPath={manualPath}
            setManualPath={setManualPath}
            subForm={subForm}
            setSubForm={setSubForm}
            newPriceForm={newPriceForm}
            setNewPriceForm={setNewPriceForm}
            runDetection={runDetection}
            addDetected={addDetected}
            addManual={addManual}
            addSubscription={addSubscription}
            deleteSubscription={async (id) => {
              await api("delete_subscription", { id });
              await refresh(false);
            }}
            removeSource={async (source_id) => {
              await api("remove_source", { sourceId: source_id });
              await refresh(false);
            }}
            syncStatus={syncStatus}
            syncForm={syncForm}
            setSyncForm={setSyncForm}
            syncLoading={syncLoading}
            pricingLoading={pricingLoading}
            onLogin={doLogin}
            onLogout={doLogout}
            onSync={doSync}
            onFullResync={doFullResync}
            onPullPricing={doPullPricing}
            onPushPricing={doPushPricing}
            onAddLocalPricing={addLocalPricing}
            onClearAllData={clearAllData}
          />
        </ErrorBoundary>
      ) : (
        <ErrorBoundary fallback={<ErrorView />}>
          <DashboardView
            summary={summary}
            provider={activeProvider}
            projects={filteredProjects}
            sessions={paginatedSessions.sessions}
            totalSessionCount={paginatedSessions.total_count}
            sessionPage={sessionPage}
            sessionsPerPage={SESSIONS_PER_PAGE}
            onPageChange={setSessionPage}
            empty={(sources || []).length === 0 && (summary?.totals?.total_tokens || 0) === 0}
            goSettings={() => setActiveTab("settings")}
          />
        </ErrorBoundary>
      )}
    </main>
  );
}

function DashboardView({
  summary,
  provider,
  projects,
  sessions,
  totalSessionCount,
  sessionPage,
  sessionsPerPage,
  onPageChange,
  empty,
  goSettings
}: {
  summary: DashboardSummary;
  provider?: ProviderSummary;
  projects: ProjectSummary[];
  sessions: SessionSummary[];
  totalSessionCount: number;
  sessionPage: number;
  sessionsPerPage: number;
  onPageChange: (page: number) => void;
  empty: boolean;
  goSettings: () => void;
}) {
  const source = provider
    ? {
        api: provider.api_equivalent_cost,
        sub: provider.subscription_amount,
        savings: provider.net_savings_vs_api,
        totals: provider.totals,
        progress: provider.subscription_amount ? provider.api_equivalent_cost / provider.subscription_amount : null
      }
    : {
        api: summary.api_equivalent_total,
        sub: summary.subscriptions_total,
        savings: summary.net_savings_vs_api,
        totals: summary.totals,
        progress: summary.break_even_progress
      };

  if (empty) {
    return (
      <section className="empty-state">
        <Database size={42} />
        <h2>No usage indexed yet</h2>
        <p>Add or detect local LLM log folders, then run a scan. Your data stays on this machine.</p>
        <button className="primary-button" onClick={goSettings}>
          <FolderPlus size={16} />
          Configure sources
        </button>
      </section>
    );
  }

  return (
    <>
      <section className="metric-grid">
        <Metric label="Cached tokens" value={compact(cachedTotal(source.totals))} detail="Cache reads, writes, and cached input" />
        <Metric label="Input tokens" value={compact(effectiveInputTotal(source.totals))} detail="Non-cached input" />
        <Metric label="Output tokens" value={compact(source.totals.output_tokens)} detail="Generated tokens" />
        <Metric label="Total tokens" value={compact(source.totals.total_tokens)} detail="Cached, input, output, and other" />
        <Metric label="API-equivalent usage" value={money(source.api)} detail="Priced local token usage" />
        <Metric label="Subscription paid" value={money(source.sub)} detail="Configured monthly amount" />
      </section>

      <section className="split">
        <div className="panel">
          <h2>Token Breakdown</h2>
          <TokenBars totals={source.totals} />
        </div>
        <div className="panel">
          <h2>Break-Even</h2>
          <div className="progress-track">
            <div className="progress-fill" style={{ width: `${Math.min((source.progress ?? 0) * 100, 180)}%` }} />
          </div>
          <p className="big-number">{source.progress == null ? "No subscription" : percent(source.progress)}</p>
          <p className="muted">
            {source.progress == null
              ? "Add a subscription to compare fixed fee against API-equivalent usage."
              : source.progress >= 1
                ? `${money(source.savings)} ahead versus API pricing.`
                : `${money(source.sub - source.api)} until break-even.`}
          </p>
        </div>
      </section>

      <section className="panel">
        <h2>Projects</h2>
        <table>
          <thead>
            <tr>
              <th>Project</th>
              <th>Tokens</th>
              <th>Mix</th>
              <th>API Cost</th>
              <th>Indexed Span</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {projects.map((project) => (
              <tr key={project.id}>
                <td>
                  <strong>{project.display_name}</strong>
                  <span>{provider ? folderHint(project.path) : providerLabel(project.provider_id)}</span>
                </td>
                <td>{compact(project.totals.total_tokens)}</td>
                <td>{tokenMix(project.totals)}</td>
                <td>{money(project.api_equivalent_cost)}</td>
                <td>{durationLabel(project.first_seen, project.last_seen)}</td>
                <td>
                  {project.path ? (
                    <button
                      className="icon-button"
                      title="Open project folder"
                      onClick={() => void api("open_project_path", { path: project.path })}
                    >
                      <FolderOpen size={15} />
                    </button>
                  ) : null}
                </td>
              </tr>
            ))}
            {projects.length === 0 && <EmptyRow colSpan={6} text="No project-level usage found yet." />}
          </tbody>
        </table>
      </section>

      <section className="panel">
        <h2>Recent Sessions ({totalSessionCount} total)</h2>
        <table>
          <thead>
            <tr>
              <th>Time</th>
              <th>Project</th>
              <th>Type</th>
              <th>Model</th>
              <th>Cached</th>
              <th>Input</th>
              <th>Output</th>
              <th>Total</th>
              <th>API Cost</th>
              <th>Confidence</th>
            </tr>
          </thead>
          <tbody>
            {sessions.map((session) => (
              <tr key={session.id}>
                <td>{date(session.timestamp)}</td>
                <td>{session.project_name ?? "—"}</td>
                <td>{session.event_type ? <span className="pill info">{session.event_type}</span> : "—"}</td>
                <td>{session.model ?? "—"}</td>
                <td><TokenCell value={session.cached_tokens} cost={session.cached_cost} total={session.total_tokens} /></td>
                <td><TokenCell value={session.effective_input_tokens} cost={session.input_cost} total={session.total_tokens} /></td>
                <td><TokenCell value={session.output_tokens} cost={session.output_cost} total={session.total_tokens} /></td>
                <td>{compact(session.total_tokens)}</td>
                <td>{session.api_equivalent_cost == null ? "—" : money(session.api_equivalent_cost)}</td>
                <td><span className={`pill ${session.confidence}`}>{session.confidence}</span></td>
              </tr>
            ))}
            {sessions.length === 0 && <EmptyRow colSpan={10} text="No sessions indexed yet." />}
          </tbody>
          {sessions.length > 0 && (
            <tfoot>
              <tr>
                <td colSpan={4}>Visible page total</td>
                <td><TokenCell value={sumSessions(sessions, "cached_tokens")} cost={sumNullable(sessions, "cached_cost")} total={sumSessions(sessions, "total_tokens")} /></td>
                <td><TokenCell value={sumSessions(sessions, "effective_input_tokens")} cost={sumNullable(sessions, "input_cost")} total={sumSessions(sessions, "total_tokens")} /></td>
                <td><TokenCell value={sumSessions(sessions, "output_tokens")} cost={sumNullable(sessions, "output_cost")} total={sumSessions(sessions, "total_tokens")} /></td>
                <td>{compact(sumSessions(sessions, "total_tokens"))}</td>
                <td>{money(sessions.reduce((sum, s) => sum + (s.api_equivalent_cost ?? 0), 0))}</td>
                <td></td>
              </tr>
            </tfoot>
          )}
        </table>
        {totalSessionCount > sessionsPerPage && (
          <div style={{ marginTop: 12, display: "flex", gap: 6, justifyContent: "center", alignItems: "center" }}>
            <button className="secondary-button" disabled={sessionPage <= 1} onClick={() => onPageChange(sessionPage - 1)}>← Prev</button>
            <span className="muted">Page {sessionPage} of {Math.ceil(totalSessionCount / sessionsPerPage)}</span>
            <button className="secondary-button" disabled={sessionPage * sessionsPerPage >= totalSessionCount} onClick={() => onPageChange(sessionPage + 1)}>Next →</button>
          </div>
        )}
      </section>
    </>
  );
}

function SettingsView(props: {
  sources: Source[];
  detected: DetectedSource[];
  subscriptions: Subscription[];
  pricing: PricingEntry[];
  missingModels: MissingModel[];
  manualPath: string;
  setManualPath: (value: string) => void;
  subForm: SubscriptionForm;
  setSubForm: (value: SubscriptionForm) => void;
  newPriceForm: Record<string, { input: string; output: string }>;
  setNewPriceForm: (value: Record<string, { input: string; output: string }>) => void;
  runDetection: () => void;
  addDetected: (source: DetectedSource) => void;
  addManual: () => void;
  addSubscription: () => void;
  deleteSubscription: (id: string) => void;
  removeSource: (id: string) => void;
  syncStatus: SyncStatus | null;
  syncForm: { server_url: string; login: string; password: string };
  setSyncForm: (value: { server_url: string; login: string; password: string }) => void;
  syncLoading: boolean;
  pricingLoading: boolean;
  onLogin: () => void;
  onLogout: () => void;
  onSync: () => void;
  onFullResync: () => void;
  onPullPricing: () => void;
  onPushPricing: () => void;
  onAddLocalPricing: (providerId: string, model: string) => void;
  onClearAllData: () => void;
}) {
  return (
    <div className="settings-grid">
      <section className="panel">
        <h2><Cloud size={16} /> Sync Account</h2>
        {props.syncStatus?.logged_in ? (
          <div className="sync-logged-in">
            <p><strong>Logged in:</strong> {props.syncStatus.username}</p>
            <p><strong>Server:</strong> {props.syncStatus.server_url}</p>
            <p><strong>Device:</strong> {props.syncStatus.device_name}</p>
            <p><strong>Last sync:</strong> {date(props.syncStatus.last_sync_at)}</p>
            <p><strong>Pending events:</strong> {props.syncStatus.pending_events}</p>
            <div className="form-row">
              <button className="primary-button" onClick={props.onSync} disabled={props.syncLoading}>
                <RefreshCw size={14} />
                {props.syncLoading ? "Syncing..." : "Sync Now"}
              </button>
              <button className="secondary-button" onClick={props.onFullResync} disabled={props.syncLoading}>
                <RefreshCw size={14} />
                Full Resync
              </button>
              <button className="secondary-button" onClick={props.onLogout} disabled={props.syncLoading}>
                Logout
              </button>
            </div>
          </div>
        ) : (
          <div className="sync-login-form">
            <div className="form-row">
              <input
                type="url"
                value={props.syncForm.server_url}
                onChange={(e) => props.setSyncForm({ ...props.syncForm, server_url: e.target.value })}
                placeholder="https://metr.petarpetkov.com"
              />
            </div>
            <div className="form-row">
              <input
                type="email"
                value={props.syncForm.login}
                onChange={(e) => props.setSyncForm({ ...props.syncForm, login: e.target.value })}
                placeholder="Email or username"
              />
              <input
                type="password"
                value={props.syncForm.password}
                onChange={(e) => props.setSyncForm({ ...props.syncForm, password: e.target.value })}
                placeholder="Password"
              />
            </div>
            <button className="primary-button" onClick={props.onLogin} disabled={props.syncLoading}>
              {props.syncLoading ? "Logging in..." : "Log in"}
            </button>
          </div>
        )}
      </section>

      <section className="panel">
        <h2><Trash2 size={16} /> Danger Zone</h2>
        <p className="muted">Delete all parsed events, projects, and conversations. Your source folders and subscriptions stay intact.</p>
        <button className="secondary-button" onClick={props.onClearAllData} style={{ borderColor: "#ef4444", color: "#ef4444" }}>
          <Trash2 size={14} />
          Clear All Data
        </button>
      </section>

      <section className="panel">
        <h2>Sources</h2>
        <div className="form-row">
          <input
            value={props.manualPath}
            onChange={(event) => props.setManualPath(event.target.value)}
            placeholder="Paste a folder path, for example C:\\Users\\you\\.claude"
          />
          <button className="primary-button" onClick={props.addManual}>
            <FolderPlus size={16} />
            Add
          </button>
        </div>
        <button className="secondary-button" onClick={props.runDetection}>Detect standard folders</button>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Path</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {props.sources.map((source) => (
              <tr key={source.id}>
                <td>{source.display_name}</td>
                <td><span title={source.path}>{source.path}</span></td>
                <td>{source.last_scan_message ?? source.last_scan_status ?? "Not scanned"}</td>
                <td>
                  <button className="icon-button" onClick={() => props.removeSource(source.id)} title="Remove source">
                    <Trash2 size={15} />
                  </button>
                </td>
              </tr>
            ))}
            {props.sources.length === 0 && <EmptyRow colSpan={4} text="No sources configured." />}
          </tbody>
        </table>
      </section>

      <section className="panel">
        <h2>Detected Folders</h2>
        <table>
          <thead>
            <tr>
              <th>Provider</th>
              <th>Files</th>
              <th>Path</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {props.detected.map((source) => (
              <tr key={source.path}>
                <td>{source.display_name}</td>
                <td>{source.found_file_count}</td>
                <td><span title={source.path}>{source.path}</span></td>
                <td><button className="secondary-button" onClick={() => props.addDetected(source)}>Add</button></td>
              </tr>
            ))}
            {props.detected.length === 0 && <EmptyRow colSpan={4} text="Run detection to find standard provider folders." />}
          </tbody>
        </table>
      </section>

      <section className="panel">
        <h2>Subscriptions</h2>
        <div className="subscription-form">
          <input value={props.subForm.provider_id} onChange={(e) => props.setSubForm({ ...props.subForm, provider_id: e.target.value })} placeholder="provider id" />
          <input value={props.subForm.product_name} onChange={(e) => props.setSubForm({ ...props.subForm, product_name: e.target.value })} placeholder="product" />
          <input value={props.subForm.monthly_amount} onChange={(e) => props.setSubForm({ ...props.subForm, monthly_amount: e.target.value })} placeholder="amount" />
          <input value={props.subForm.billing_anchor_day} onChange={(e) => props.setSubForm({ ...props.subForm, billing_anchor_day: e.target.value })} placeholder="billing day" />
          <button className="primary-button" onClick={props.addSubscription}>
            <WalletCards size={16} />
            Add
          </button>
        </div>
        <table>
          <thead>
            <tr>
              <th>Provider</th>
              <th>Product</th>
              <th>Amount</th>
              <th>Anchor</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {props.subscriptions.map((sub) => (
              <tr key={sub.id}>
                <td>{sub.provider_id}</td>
                <td>{sub.product_name}</td>
                <td>{sub.currency} {sub.monthly_amount.toFixed(2)}</td>
                <td>{sub.billing_anchor_day}</td>
                <td>
                  <button className="icon-button" onClick={() => props.deleteSubscription(sub.id)} title="Delete subscription">
                    <Trash2 size={15} />
                  </button>
                </td>
              </tr>
            ))}
            {props.subscriptions.length === 0 && <EmptyRow colSpan={5} text="No subscriptions configured." />}
          </tbody>
        </table>
      </section>

      <section className="panel">
        <h2>Pricing Catalog</h2>
        <div className="form-row">
          <button className="primary-button" onClick={props.onPullPricing} disabled={props.pricingLoading || !props.syncStatus?.logged_in} title={props.syncStatus?.logged_in ? "Pull latest prices from central server" : "Log in to sync first"}>
            <RefreshCw size={14} />
            {props.pricingLoading ? "Pulling..." : "Pull from Server"}
          </button>
          <button className="secondary-button" onClick={props.onPushPricing} disabled={props.pricingLoading || !props.syncStatus?.logged_in} title={props.syncStatus?.logged_in ? "Push local prices to central server" : "Log in to sync first"}>
            <Cloud size={14} />
            {props.pricingLoading ? "Pushing..." : "Push to Server"}
          </button>
        </div>
        {!props.syncStatus?.logged_in && (
          <p className="muted">Log in to Sync Account above to fetch or contribute prices.</p>
        )}

        {props.missingModels.length > 0 && (
          <>
            <h3 style={{ marginTop: 16, marginBottom: 8 }}>Missing Prices ({props.missingModels.length} model{props.missingModels.length !== 1 ? "s" : ""})</h3>
            <table>
              <thead>
                <tr>
                  <th>Provider</th>
                  <th>Model</th>
                  <th>Events</th>
                  <th>Input / 1M</th>
                  <th>Output / 1M</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {props.missingModels.map((m) => {
                  const key = `${m.provider_id}::${m.model}`;
                  const form = props.newPriceForm[key] ?? { input: "", output: "" };
                  return (
                    <tr key={key}>
                      <td>{m.provider_id}</td>
                      <td>{m.model}</td>
                      <td>{m.event_count}</td>
                      <td>
                        <input
                          type="number"
                          step="0.01"
                          style={{ width: 80 }}
                          value={form.input}
                          placeholder="$"
                          onChange={(e) =>
                            props.setNewPriceForm({
                              ...props.newPriceForm,
                              [key]: { ...form, input: e.target.value },
                            })
                          }
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          step="0.01"
                          style={{ width: 80 }}
                          value={form.output}
                          placeholder="$"
                          onChange={(e) =>
                            props.setNewPriceForm({
                              ...props.newPriceForm,
                              [key]: { ...form, output: e.target.value },
                            })
                          }
                        />
                      </td>
                      <td>
                        <button
                          className="secondary-button"
                          disabled={props.pricingLoading || !form.input || !form.output}
                          onClick={() => props.onAddLocalPricing(m.provider_id, m.model)}
                        >
                          Add
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </>
        )}

        <h3 style={{ marginTop: 16, marginBottom: 8 }}>Known Prices ({props.pricing.length})</h3>
        <table>
          <thead>
            <tr>
              <th>Provider</th>
              <th>Model</th>
              <th>Input / 1M</th>
              <th>Output / 1M</th>
              <th>Cached / 1M</th>
            </tr>
          </thead>
          <tbody>
            {props.pricing.map((entry) => (
              <tr key={entry.id}>
                <td>{entry.provider_id}</td>
                <td>{entry.model}</td>
                <td>{price(entry.input_per_1m)}</td>
                <td>{price(entry.output_per_1m)}</td>
                <td>{price(entry.cached_input_per_1m)}</td>
              </tr>
            ))}
            {props.pricing.length === 0 && <EmptyRow colSpan={5} text="No prices loaded. Pull from server or add manually." />}
          </tbody>
        </table>
      </section>
    </div>
  );
}

class ErrorBoundary extends React.Component<{ children: React.ReactNode; fallback: React.ReactNode }, { hasError: boolean }> {
  constructor(props: { children: React.ReactNode; fallback: React.ReactNode }) {
    super(props);
    this.state = { hasError: false };
  }
  static getDerivedStateFromError() {
    return { hasError: true };
  }
  componentDidCatch(error: unknown, info: React.ErrorInfo) {
    console.error("[MEtR] Render error:", error, info);
  }
  render() {
    if (this.state.hasError) {
      return this.props.fallback;
    }
    return this.props.children;
  }
}

function ErrorView() {
  return (
    <section className="empty-state" style={{ minHeight: "auto", padding: 40 }}>
      <AlertCircle size={42} color="#ef4444" />
      <h2>Something went wrong</h2>
      <p>The app encountered an error while rendering. Try restarting MEtR.</p>
      <button className="primary-button" onClick={() => window.location.reload()}>
        Reload App
      </button>
    </section>
  );
}

function Metric({ label, value, detail }: { label: string; value: string; detail: string }) {
  return (
    <div className="metric">
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{detail}</small>
    </div>
  );
}

function TokenBars({ totals }: { totals: UsageTotals }) {
  const safe = totals || emptyTotals();
  const rows = [
    ["Cached", cachedTotal(safe)],
    ["Input", effectiveInputTotal(safe)],
    ["Output", safe.output_tokens],
    ["Reasoning/tool", safe.reasoning_tokens + safe.tool_tokens],
    ["Unknown", safe.unknown_tokens]
  ] as const;
  const max = Math.max(...rows.map(([, value]) => value), 1);
  return (
    <div className="token-bars">
      {rows.map(([label, value]) => (
        <div className="bar-row" key={label}>
          <span>{label}</span>
          <div><i style={{ width: `${(value / max) * 100}%` }} /></div>
          <strong>{compact(value)}</strong>
        </div>
      ))}
    </div>
  );
}

function TokenCell({ value, cost, total }: { value: number; cost: number | null; total: number }) {
  const pct = total > 0 ? value / total : 0;
  return (
    <span className="token-cell">
      <strong>{compact(value)}</strong>
      <small>{cost == null ? "—" : money(cost)} · {percentPrecise(pct)}</small>
    </span>
  );
}

function EmptyRow({ colSpan, text }: { colSpan: number; text: string }) {
  return (
    <tr>
      <td colSpan={colSpan} className="table-empty">{text}</td>
    </tr>
  );
}

async function api<T>(command: string, args?: Record<string, unknown>): Promise<T> {
  return invoke<T>(command, args);
}

function emptyTotals(): UsageTotals {
  return {
    input_tokens: 0,
    output_tokens: 0,
    cached_input_tokens: 0,
    cache_write_tokens: 0,
    cache_read_tokens: 0,
    reasoning_tokens: 0,
    tool_tokens: 0,
    unknown_tokens: 0,
    total_tokens: 0
  };
}

function money(value: number, currency = "USD") {
  if (Math.abs(value) > 0 && Math.abs(value) < 0.01) return `<${currency}0.01`;
  return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(value);
}

function price(value: number | null) {
  return value == null ? "Unknown" : money(value);
}

function compact(value: number) {
  return new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

function percent(value: number) {
  return new Intl.NumberFormat(undefined, { style: "percent", maximumFractionDigits: 0 }).format(value);
}

function percentPrecise(value: number) {
  return new Intl.NumberFormat(undefined, { style: "percent", maximumFractionDigits: 1 }).format(value);
}

function date(value: string | null) {
  if (!value) return "Never";
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function providerLabel(providerId: string) {
  switch (providerId) {
    case "openai":
      return "OpenAI / Codex";
    case "anthropic":
      return "Claude";
    case "google":
      return "Gemini";
    case "cursor":
      return "Cursor";
    case "cline":
      return "Cline / Roo Code";
    case "continue":
      return "Continue";
    case "aider":
      return "Aider";
    case "kimi":
      return "Kimi / Moonshot";
    case "ollama":
      return "Ollama";
    case "lmstudio":
      return "LM Studio";
    case "generic":
      return "Generic";
    default:
      return providerId;
  }
}

function folderHint(path: string | null) {
  if (!path) return "Folder unavailable";
  const parts = path.split(/[\\/]/).filter(Boolean);
  if (parts.length <= 1) return path;
  return parts.slice(-2).join(" / ");
}

function tokenMix(totals: UsageTotals) {
  const safe = totals || emptyTotals();
  return `Cached ${compact(cachedTotal(safe))}  In ${compact(effectiveInputTotal(safe))}  Out ${compact(safe.output_tokens)}`;
}

function effectiveInputTotal(totals: UsageTotals) {
  return Math.max(0, totals.input_tokens - totals.cached_input_tokens);
}

function cachedTotal(totals: UsageTotals) {
  return totals.cached_input_tokens + totals.cache_read_tokens + totals.cache_write_tokens;
}

function sumSessions(sessions: SessionSummary[], key: "cached_tokens" | "effective_input_tokens" | "output_tokens" | "total_tokens") {
  return sessions.reduce((sum, session) => sum + session[key], 0);
}

function sumNullable(sessions: SessionSummary[], key: "cached_cost" | "input_cost" | "output_cost" | "other_cost") {
  let hasAny = false;
  const total = sessions.reduce((sum, session) => {
    const value = session[key];
    if (value == null) return sum;
    hasAny = true;
    return sum + value;
  }, 0);
  return hasAny ? total : null;
}

function durationLabel(firstSeen: string | null, lastSeen: string | null) {
  if (!firstSeen || !lastSeen) return "Unknown";
  const start = new Date(firstSeen).getTime();
  const end = new Date(lastSeen).getTime();
  const diff = Math.max(0, end - start);
  const days = Math.floor(diff / 86_400_000);
  if (days >= 1) return `${days}d`;
  const hours = Math.floor(diff / 3_600_000);
  if (hours >= 1) return `${hours}h`;
  const mins = Math.floor(diff / 60_000);
  if (mins >= 1) return `${mins}m`;
  return "< 1m";
}

function message(error: unknown) {
  return error instanceof Error ? error.message : String(error);
}

createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
