# Product Specification: Local LLM Usage Tracker

## 1. Product Summary

Build a native-feeling desktop application for Windows and macOS that analyzes local LLM application log/history files, extracts token usage, stores everything locally, and helps the user understand whether their subscriptions are cheaper than equivalent API usage.

The product is local-first. It must not require users to install Node.js, Rust, Python, database servers, command-line tools, or developer dependencies. The distributed app must install like a normal desktop application.

Working product name: `MEtR`

Primary user problem:

> I use multiple LLM desktop apps and coding harnesses such as Codex, Claude Code, Cursor, Gemini CLI, Cline, Roo Code, Continue, Aider, and others. I pay fixed monthly subscriptions, but I cannot easily see how much token usage I am getting, what it would cost through official APIs, or whether my subscription is worth it.

The app should make that clear in a clean, easy UI.

## 2. Recommended Tech Stack

Use this stack unless there is a very strong reason not to.

- Desktop framework: Tauri 2
- Native backend: Rust
- Frontend: React + TypeScript
- Build tool: Vite
- UI styling: Tailwind CSS with a small local design system
- UI primitives: Radix UI or React Aria
- Charts: Apache ECharts or Recharts
- Local database: SQLite
- Rust database layer: sqlx with SQLite
- File watching: Rust `notify`
- Serialization: serde, serde_json
- Date/time: chrono or time
- Packaging: Tauri bundler

Expected user install outputs:

- Windows: `.msi` installer and optionally portable `.exe`
- macOS: signed `.app` and `.dmg`

End users must not need Node.js, Rust, npm, pnpm, Python, SQLite CLI, Docker, or any other developer tool.

## 3. Core Product Goals

1. Feel like a real native desktop app on Windows and macOS.
2. Automatically detect common local folders used by LLM apps and coding harnesses.
3. Let users manually add any folder where history/log files are stored.
4. Parse local logs/history files and extract token usage where available.
5. Normalize token usage across providers into a common schema.
6. Store all parsed usage in a local SQLite database.
7. Show token breakdowns by provider, model, project, chat/session, and billing cycle.
8. Show input tokens, output tokens, cached input/read tokens, cache write tokens, and unknown/unclassified tokens.
9. Estimate equivalent API cost using official provider API pricing.
10. Let users enter subscription cost and billing date per provider/product.
11. Calculate subscription-adjusted real-life cost over the current billing cycle.
12. Help the user answer: "Am I getting more value than I would by paying API prices?"

## 4. Non-Goals for Version 1

Do not build these in v1 unless everything else is done:

- Cloud sync
- User accounts
- Remote database
- Team sharing
- Browser extension
- Mobile app
- Provider login
- Reading billing dashboards through web scraping
- Sending log files to any server
- Automatic API key collection
- Predicting future quota limits from undocumented provider rules

The product should work completely offline after installation, except optional pricing update checks if the user explicitly triggers them.

## 5. Privacy and Local-First Requirements

This app reads potentially sensitive local LLM conversations. Treat privacy as a top-level requirement.

Requirements:

- All raw log/history files stay on the user's machine.
- Parsed data is stored only in a local SQLite database.
- No telemetry by default.
- No cloud sync in v1.
- No upload of prompts, completions, file paths, project names, or usage records.
- The app must never modify source log/history files.
- The app should avoid storing raw prompt/response text unless the user explicitly enables a developer/debug option.
- Store source file paths and stable hashes so records can be de-duplicated without storing full content.

Add an in-app privacy note in settings:

> MEtR reads local LLM log files and stores parsed usage locally. It does not upload your conversations or usage data.

## 6. Platform and Packaging Requirements

### 6.1 Windows

Target:

- Windows 10 and Windows 11
- x64 required
- arm64 optional later

Installer:

- Provide `.msi`
- Optionally provide a portable `.exe`
- Do not require Node.js, Rust, npm, Python, Docker, or manual SQLite installation.

WebView:

- Tauri uses Microsoft WebView2.
- Configure the Windows installer to bootstrap WebView2 if missing.
- The app should show a clear installer/runtime message if WebView2 cannot be installed.

Local app data paths:

- Database: `%APPDATA%\MEtR\metr.db`
- Config: `%APPDATA%\MEtR\config.json`
- App logs: `%LOCALAPPDATA%\MEtR\logs`

### 6.2 macOS

Target:

- macOS 13 or newer
- Apple Silicon required
- Intel optional if build pipeline supports it

Installer:

- Provide `.dmg`
- Include `.app`
- Sign and notarize for public distribution when ready.

WebView:

- Tauri uses the system WebKit WebView.
- No user-installed browser/runtime should be required.

Local app data paths:

- Database: `~/Library/Application Support/MEtR/metr.db`
- Config: `~/Library/Application Support/MEtR/config.json`
- App logs: `~/Library/Logs/MEtR`

## 7. Native Feel Requirements

The app should feel clean, quiet, and useful rather than like a marketing website.

### 7.1 General UI Style

- Use system fonts:
  - macOS: `-apple-system`
  - Windows: `Segoe UI`
- Support light and dark mode.
- Default to following the OS theme.
- Use native-feeling spacing, compact tables, standard controls, and predictable layouts.
- Avoid oversized landing-page hero sections.
- Avoid decorative gradients, bokeh, or purely aesthetic cards.
- Use cards only for repeated data summaries or focused panels.
- Keep border radius modest, ideally 6px to 8px.
- Make tables dense but readable.
- The first screen after setup should be the real dashboard, not a splash/marketing page.

### 7.2 Window Behavior

- Use the real OS window frame/titlebar unless a custom titlebar is necessary.
- Standard close/minimize/maximize behavior must work.
- Support keyboard navigation.
- Remember window size and position.
- Remember the last selected provider tab.

### 7.3 Navigation

Use a tabbed structure:

- `All`
- One tab for each configured provider/product
- `Settings`

Example:

```text
All | Codex | Claude | Cursor | Gemini | Settings
```

Only show provider tabs after a provider is detected or manually configured. The `All` tab always exists.

## 8. Main User Flows

### 8.1 First Launch Onboarding

The onboarding flow should be short and practical.

Step 1: Welcome

- Explain that the app tracks local LLM usage from local logs.
- Mention that data stays local.

Step 2: Auto-detect sources

- Scan standard folders.
- Show detected sources with provider name, folder path, and parser status.
- Let users enable/disable each detected source.

Step 3: Add custom folders

- Provide `Add folder` button.
- User can choose any local folder.
- User must choose or confirm a provider/parser type:
  - Auto-detect
  - Codex
  - Claude
  - Cursor
  - Gemini
  - Cline/Roo Code
  - Continue
  - Aider
  - Generic JSON/JSONL

Step 4: Subscription setup

- Optional.
- Let user enter subscription cost for each provider/product.
- Keep this simple:
  - Provider/product name
  - Monthly amount
  - Currency
  - Billing cycle anchor date

Step 5: Start scan

- Run initial indexing.
- Show progress.
- Allow user to use the app while indexing continues.

### 8.2 Daily Use

User opens the app and sees:

- Current billing cycle API-equivalent cost.
- Subscription amount paid for the cycle.
- Effective subscription cost based on usage so far.
- Whether subscription is currently cheaper or more expensive than API-equivalent pricing.
- Token breakdown by provider.
- Top projects by usage/cost.
- Recent chats/sessions.

### 8.3 Manual Folder Add

User can add a folder later:

1. Go to Settings.
2. Click `Add source`.
3. Pick folder.
4. Choose provider/parser or Auto-detect.
5. App indexes files and creates/updates provider tab.

### 8.4 Pricing Update

In Settings, user can:

- See the current local pricing catalog version.
- Manually update model prices.
- Import/export pricing catalog as JSON.
- Optionally click `Check official pricing pages`.

The app must not rely on scraping pages every time. Official prices change, so the local pricing catalog must be versioned and editable.

## 9. Provider and Folder Detection

The scanner must support automatic detection and manual override.

Detection should be conservative. A folder being present does not mean it contains usable usage data. The app must separate:

- Folder detected
- Files found
- Parser available
- Token usage found
- Token usage not available

### 9.1 Initial Provider Candidates

Implement these provider candidates for v1.

| Provider/Product | Candidate paths | v1 parser expectation |
| --- | --- | --- |
| OpenAI Codex / Codex CLI | `%USERPROFILE%\.codex`, `$HOME/.codex` | Parse JSON/JSONL/session/history files where usage fields exist. Mark unknown when token fields are not present. |
| Claude Code | `%USERPROFILE%\.claude`, `$HOME/.claude` | Parse project transcript JSONL files. Extract model and `usage` fields such as input, output, cache creation/write, and cache read tokens when present. |
| Cursor | Windows app data and macOS app support Cursor folders | Detect folder. Parse any available usage logs if token fields exist. Mark unknown otherwise. |
| Gemini CLI | `%USERPROFILE%\.gemini`, `$HOME/.gemini` | Detect folder. Parse JSON/JSONL logs if usage metadata exists. |
| Cline / Roo Code | VS Code global storage extension folders | Parse task/session JSON files when token usage exists. |
| Continue | `%USERPROFILE%\.continue`, `$HOME/.continue` | Detect folder. Parse history/log files if usage metadata exists. |
| Aider | Project-level `.aider*` files and user config/history | Detect project usage. Extract cost/token lines if present. Mark unknown otherwise. |
| Generic JSON/JSONL | User-selected folder | Search for known usage field names and allow generic extraction with low confidence. |

Important:

- Do not fake token usage when logs do not include it.
- If a provider does not expose enough local data, show `Usage unavailable from local logs` instead of guessing.
- The app should still show detected projects and sessions if possible, but cost calculations require token data.

### 9.2 Detection Algorithm

On app startup and when the user runs `Rescan`:

1. Build candidate path list for the current OS.
2. Expand environment variables and home directory.
3. Check path existence.
4. For each existing path, run provider-specific `detect()`.
5. `detect()` returns:
   - provider id
   - display name
   - source path
   - parser id
   - confidence
   - found file count
   - notes
6. Save detected sources to `log_sources`.
7. Do not enable a source automatically if confidence is low.
8. Ask user confirmation during onboarding.

### 9.3 Manual Source Configuration

Each source must support:

- Enabled/disabled
- Provider/parser selection
- Folder path
- Recursive scan on/off
- Include file patterns
- Exclude file patterns
- Last scan time
- Parser version

Default include patterns:

```text
*.json
*.jsonl
*.log
*.txt
*.md
*.sqlite
*.db
```

Default exclude patterns:

```text
node_modules
.git
target
dist
build
.next
vendor
```

Provider parsers should override include/exclude rules when they know exact file names.

## 10. Parsing and Normalization

### 10.1 Parser Contract

Each provider parser must implement this interface conceptually:

```rust
trait UsageParser {
    fn id(&self) -> &'static str;
    fn display_name(&self) -> &'static str;
    fn detect(&self, source_path: &Path) -> DetectionResult;
    fn list_candidate_files(&self, source_path: &Path) -> Vec<PathBuf>;
    fn parse_file(&self, file: &Path, cursor: Option<FileCursor>) -> ParseResult;
}
```

`ParseResult` includes:

- parsed usage events
- warnings
- new cursor
- parser version

### 10.2 Normalized Usage Event

Every parsed usage record must be normalized into this shape.

```typescript
type NormalizedUsageEvent = {
  id: string;
  providerId: string;
  providerDisplayName: string;
  productId: string | null;
  sourceId: string;
  parserId: string;
  parserVersion: string;

  timestamp: string;
  projectId: string | null;
  projectPath: string | null;
  projectDisplayName: string | null;
  conversationId: string | null;
  messageId: string | null;
  requestId: string | null;

  model: string | null;
  inputTokens: number;
  outputTokens: number;
  cachedInputTokens: number;
  cacheWriteTokens: number;
  cacheReadTokens: number;
  reasoningTokens: number;
  toolTokens: number;
  unknownTokens: number;

  officialApiCostUsd: number | null;
  pricingCatalogId: string | null;
  pricingMatchConfidence: "exact" | "alias" | "fallback" | "missing";

  sourceFilePath: string;
  sourceFileModifiedAt: string;
  sourceOffset: number | null;
  sourceHash: string;
  rawRecordHash: string;

  confidence: "high" | "medium" | "low";
  warnings: string[];
};
```

### 10.3 Token Categories

Support these token categories:

- Input tokens
- Output tokens
- Cached input tokens
- Cache read tokens
- Cache write/cache creation tokens
- Reasoning tokens
- Tool tokens
- Unknown tokens

Provider mappings:

- OpenAI cached input tokens should map to `cachedInputTokens` when provided.
- Anthropic `cache_creation_input_tokens` should map to `cacheWriteTokens`.
- Anthropic `cache_read_input_tokens` should map to `cacheReadTokens`.
- Reasoning tokens should be tracked separately if exposed, but included in cost according to provider pricing rules.
- If logs include total tokens only, store known total in `unknownTokens` and set confidence to `low`.

### 10.4 De-duplication

The app must not double-count usage when:

- The same file is rescanned.
- The app restarts.
- File watcher emits multiple events.
- A parser sees the same JSON object in two passes.

Create a stable dedupe key:

```text
sha256(provider_id + source_file_path + source_offset + request_id + message_id + raw_record_hash)
```

If request/message IDs are missing, use source file path, source offset, timestamp, model, token counts, and raw record hash.

### 10.5 Incremental Scanning

For each indexed file, store:

- file path
- size
- modified time
- hash if needed
- last processed byte offset for append-only files
- parser id
- parser version
- last scan status

Rules:

- If file size increased and parser supports append-only mode, scan from cursor.
- If file size decreased, treat as rotated/rewritten and rescan.
- If parser version changed, rescan affected files.
- If pricing catalog changed, recalculate costs without reparsing logs.

## 11. Project Detection

Most coding harnesses tie conversations to a working directory or project folder. The app must preserve that relationship.

Project mapping priority:

1. Explicit working directory field in the log record.
2. Provider-specific project folder naming convention.
3. Source path ancestry.
4. User-defined project mapping.
5. Unknown project.

Project display name:

- Prefer final folder name from project path.
- If duplicate names exist, show parent context, for example `api / backend`.
- Keep full path available in tooltip or detail view.

Project fields:

- project id
- display name
- full path
- provider id
- first seen
- last seen
- total events
- total tokens
- official API-equivalent cost
- subscription-allocated actual cost

## 12. Pricing Model

The app compares local subscription usage against official API-equivalent prices.

### 12.1 Pricing Sources

Use official pricing pages as source references:

- OpenAI API pricing: https://platform.openai.com/docs/pricing/
- OpenAI public API pricing: https://openai.com/api/pricing/
- Anthropic API pricing: https://docs.anthropic.com/en/docs/about-claude/pricing
- Google Gemini API pricing: https://ai.google.dev/gemini-api/docs/pricing
- xAI model pricing: https://docs.x.ai/docs/models/

Do not hard-code pricing logic directly into UI components. Use a versioned pricing catalog.

### 12.2 Pricing Catalog

Store provider/model pricing in a local JSON seed file and import it into SQLite on first run.

Example:

```json
{
  "catalogVersion": "2026-04-29",
  "currency": "USD",
  "providers": [
    {
      "providerId": "openai",
      "sourceUrl": "https://platform.openai.com/docs/pricing/",
      "models": [
        {
          "model": "example-model-name",
          "aliases": ["example-alias"],
          "effectiveFrom": "2026-04-29",
          "unit": "per_1m_tokens",
          "inputPerUnit": 0,
          "outputPerUnit": 0,
          "cachedInputPerUnit": 0,
          "cacheWritePerUnit": null,
          "cacheReadPerUnit": null,
          "reasoningBilling": "output",
          "notes": "Replace seed values with official values before release."
        }
      ]
    }
  ]
}
```

Implementation rule:

- The app must support importing updated pricing without code changes.
- The app must allow manual model price overrides in Settings.
- Every usage event cost must reference the pricing catalog row used.
- If no price is found for a model, show cost as `Unknown` and exclude that event from API-cost totals unless the user maps it manually.

### 12.3 API-Equivalent Cost Calculation

Normalize all token prices to USD per 1 million tokens.

For each usage event:

```text
api_cost =
  (input_tokens / 1_000_000 * input_price_per_1m)
+ (output_tokens / 1_000_000 * output_price_per_1m)
+ (cached_input_tokens / 1_000_000 * cached_input_price_per_1m)
+ (cache_write_tokens / 1_000_000 * cache_write_price_per_1m)
+ (cache_read_tokens / 1_000_000 * cache_read_price_per_1m)
+ (reasoning_tokens / 1_000_000 * reasoning_price_per_1m)
+ (tool_tokens / 1_000_000 * tool_price_per_1m)
```

Provider-specific rules:

- If reasoning tokens are billed as output tokens, use output price.
- If cache write price is expressed as multiplier of input price, convert to explicit price before storing.
- If cache read price is expressed as multiplier of input price, convert to explicit price before storing.
- If a provider has tiered pricing based on context size, store enough pricing metadata to support it later. v1 can use the standard listed model price unless logs expose the tier trigger.

### 12.4 Missing Prices

When a price is missing:

- Show `Unknown API cost`.
- Do not include unknown-cost events in API-equivalent dollar totals.
- Still include token totals.
- Show a small warning in the relevant model/project row.
- Let the user map the model to a known model or add a custom price.

## 13. Subscription Model

The subscription model should be simple and understandable.

The user enters one total subscription amount per provider/product. If they have two accounts, they should enter the combined amount manually.

Example:

- ChatGPT: `$40` per month
- Billing anchor date: 13th of each month

This means the app treats ChatGPT as one aggregate subscription with a billing cycle from the 13th of the current/previous month to the 13th of the next month.

### 13.1 Subscription Fields

Each subscription:

- id
- provider id
- product name
- monthly amount
- currency
- billing anchor date
- billing anchor time
- enabled/disabled
- notes
- created at
- updated at

### 13.2 Billing Cycle Calculation

Given:

- monthly amount
- anchor date
- current date/time

Compute:

- current cycle start
- current cycle end
- days elapsed
- days remaining

Rules:

- If anchor day is 13, cycle runs from the 13th to the next 13th.
- If the month does not have the anchor day, use the last day of the month.
- Treat cycle end as exclusive.
- Store dates timezone-aware using the user's local timezone.

### 13.3 Subscription Actual Cost Allocation

The app should answer:

> How much of my fixed subscription fee should be allocated to this provider, project, model, or chat based on usage so far?

Use API-equivalent cost as the allocation weight when available. This is better than raw tokens because input, output, and cache tokens have different real API values.

For a given subscription cycle:

```text
cycle_api_equivalent_cost = sum(api_equivalent_cost for all priced events in cycle)
cycle_subscription_fee = user_entered_monthly_amount
```

For each item:

```text
item_subscription_allocated_cost =
  cycle_subscription_fee * (item_api_equivalent_cost / cycle_api_equivalent_cost)
```

If API-equivalent prices are missing:

- Fall back to total tokens as allocation weight.
- Mark allocated cost confidence as `low`.

### 13.4 Effective Cost and Break-Even

For each subscription cycle:

```text
effective_discount_ratio =
  cycle_subscription_fee / cycle_api_equivalent_cost
```

Interpretation:

- If `cycle_api_equivalent_cost > cycle_subscription_fee`, subscription is cheaper than API.
- If `cycle_api_equivalent_cost < cycle_subscription_fee`, API would currently be cheaper.

Dashboard labels:

- `API-equivalent usage`
- `Subscription paid`
- `Net savings vs API`
- `Break-even progress`

Formulas:

```text
net_savings_vs_api = cycle_api_equivalent_cost - cycle_subscription_fee
break_even_progress = cycle_api_equivalent_cost / cycle_subscription_fee
```

Examples:

- Subscription is `$20`, API-equivalent usage is `$10`: progress is 50 percent, user has not broken even.
- Subscription is `$20`, API-equivalent usage is `$30`: progress is 150 percent, user is `$10` ahead vs API.

### 13.5 Effective Price Over Time

Because the fee is fixed and usage increases during the month, the effective cost per token should decrease as usage increases.

Show:

```text
effective_cost_per_1m_tokens =
  cycle_subscription_fee / (cycle_total_tokens / 1_000_000)
```

Also show category-specific effective rates if enough data exists:

- effective input cost per 1M
- effective output cost per 1M
- effective cached cost per 1M

Keep the primary UI simple. Put category-specific rates in detail views.

## 14. Main Screens

### 14.1 Dashboard: All Tab

Purpose:

Give the user a cross-provider view of current usage.

Content:

- Billing cycle selector:
  - Current cycle
  - Previous cycle
  - Last 7 days
  - Last 30 days
  - Custom range
- Summary metrics:
  - API-equivalent usage
  - Subscription paid
  - Net savings/loss vs API
  - Total tokens
  - Input tokens
  - Output tokens
  - Cache read/write tokens
- Provider comparison table:
  - Provider
  - Subscription
  - API-equivalent cost
  - Savings/loss
  - Total tokens
  - Top model
  - Last seen
- Top projects table:
  - Project
  - Provider
  - API-equivalent cost
  - Subscription allocated cost
  - Tokens
  - Last active
- Usage over time chart:
  - Daily API-equivalent cost
  - Daily token volume

### 14.2 Provider Tab

Each configured provider gets a tab.

Header:

- Provider name
- Current cycle
- Source count
- Last scan status
- Rescan button

Summary metrics:

- API-equivalent usage for provider
- Subscription paid for provider
- Net savings/loss
- Input tokens
- Output tokens
- Cached/cache read/cache write tokens
- Unknown tokens

Tables:

1. Projects
   - Project name
   - Path
   - API-equivalent cost
   - Subscription allocated cost
   - Input tokens
   - Output tokens
   - Cached/cache tokens
   - Sessions
   - Last active

2. Models
   - Model
   - API price status
   - Input tokens
   - Output tokens
   - Cache tokens
   - API-equivalent cost

3. Recent sessions/chats
   - Time
   - Project
   - Model
   - Tokens
   - API-equivalent cost
   - Parser confidence

### 14.3 Project Detail View

Open when the user clicks a project.

Content:

- Project name and full path
- Provider tabs inside project if used by multiple providers
- Current cycle summary
- Session list
- Model breakdown
- Token category chart
- API-equivalent cost vs subscription allocated cost
- Warnings:
  - Missing prices
  - Low-confidence parse
  - Unknown tokens

### 14.4 Session Detail View

Open when user clicks a session/chat.

Content:

- Timestamp
- Provider
- Model
- Project
- Token breakdown
- API-equivalent cost
- Subscription allocated cost
- Source file path
- Parser confidence
- Warnings

Do not show prompt/response text by default.

### 14.5 Settings

Sections:

1. Sources
   - List configured folders
   - Add source
   - Remove source
   - Enable/disable source
   - Rescan source
   - Parser selection

2. Subscriptions
   - Provider/product
   - Monthly amount
   - Currency
   - Billing anchor date
   - Enabled

3. Pricing Catalog
   - Catalog version
   - Provider/model prices
   - Missing model mappings
   - Import/export JSON
   - Manual overrides

4. Privacy
   - Local database path
   - Open database folder
   - Clear parsed data
   - Rebuild database from sources
   - Raw text storage toggle, default off

5. Appearance
   - System/light/dark theme
   - Compact table mode

6. About
   - App version
   - Parser versions
   - License info

## 15. Database Schema

Use SQLite with migrations.

### 15.1 Tables

```sql
CREATE TABLE providers (
  id TEXT PRIMARY KEY,
  display_name TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE log_sources (
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
  updated_at TEXT NOT NULL,
  FOREIGN KEY (provider_id) REFERENCES providers(id)
);

CREATE TABLE indexed_files (
  id TEXT PRIMARY KEY,
  source_id TEXT NOT NULL,
  path TEXT NOT NULL,
  size_bytes INTEGER NOT NULL,
  modified_at TEXT NOT NULL,
  content_hash TEXT,
  cursor_offset INTEGER,
  parser_id TEXT NOT NULL,
  parser_version TEXT NOT NULL,
  last_scan_status TEXT NOT NULL,
  last_scan_message TEXT,
  last_scanned_at TEXT NOT NULL,
  UNIQUE(source_id, path),
  FOREIGN KEY (source_id) REFERENCES log_sources(id)
);

CREATE TABLE projects (
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

CREATE TABLE conversations (
  id TEXT PRIMARY KEY,
  provider_id TEXT NOT NULL,
  project_id TEXT,
  external_conversation_id TEXT,
  display_name TEXT,
  first_seen_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE usage_events (
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
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE(id),
  FOREIGN KEY (source_id) REFERENCES log_sources(id),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id)
);

CREATE TABLE pricing_catalogs (
  id TEXT PRIMARY KEY,
  provider_id TEXT NOT NULL,
  model TEXT NOT NULL,
  aliases_json TEXT NOT NULL DEFAULT '[]',
  source_url TEXT,
  catalog_version TEXT NOT NULL,
  effective_from TEXT NOT NULL,
  effective_to TEXT,
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

CREATE TABLE subscriptions (
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

CREATE TABLE app_settings (
  key TEXT PRIMARY KEY,
  value_json TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
```

### 15.2 Indexes

```sql
CREATE INDEX idx_usage_events_timestamp ON usage_events(timestamp);
CREATE INDEX idx_usage_events_provider ON usage_events(provider_id);
CREATE INDEX idx_usage_events_project ON usage_events(project_id);
CREATE INDEX idx_usage_events_model ON usage_events(model);
CREATE INDEX idx_usage_events_conversation ON usage_events(conversation_id);
CREATE INDEX idx_usage_events_source ON usage_events(source_id);
CREATE INDEX idx_pricing_provider_model ON pricing_catalogs(provider_id, model);
CREATE INDEX idx_sources_provider ON log_sources(provider_id);
```

## 16. Backend Modules

Implement Rust modules with clear boundaries.

```text
src-tauri/src/
  main.rs
  commands/
    dashboard.rs
    sources.rs
    subscriptions.rs
    pricing.rs
    scans.rs
  db/
    mod.rs
    migrations.rs
    repositories.rs
  detection/
    mod.rs
    os_paths.rs
    detectors.rs
  parsers/
    mod.rs
    codex.rs
    claude.rs
    cursor.rs
    gemini.rs
    cline.rs
    continue_dev.rs
    aider.rs
    generic_jsonl.rs
  pricing/
    mod.rs
    catalog.rs
    calculator.rs
  subscriptions/
    mod.rs
    billing_cycle.rs
    allocation.rs
  scanning/
    mod.rs
    scanner.rs
    watcher.rs
    incremental.rs
  models/
    mod.rs
```

Frontend structure:

```text
src/
  app/
    App.tsx
    routes.tsx
  components/
    layout/
    charts/
    tables/
    forms/
    ui/
  features/
    dashboard/
    providers/
    projects/
    sessions/
    sources/
    subscriptions/
    pricing/
    settings/
  lib/
    api.ts
    formatting.ts
    dates.ts
    money.ts
    tokens.ts
```

## 17. Tauri Commands

Expose backend functionality through Tauri commands.

Required commands:

```text
get_app_status()
get_dashboard_summary(range)
get_provider_summary(provider_id, range)
get_project_detail(project_id, range)
get_session_detail(conversation_id)

detect_sources()
list_sources()
add_source(path, parser_id, provider_id)
update_source(source_id, patch)
remove_source(source_id)
rescan_source(source_id)
rescan_all()

list_subscriptions()
create_subscription(input)
update_subscription(id, patch)
delete_subscription(id)

list_pricing_catalog()
update_pricing_entry(id, patch)
create_pricing_override(input)
import_pricing_catalog(json)
export_pricing_catalog()

get_settings()
update_setting(key, value)
open_database_folder()
clear_parsed_data()
rebuild_database()
```

Long-running scans should stream progress to the frontend using Tauri events.

Events:

```text
scan_started
scan_progress
scan_warning
scan_finished
source_detected
usage_event_imported
pricing_updated
```

## 18. Error Handling

The app must be calm and clear when something cannot be parsed.

Examples:

- Folder does not exist: show `Folder not found`.
- Permission denied: show `MEtR cannot read this folder. Check file permissions.`
- Parser found no token usage: show `No token usage found in this source yet.`
- Unknown model price: show `No API price configured for this model.`
- Corrupt JSON line: log warning, skip line, continue scan.
- Database locked: retry briefly, then show non-destructive error.

Do not crash the app because of one bad file.

## 19. Performance Requirements

Target:

- Initial app startup: under 2 seconds after installation on a normal machine.
- UI remains responsive during indexing.
- Initial scan of 100 MB logs: under 60 seconds on a normal laptop.
- Incremental scan after app startup: under 5 seconds for unchanged sources.
- SQLite queries for dashboard: under 300 ms for 100,000 usage events.

Implementation requirements:

- Scanning runs in Rust background tasks.
- UI receives progress events.
- Use batch inserts inside transactions.
- Use indexes listed above.
- Avoid reading huge files fully into memory unless necessary.
- For JSONL, stream line by line.

## 20. Security Requirements

- Do not execute files from scanned folders.
- Treat all log content as untrusted input.
- Do not follow symlinks outside user-selected folders unless the user enables it.
- Avoid storing raw prompt/response content by default.
- File paths can be sensitive. Do not expose them outside the app.
- Redact paths in debug logs unless debug mode is enabled.

## 21. Accessibility Requirements

- All controls keyboard accessible.
- Minimum contrast meets WCAG AA.
- Tables support screen reader labels.
- Charts must have textual summaries.
- Buttons must have accessible names.
- Do not rely on color alone to show profit/loss or warnings.

## 22. Testing Requirements

### 22.1 Rust Unit Tests

Required:

- Billing cycle calculation
- Subscription allocation
- API cost calculation
- Pricing lookup and alias matching
- Deduplication key generation
- Parser tests using fixture logs
- Incremental scan cursor behavior

### 22.2 Frontend Tests

Required:

- Dashboard renders empty state
- Dashboard renders sample data
- Provider tabs render from configured providers
- Subscription form validates amount/date
- Missing pricing warning appears
- Source settings add/edit/remove flow

### 22.3 End-to-End Tests

Use Playwright where practical.

Required flows:

1. First launch onboarding with sample fixture folder.
2. Auto-detected source appears.
3. Initial scan imports sample usage.
4. Dashboard shows API-equivalent cost.
5. User adds subscription.
6. Dashboard shows break-even progress and savings/loss.
7. User adds custom pricing override for unknown model.

### 22.4 Fixture Data

Create test fixture folders:

```text
fixtures/
  codex/
  claude/
  generic-jsonl/
  missing-prices/
  malformed/
```

Fixtures must not contain real user conversations. Use synthetic logs only.

## 23. Empty and Partial States

The product must be useful even before perfect parsing support exists.

States:

- No sources configured
- Sources detected but disabled
- Source enabled but no files found
- Files found but no token usage found
- Usage found but prices missing
- Usage found but no subscription entered
- Subscription entered but no usage this cycle

Each state should tell the user what happened and what they can do next.

Example copy:

```text
No token usage found yet.
This source was detected, but MEtR could not find token counters in the local logs.
You can keep it enabled and MEtR will continue watching for new usage.
```

## 24. Visual Design Details

Use a restrained app-like layout.

### 24.1 Layout

```text
+-----------------------------------------------------------+
| App title / current range / global actions                |
+-----------------------------------------------------------+
| All | Codex | Claude | Cursor | Gemini | Settings         |
+-----------------------------------------------------------+
| Summary metric row                                        |
+-----------------------------------------------------------+
| Left: usage chart              | Right: break-even panel   |
+-----------------------------------------------------------+
| Projects table                                            |
+-----------------------------------------------------------+
| Models / recent sessions tables                           |
+-----------------------------------------------------------+
```

### 24.2 Summary Tiles

Summary tiles should be compact:

- Label
- Value
- Small secondary context
- Optional trend indicator

Example:

```text
API-equivalent usage
$31.42
Current cycle
```

### 24.3 Break-Even Panel

Show:

- Subscription paid
- API-equivalent usage
- Progress bar toward break-even
- Savings/loss label

Copy examples:

- `$8.40 until break-even`
- `$12.15 ahead vs API`

Use clear color but also text.

### 24.4 Tables

Tables should support:

- Sorting
- Filtering by model/project
- Column visibility if easy
- Right-aligned numbers
- Monospace for token counts optional
- Tooltips for full paths

## 25. Data Formatting

Money:

- Default currency: USD
- Show two decimals for normal values.
- Show `<$0.01` for tiny costs.
- Store money as decimal-compatible values. SQLite REAL is acceptable for v1 display estimates, but keep calculation centralized.

Tokens:

- Show exact value in detail views.
- Compact in dashboard:
  - `1,234`
  - `12.4K`
  - `3.2M`

Dates:

- Use user's local timezone.
- Show absolute timestamp in detail views.
- Use relative labels only as secondary text.

## 26. Build and Distribution

Use package scripts similar to:

```json
{
  "scripts": {
    "dev": "tauri dev",
    "build": "tauri build",
    "test": "vitest run",
    "test:e2e": "playwright test",
    "lint": "eslint .",
    "format": "prettier --write ."
  }
}
```

Release artifacts:

- Windows `.msi`
- macOS `.dmg`

Release checklist:

- App installs without developer dependencies.
- App launches offline.
- First-launch onboarding works.
- Sample fixtures parse correctly.
- App survives malformed logs.
- Database migrations run on clean install.
- App can be uninstalled normally.

## 27. Implementation Milestones

### Milestone 1: Skeleton App

- Tauri + React + TypeScript app boots.
- SQLite database created in app data folder.
- Basic layout with tabs.
- Settings page exists.
- Installer build succeeds for current development OS.

Acceptance:

- User can install and open app.
- No Node/Rust required after install.

### Milestone 2: Sources and Scanning

- Auto-detect candidate provider folders.
- Add manual folder.
- Store sources in SQLite.
- Run background scan.
- Show scan status in UI.

Acceptance:

- App detects `.codex` and `.claude` folders when present.
- User can add any custom folder.
- App does not crash on unreadable/malformed files.

### Milestone 3: First Parsers

- Implement Claude Code parser.
- Implement Codex parser.
- Implement Generic JSONL parser.
- Normalize usage events.
- De-duplicate events.

Acceptance:

- Synthetic fixtures import usage events.
- Dashboard token totals match fixture expectations.

### Milestone 4: Pricing

- Add local pricing catalog.
- Implement model alias matching.
- Calculate API-equivalent cost.
- Show missing price warnings.
- Allow manual pricing overrides.

Acceptance:

- Usage events with known model prices show API-equivalent cost.
- Unknown models do not break dashboard.

### Milestone 5: Subscriptions

- Add subscription settings.
- Implement billing cycles.
- Implement break-even calculation.
- Implement subscription allocated cost.

Acceptance:

- User can enter `$20` with billing date 13.
- Current cycle is calculated correctly.
- Dashboard shows API-equivalent cost, subscription paid, and savings/loss.

### Milestone 6: Provider Tabs and Project Details

- Dynamic provider tabs.
- Provider summary views.
- Project breakdown.
- Session details.

Acceptance:

- Each configured provider gets a tab.
- Projects show token and cost totals.

### Milestone 7: Polish and Packaging

- Native-feeling UI pass.
- Light/dark mode.
- Empty states.
- Error states.
- Windows and macOS packaging.

Acceptance:

- App feels clean and understandable.
- Installer artifacts exist.
- No developer dependency required for end user.

## 28. Acceptance Criteria for Version 1

The v1 app is complete when all of these are true:

1. Runs as an installed desktop app on Windows and macOS.
2. Does not require end users to install Node.js, Rust, Python, or SQLite.
3. Stores all data locally in SQLite.
4. Detects common provider folders.
5. Allows user-selected custom folders.
6. Parses at least Claude Code, Codex, and generic JSONL fixture usage.
7. Shows tabs for configured providers.
8. Shows usage by project.
9. Shows input, output, cached/cache read/cache write, and unknown token categories.
10. Calculates API-equivalent cost from a versioned pricing catalog.
11. Allows manual pricing overrides.
12. Lets user enter total monthly subscription amount and billing anchor date.
13. Calculates current cycle subscription cost, API-equivalent usage, break-even progress, and savings/loss.
14. Handles missing token data without guessing.
15. Handles malformed files without crashing.
16. Preserves privacy and does not upload user logs.

## 29. Important Implementation Notes

1. Do not guess usage if token counters are unavailable.
2. Do not store raw prompts/responses by default.
3. Do not hard-code pricing in UI components.
4. Treat pricing as versioned data.
5. Treat parsers as adapters with separate versions.
6. Make parser confidence visible.
7. Keep the subscription model simple: one total monthly amount per provider/product.
8. If users have multiple accounts, they manually enter the combined monthly amount.
9. Billing date is the anchor for the aggregate subscription.
10. The app's value is clarity, not perfect reverse engineering of every vendor's private quota system.

## 30. Suggested First Build Order for an Implementing Model

Build in this order:

1. Scaffold Tauri app.
2. Add SQLite migrations.
3. Add app shell and tabs.
4. Add source detection/settings.
5. Add scanner and synthetic fixtures.
6. Add normalized usage event schema.
7. Add Claude parser.
8. Add Codex parser.
9. Add dashboard totals.
10. Add pricing catalog and cost calculator.
11. Add subscription settings and break-even math.
12. Add provider/project/session views.
13. Add packaging.
14. Add tests.
15. Polish UI.

Do not start with complex charts. Start with correct data and calculations, then make the UI nicer.
