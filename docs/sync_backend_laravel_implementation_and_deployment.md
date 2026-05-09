# MEtR Sync Backend Implementation And Deployment Runbook

This document is a handoff for building a Laravel sync backend for MEtR and deploying it on the same server and with the same deployment style as `p2pbg`.

The intended reader may be a smaller/cheaper coding model. Be explicit, avoid clever shortcuts, and follow the order below.

## 0. Decision Summary

Build a separate Laravel backend that receives normalized MEtR usage data from each desktop installation.

MEtR remains local-first:

- Each Mac/Windows machine keeps its own local SQLite database.
- Each desktop app scans local Codex, Claude, Kimi, LM Studio, Ollama, etc. logs.
- The desktop app uploads normalized usage events to Laravel.
- Laravel stores one unified cross-device view.
- Laravel also stores subscriptions, provider account attribution rules, project grouping, and time-versioned model prices.
- Laravel sends shared settings/prices/rules back down to each desktop client.

Do not sync raw client log files. Do not sync the live SQLite database.

## 1. Non-Negotiable Requirements

### Functional

- Username/password login only for now.
- Multiple desktop devices per user.
- Aggregated reporting across devices.
- Per-device reporting must remain available.
- Projects must be grouped by developer root folder across operating systems.
- Provider accounts must be represented separately from providers.
- Subscriptions attach to provider accounts, not only providers.
- Usage events may be attributed to provider accounts exactly, by rule, manually, or as unknown.
- Model pricing must be time-versioned with effective date periods.
- Laravel scheduler must support periodic pricing updates.
- Sync must be idempotent: repeated upload of the same batch must not duplicate events.

### Security And Privacy

- Never upload raw prompt or response text.
- Never upload raw local log files.
- File paths are sensitive. For MVP, upload normalized project roots and source path hashes. Do not upload every raw source file path unless explicitly needed for debugging.
- API must require HTTPS in production.
- Password auth uses Laravel hashing only. Never implement custom password hashing.
- Desktop sync uses bearer tokens from Laravel Sanctum.

### Deployment

- Deploy on `the18th`.
- Mirror the `p2pbg` deployment model:
  - manual deploys
  - selective `rsync`
  - Docker Compose runtime
  - Nginx app container
  - PHP-FPM container
  - scheduler container running `php artisan schedule:work`
  - MySQL 8 container
  - Redis container
  - live env files are never overwritten by code sync
- Do not modify p2pbg files or containers.
- Use a separate deployment root:

```text
/opt/metr-sync/site
```

## 2. Proposed Repo Layout

Keep the desktop app at the current repo root. Add the server under a subfolder so it does not collide with Tauri/Vite files.

```text
MEtR/
├── docs/
│   └── sync_backend_laravel_implementation_and_deployment.md
├── sync-server/
│   ├── backend/                  # Laravel 11 app
│   │   ├── app/
│   │   ├── bootstrap/
│   │   ├── config/
│   │   ├── database/
│   │   ├── public/
│   │   ├── resources/
│   │   ├── routes/
│   │   ├── storage/
│   │   ├── tests/
│   │   ├── Dockerfile
│   │   ├── docker-entrypoint.sh
│   │   ├── php.ini
│   │   ├── php-fpm-www.conf
│   │   ├── composer.json
│   │   └── package.json
│   ├── nginx/
│   │   └── default.conf
│   ├── scripts/
│   │   └── metr_sync_preflight.sh
│   ├── docker-compose.yml
│   └── README.md
├── src/                          # Existing desktop frontend
└── src-tauri/                    # Existing desktop backend
```

## 3. Technology Choices

Use versions close to p2pbg:

- Laravel 11
- PHP 8.3 FPM Alpine
- MySQL 8.0
- Redis 7
- Nginx 1.25 Alpine
- Laravel Sanctum for API tokens
- Laravel scheduler for pricing cron
- PHPUnit for backend tests

Required Composer packages:

```bash
composer require laravel/sanctum predis/predis guzzlehttp/guzzle
composer require --dev laravel/pint
```

Use Sanctum personal access tokens for desktop API auth. Do not add Sign in with Apple, Google, GitHub, or OAuth in this phase.

## 4. Laravel Scaffold Commands

Run locally from the MEtR repo root:

```bash
cd /Users/petarpetkov/Developer/MEtR
mkdir -p sync-server
cd sync-server

composer create-project laravel/laravel backend "^11.0"
cd backend

composer require laravel/sanctum predis/predis guzzlehttp/guzzle
composer require --dev laravel/pint

php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider"
php artisan make:controller Api/V1/AuthController
php artisan make:controller Api/V1/DeviceController
php artisan make:controller Api/V1/SyncController
php artisan make:controller Api/V1/DashboardController
php artisan make:controller Api/V1/SubscriptionController
php artisan make:controller Api/V1/ProviderAccountController
php artisan make:controller Api/V1/ProjectController
php artisan make:controller Api/V1/PricingController
```

Add `routes/api.php` if Laravel did not create it. In Laravel 11, make sure API routes are loaded from `bootstrap/app.php`.

## 5. Domain Model

### 5.1 Users

Use Laravel's default `users` table, but username/password is required.

Fields:

- `id`
- `name`
- `username` unique
- `email` nullable unique
- `password`
- timestamps

Login accepts either `username` or `email`.

### 5.2 Devices

One record per desktop installation.

Table: `devices`

Columns:

- `id` ULID primary
- `user_id` foreign key to users
- `device_uuid` string unique per install
- `display_name` string
- `platform` string, examples: `macos`, `windows`, `linux`
- `hostname_hash` nullable string
- `os_version` nullable string
- `app_version` nullable string
- `last_seen_at` nullable timestamp
- timestamps

Indexes:

- unique `(user_id, device_uuid)`
- index `(user_id, platform)`

Never rely on hostname as identity. Hostnames change. Generate and persist a device UUID locally.

### 5.3 Providers

Seed these rows:

```text
openai      OpenAI / Codex
anthropic   Claude
kimi        Kimi / Moonshot
google      Gemini
cursor      Cursor
lmstudio    LM Studio
ollama      Ollama
cloudflare  Cloudflare Workers AI
generic     Generic JSONL
```

Table: `providers`

Columns:

- `id` string primary
- `display_name`
- timestamps

### 5.4 Provider Accounts

This is the key concept for your personal vs enterprise account problem.

Examples:

```text
OpenAI Enterprise - Windows
OpenAI Personal - MacBook
OpenAI Personal 2 - Mac Mini
Claude Pro - Personal
Kimi Code - Personal
```

Table: `provider_accounts`

Columns:

- `id` ULID primary
- `user_id`
- `provider_id`
- `label`
- `account_type` enum-like string: `personal`, `team`, `enterprise`, `unknown`
- `default_device_id` nullable foreign key to devices
- `external_account_hint_hash` nullable string
- `active` boolean default true
- `notes` nullable text
- timestamps

Indexes:

- index `(user_id, provider_id)`
- index `(user_id, active)`

Important: Do not assume provider account can always be detected from logs. Usually it cannot. Use rules and confidence.

### 5.5 Account Attribution Rules

Rules assign usage events to provider accounts.

Table: `account_attribution_rules`

Columns:

- `id` ULID primary
- `user_id`
- `provider_id` nullable
- `provider_account_id`
- `device_id` nullable
- `project_id` nullable
- `source_path_pattern` nullable string
- `model_pattern` nullable string
- `starts_at` nullable timestamp
- `ends_at` nullable timestamp
- `priority` integer default 100
- `enabled` boolean default true
- `notes` nullable text
- timestamps

Indexes:

- index `(user_id, enabled, priority)`
- index `(provider_id, device_id)`

Rule precedence:

1. Manual per-event or per-conversation override
2. Exact log/account hint if the client ever exposes one
3. Enabled attribution rules ordered by lowest `priority`
4. Provider account default device
5. Unknown

Store the result on each event:

- `provider_account_id`
- `account_attribution_confidence`: `exact`, `manual`, `rule`, `device_default`, `unknown`
- `account_attribution_reason`

Example rules for the current situation:

```text
Rule 10: provider=openai, device=Windows workstation -> OpenAI Enterprise - Windows
Rule 20: provider=openai, device=MacBook Air -> OpenAI Personal - MacBook
Rule 30: provider=openai, device=Mac Mini -> OpenAI Personal 2 - Mac Mini
```

If you switch accounts on one device, add a date range:

```text
provider=openai, device=Windows, starts_at=2026-05-01, ends_at=2026-06-01 -> OpenAI Enterprise
provider=openai, device=Windows, starts_at=2026-06-01 -> OpenAI Personal
```

### 5.6 Subscriptions

Subscriptions attach to provider accounts, not just providers.

Table: `subscriptions`

Columns:

- `id` ULID primary
- `user_id`
- `provider_account_id`
- `provider_id`
- `plan_name`
- `monthly_price`
- `currency` default `USD`
- `billing_anchor_day` nullable integer
- `started_on` nullable date
- `ended_on` nullable date
- `active` boolean default true
- `notes` nullable text
- timestamps

Indexes:

- index `(user_id, active)`
- index `(provider_account_id, active)`

Seed your current values after first login through UI or artisan command:

```text
Claude Pro       USD 20/month
ChatGPT Plus     USD 20/month
Kimi Code        USD 39/month
```

Do not hard-code these in migrations. They are user data.

### 5.7 Projects And Project Roots

Projects are logical. Roots are device-specific paths.

Table: `projects`

Columns:

- `id` ULID primary
- `user_id`
- `canonical_name`
- `slug`
- `manual_name` nullable string
- `active` boolean default true
- timestamps

Table: `project_roots`

Columns:

- `id` ULID primary
- `project_id`
- `user_id`
- `device_id`
- `absolute_path` string nullable
- `normalized_path_hash` string
- `display_path` string nullable
- `source_provider_id` nullable
- `first_seen_at` nullable timestamp
- `last_seen_at` nullable timestamp
- timestamps

Indexes:

- unique `(user_id, device_id, normalized_path_hash)`
- index `(user_id, project_id)`

Project grouping algorithm:

1. Normalize path separators to `/`.
2. Strip Windows drive casing differences.
3. Recognize developer root markers:
   - `/Users/<name>/Developer/<project>`
   - `/Users/<name>/iDeveloper/<project>`
   - `C:/Users/<name>/Developer/<project>`
   - `C:/Users/<name>/source/repos/<project>`
   - `/home/<name>/Developer/<project>`
4. Strip worktree suffixes:
   - `.worktrees/<name>`
   - `worktrees/<name>`
   - `.claude/worktrees/<name>`
   - `<project>--claude-worktrees-<suffix>`
5. Use the project directory name as the first canonical guess.
6. If another project already has the same canonical name for the user, attach the new root to it.
7. Provide a merge UI/API for mistakes.

Do not group by Kimi session UUID, Claude subagent UUID, or Codex rollout UUID.

### 5.8 Conversations

Table: `conversations`

Columns:

- `id` ULID primary
- `user_id`
- `provider_id`
- `device_id`
- `project_id` nullable
- `external_conversation_id`
- `display_name` nullable
- `started_at` nullable timestamp
- `last_seen_at` nullable timestamp
- timestamps

Indexes:

- unique `(user_id, provider_id, device_id, external_conversation_id)`
- index `(user_id, project_id)`

### 5.9 Usage Events

Table: `usage_events`

Columns:

- `id` ULID primary
- `user_id`
- `device_id`
- `provider_id`
- `provider_account_id` nullable
- `account_attribution_confidence` string default `unknown`
- `account_attribution_reason` nullable string
- `project_id` nullable
- `conversation_id` nullable
- `source_event_id` string
- `source_event_hash` string
- `source_file_hash` nullable string
- `source_offset` nullable bigint
- `timestamp` timestamp
- `model` nullable string
- `input_tokens` bigint default 0
- `output_tokens` bigint default 0
- `cached_input_tokens` bigint default 0
- `cache_write_tokens` bigint default 0
- `cache_read_tokens` bigint default 0
- `reasoning_tokens` bigint default 0
- `tool_tokens` bigint default 0
- `unknown_tokens` bigint default 0
- `official_api_cost_usd` decimal nullable
- `model_price_id` nullable foreign key
- `pricing_match_confidence` string default `missing`
- `warnings_json` json nullable
- `client_created_at` nullable timestamp
- `client_updated_at` nullable timestamp
- timestamps

Indexes:

- unique `(device_id, source_event_id)`
- index `(user_id, timestamp)`
- index `(user_id, provider_id, timestamp)`
- index `(user_id, project_id, timestamp)`
- index `(user_id, provider_account_id, timestamp)`
- index `(model)`

Important:

- A missing price must not block event import.
- Unknown price means cost is null and `pricing_match_confidence` is `missing` or `missing_price`.
- Zero-token synthetic errors should not become usage events. They may later become `failed_requests`, but not usage/cost events.

### 5.10 Model Prices

Prices must be period-based because providers can change pricing.

Table: `model_prices`

Columns:

- `id` ULID primary
- `provider_id`
- `model`
- `aliases_json` json nullable
- `currency` default `USD`
- `input_per_1m` decimal nullable
- `output_per_1m` decimal nullable
- `cached_input_per_1m` decimal nullable
- `cache_write_per_1m` decimal nullable
- `cache_read_per_1m` decimal nullable
- `reasoning_per_1m` decimal nullable
- `tool_per_1m` decimal nullable
- `effective_from` timestamp
- `effective_to` nullable timestamp
- `source_url` nullable string
- `source_hash` nullable string
- `catalog_version` nullable string
- `user_override` boolean default false
- `notes` nullable text
- timestamps

Indexes:

- index `(provider_id, model)`
- index `(provider_id, model, effective_from, effective_to)`

Pricing lookup:

1. Match provider.
2. Match exact model case-insensitively.
3. Match alias case-insensitively.
4. Find period where `effective_from <= event.timestamp` and (`effective_to is null` or `event.timestamp < effective_to`).
5. If no price exists, store null cost and keep the event.

### 5.11 Price Observations

For cron price updates, store raw observations before mutating active price periods.

Table: `price_observations`

Columns:

- `id` ULID primary
- `provider_id`
- `source_url`
- `fetched_at`
- `source_hash`
- `parsed_json`
- `status`: `parsed`, `parse_failed`, `unchanged`, `changed`, `manual_review`
- `error` nullable text
- timestamps

### 5.12 Sync Batches

Table: `sync_batches`

Columns:

- `id` ULID primary
- `user_id`
- `device_id`
- `client_batch_id` string
- `direction`: `upload`, `download`
- `status`: `received`, `processed`, `failed`
- `event_count` integer default 0
- `error` nullable text
- timestamps

Indexes:

- unique `(device_id, client_batch_id)`

## 6. API Contract

All API routes are under:

```text
/api/v1
```

Use JSON only. Require:

```text
Accept: application/json
Content-Type: application/json
Authorization: Bearer <token>
```

except register/login routes.

### 6.1 Auth

`POST /api/v1/auth/register`

Request:

```json
{
  "name": "Petar",
  "username": "petar",
  "email": "optional@example.com",
  "password": "long-password"
}
```

Response:

```json
{
  "token": "plain-text-sanctum-token",
  "user": {
    "id": "01...",
    "username": "petar"
  }
}
```

`POST /api/v1/auth/login`

Request:

```json
{
  "login": "petar",
  "password": "long-password",
  "device_name": "Petar MacBook Air"
}
```

Response:

```json
{
  "token": "plain-text-sanctum-token",
  "user": {
    "id": "01...",
    "username": "petar"
  }
}
```

`POST /api/v1/auth/logout`

Revoke the current Sanctum token.

### 6.2 Device Registration

`POST /api/v1/devices/register`

Request:

```json
{
  "device_uuid": "generated-on-desktop",
  "display_name": "Petar Windows Workstation",
  "platform": "windows",
  "hostname_hash": "sha256-hostname",
  "os_version": "Windows 11",
  "app_version": "0.1.0"
}
```

Response:

```json
{
  "device": {
    "id": "01...",
    "device_uuid": "generated-on-desktop",
    "display_name": "Petar Windows Workstation"
  }
}
```

### 6.3 Upload Usage Events

`POST /api/v1/sync/events`

Request:

```json
{
  "device_uuid": "generated-on-desktop",
  "client_batch_id": "uuid",
  "events": [
    {
      "source_event_id": "local-usage-event-id",
      "source_event_hash": "sha256",
      "source_file_hash": "sha256-or-null",
      "source_offset": 123,
      "provider_id": "openai",
      "timestamp": "2026-05-09T09:20:00Z",
      "model": "gpt-5.4",
      "project": {
        "root_path": "/Users/petarpetkov/Developer/MEtR",
        "display_name": "MEtR"
      },
      "conversation": {
        "external_conversation_id": "rollout-id-or-session-id",
        "display_name": null
      },
      "tokens": {
        "input": 1000,
        "output": 200,
        "cached_input": 0,
        "cache_write": 0,
        "cache_read": 0,
        "reasoning": 0,
        "tool": 0,
        "unknown": 0
      },
      "client_cost": {
        "official_api_cost_usd": 0.005,
        "pricing_match_confidence": "exact"
      },
      "warnings": []
    }
  ]
}
```

Response:

```json
{
  "batch_id": "01...",
  "received": 1,
  "inserted": 1,
  "updated": 0,
  "duplicates": 0,
  "server_time": "2026-05-09T10:00:00Z"
}
```

Rules:

- Use `unique(device_id, source_event_id)` for idempotency.
- Do not reject a whole batch because one event has missing price.
- Validate token fields are non-negative integers.
- Reject events with all token fields zero. They are not usage events.
- If project root is absent, accept the event and leave `project_id` null.

### 6.4 Download Shared Settings

`GET /api/v1/sync/settings?since=<cursor>`

Return:

- provider accounts
- attribution rules
- subscriptions
- project merges/renames
- current active model prices
- server sync cursor

### 6.5 Provider Accounts

CRUD endpoints:

```text
GET    /api/v1/provider-accounts
POST   /api/v1/provider-accounts
PATCH  /api/v1/provider-accounts/{id}
DELETE /api/v1/provider-accounts/{id}
```

Do not hard-delete if events reference the account. Mark inactive.

### 6.6 Attribution Rules

CRUD endpoints:

```text
GET    /api/v1/account-attribution-rules
POST   /api/v1/account-attribution-rules
PATCH  /api/v1/account-attribution-rules/{id}
DELETE /api/v1/account-attribution-rules/{id}
```

Add a backfill endpoint:

```text
POST /api/v1/account-attribution-rules/reapply
```

It reapplies rules to events that are not manual overrides.

### 6.7 Subscriptions

CRUD endpoints:

```text
GET    /api/v1/subscriptions
POST   /api/v1/subscriptions
PATCH  /api/v1/subscriptions/{id}
DELETE /api/v1/subscriptions/{id}
```

Delete should mark inactive unless no events/subscription history depend on it.

### 6.8 Projects

Endpoints:

```text
GET   /api/v1/projects
PATCH /api/v1/projects/{id}
POST  /api/v1/projects/{id}/merge
```

Merge request:

```json
{
  "target_project_id": "01..."
}
```

Merge behavior:

- Move project roots to target.
- Move conversations to target.
- Move usage events to target.
- Mark source project inactive.
- Keep an audit row if an audit table is added.

### 6.9 Dashboard

Endpoints:

```text
GET /api/v1/dashboard/summary?from=2026-05-01&to=2026-05-31
GET /api/v1/dashboard/by-device?from=...&to=...
GET /api/v1/dashboard/by-project?from=...&to=...
GET /api/v1/dashboard/by-provider-account?from=...&to=...
GET /api/v1/dashboard/by-model?from=...&to=...
```

Each endpoint should include:

- total input/output/cache/reasoning/tool/unknown tokens
- total official API-equivalent cost
- event count
- missing price event count
- unknown account event count

## 7. Backend Services To Implement

Create service classes. Keep controllers thin.

```text
app/Services/Sync/IngestUsageEvents.php
app/Services/Projects/NormalizeProjectRoot.php
app/Services/Projects/ResolveProject.php
app/Services/Accounts/AttributeProviderAccount.php
app/Services/Pricing/ResolveModelPrice.php
app/Services/Pricing/CalculateUsageCost.php
app/Services/Pricing/UpdateProviderPrices.php
```

### 7.1 Project Normalization Service

Input:

- raw root path from client
- device platform
- provider id

Output:

- normalized root hash
- display path
- canonical project name guess

Test cases:

```text
/Users/petarpetkov/Developer/FitHero -> FitHero
/Users/petarpetkov/Developer/FitHero/.worktrees/abc -> FitHero
/Users/petarpetkov/Developer/GichevaArt--claude-worktrees-cool-mcnulty-aa3c17 -> GichevaArt
C:\Users\Petar\Developer\FitHero -> FitHero
C:\Users\Petar\source\repos\FitHero -> FitHero
/Users/petarpetkov/.kimi/sessions/uuid/wire.jsonl -> null
```

### 7.2 Account Attribution Service

Input:

- user id
- device id
- provider id
- model
- project id
- timestamp
- optional account hint hash
- optional manual override

Output:

- provider account id nullable
- confidence
- reason

Rules:

- Manual override always wins.
- Exact account hint wins over device defaults.
- More specific rules beat generic rules.
- Lower `priority` wins.
- Date range must contain event timestamp.
- If no match, return unknown.

### 7.3 Pricing Service

Input:

- provider id
- model
- timestamp
- tokens

Output:

- cost nullable
- model price id nullable
- pricing confidence

Rules:

- Missing price is not an error.
- Unknown tokens make cost null.
- Reasoning tokens use `reasoning_per_1m`, falling back to `output_per_1m`.
- Tool tokens use `tool_per_1m`, falling back to `input_per_1m`.
- Cache read uses `cache_read_per_1m`, falling back to `cached_input_per_1m`.
- Cache write uses `cache_write_per_1m`, falling back to `input_per_1m`.

## 8. Price Update Cron

Create command:

```bash
php artisan make:command UpdateModelPrices
```

Command signature:

```text
metr:prices:update {--provider=} {--dry-run} {--force-manual-review}
```

In `routes/console.php`:

```php
Schedule::command('metr:prices:update')->dailyAt('04:10')->withoutOverlapping();
```

Provider adapters:

```text
app/Services/Pricing/Sources/OpenAiPricingSource.php
app/Services/Pricing/Sources/AnthropicPricingSource.php
app/Services/Pricing/Sources/KimiPricingSource.php
app/Services/Pricing/Sources/GooglePricingSource.php
```

Implementation rules:

- Prefer official provider pages or APIs only.
- Store every fetch in `price_observations`.
- If parsed prices equal current active period, do nothing.
- If prices changed:
  - set old active period `effective_to`
  - insert new `model_prices` period
  - enqueue/recalculate affected events from `effective_from` onward
- If parsing fails, do not mutate `model_prices`.
- If a page format changed, set observation status `manual_review`.

Do not erase historical prices. Historical events must continue using the price period active at their timestamp.

## 9. Desktop Client Changes

### 9.1 Local SQLite Additions

Add local tables/columns in the Tauri SQLite DB:

Table: `sync_settings`

- `key` string primary
- `value` text
- `updated_at`

Keys:

```text
server_url
device_uuid
device_display_name
auth_token
last_upload_at
last_download_cursor
sync_enabled
```

MVP can store `auth_token` in SQLite. Later move it to OS keychain.

Add columns to local `usage_events`:

- `synced_at` nullable text
- `remote_id` nullable text
- `sync_error` nullable text

If adding columns is awkward, create table `sync_event_state`:

- `usage_event_id` primary key
- `synced_at`
- `remote_id`
- `sync_error`

### 9.2 Tauri Commands

Add commands:

```text
get_sync_status
configure_sync_server
login_sync
logout_sync
register_device
sync_now
list_remote_provider_accounts
list_remote_subscriptions
list_remote_projects
```

### 9.3 Sync Algorithm

On `sync_now`:

1. Confirm `server_url`, `auth_token`, and `device_uuid` exist.
2. Register/update device.
3. Select unsynced local usage events in batches of 500.
4. Convert local event rows to API JSON.
5. POST `/api/v1/sync/events`.
6. Mark successful events `synced_at`.
7. Keep failed events with `sync_error`.
8. GET `/api/v1/sync/settings`.
9. Update local prices, provider accounts, subscriptions, and project merge hints.
10. Run local repricing if new price periods arrived.

Do not block local scanning if sync fails. Show sync error but keep local data.

## 10. Minimal Web UI

Laravel should have a small web UI for managing shared data. Do not overbuild it.

Pages:

- `/login`
- `/dashboard`
- `/devices`
- `/provider-accounts`
- `/account-attribution-rules`
- `/subscriptions`
- `/projects`
- `/pricing`

Dashboard filters:

- date range
- provider
- provider account
- device
- project
- model

Dashboard cards:

- total API-equivalent cost
- subscription spend
- estimated over/under subscription value
- missing price event count
- unknown provider account event count

## 11. Tests Required Before Deployment

Backend tests:

- username/password login returns Sanctum token
- device registration is idempotent
- event upload is idempotent by `(device_id, source_event_id)`
- zero-token events are rejected/skipped
- missing price imports event with null cost
- price period lookup chooses correct historical period
- project root normalization handles macOS and Windows paths
- attribution rule assigns Windows OpenAI usage to enterprise account
- manual attribution override beats rules
- project merge moves events and roots

Desktop tests can be lighter initially:

- JSON serialization of usage event upload payload
- sync batch marking does not mark failed events as synced
- local repricing survives missing prices

## 12. Docker Runtime Files

Create `sync-server/docker-compose.yml`:

```yaml
services:
  nginx:
    image: nginx:1.25-alpine
    container_name: "${COMPOSE_PROJECT_NAME:-metr-sync}-nginx"
    ports:
      - "${NGINX_PORT:-8090}:80"
    volumes:
      - ./backend:/var/www/html
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php
    networks:
      - metr-sync

  php:
    build: ./backend
    container_name: "${COMPOSE_PROJECT_NAME:-metr-sync}-php"
    environment:
      - APP_ENV=${APP_ENV}
      - APP_DEBUG=${APP_DEBUG}
      - APP_URL=${APP_URL}
      - DB_HOST=${DB_HOST}
      - DB_PORT=${DB_PORT}
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PORT=${REDIS_PORT}
    volumes:
      - ./backend:/var/www/html
    depends_on:
      - db
      - redis
    networks:
      - metr-sync

  scheduler:
    build: ./backend
    container_name: "${COMPOSE_PROJECT_NAME:-metr-sync}-scheduler"
    command: php -d memory_limit=512M artisan schedule:work
    environment:
      - APP_ENV=${APP_ENV}
      - APP_DEBUG=${APP_DEBUG}
      - APP_URL=${APP_URL}
      - DB_HOST=${DB_HOST}
      - DB_PORT=${DB_PORT}
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PORT=${REDIS_PORT}
    volumes:
      - ./backend:/var/www/html
    depends_on:
      - db
      - redis
    networks:
      - metr-sync

  db:
    image: mysql:8.0
    container_name: "${COMPOSE_PROJECT_NAME:-metr-sync}-db"
    ports:
      - "${DB_EXPOSE_PORT:-3308}:3306"
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - MYSQL_USER=${MYSQL_USER}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql
    networks:
      - metr-sync

  redis:
    image: redis:7-alpine
    container_name: "${COMPOSE_PROJECT_NAME:-metr-sync}-redis"
    command: redis-server --maxmemory 128mb --maxmemory-policy allkeys-lru
    ports:
      - "${REDIS_EXPOSE_PORT:-6381}:6379"
    networks:
      - metr-sync

networks:
  metr-sync:
    name: "${COMPOSE_PROJECT_NAME:-metr-sync}"

volumes:
  db-data:
    name: metr_sync_db_data
```

Create `sync-server/nginx/default.conf`:

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;
    charset utf-8;
    client_max_body_size 20m;
    access_log off;
    resolver 127.0.0.11 valid=30s ipv6=off;

    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/javascript application/json image/svg+xml;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri /index.php?$query_string;
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
        fastcgi_param HTTP_X_FORWARDED_FOR $http_x_forwarded_for;
        fastcgi_param HTTP_X_REAL_IP $http_x_real_ip;
        fastcgi_param HTTP_HOST $http_host;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 8 32k;
        fastcgi_busy_buffers_size 64k;
        fastcgi_max_temp_file_size 0;
    }

    location ~ /\. {
        deny all;
    }
}
```

Create `sync-server/backend/Dockerfile`:

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        bash \
        curl \
        icu-dev \
        libzip-dev \
        nodejs \
        npm \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY ./php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY ./php-fpm-www.conf /usr/local/etc/php-fpm.d/zz-metr-sync-www.conf
COPY ./docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
```

Create `sync-server/backend/docker-entrypoint.sh`:

```bash
#!/bin/bash
set -e

mkdir -p /var/www/html/bootstrap/cache
mkdir -p /var/www/html/storage/{app,framework,logs}
mkdir -p /var/www/html/storage/framework/{cache,sessions,views}

chmod -R a+rwX /var/www/html/storage
chmod -R a+rwX /var/www/html/bootstrap/cache

exec docker-php-entrypoint "$@"
```

Create `sync-server/backend/php.ini`:

```ini
upload_max_filesize=20M
post_max_size=20M
memory_limit=512M
```

Create `sync-server/backend/php-fpm-www.conf`:

```ini
[www]
pm = dynamic
pm.max_children = 16
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
```

## 13. Environment Files

There are two live env files, just like p2pbg. They are not interchangeable.

### Root Compose Env

Live path:

```text
/opt/metr-sync/site/.env
```

Example:

```env
COMPOSE_PROJECT_NAME=metr-sync
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<METR_SYNC_HOSTNAME>

NGINX_PORT=8090

DB_HOST=db
DB_PORT=3306
DB_DATABASE=metr_sync
DB_USERNAME=metr_sync
DB_PASSWORD=<strong-password>
MYSQL_ROOT_PASSWORD=<strong-root-password>
MYSQL_DATABASE=metr_sync
MYSQL_USER=metr_sync
MYSQL_PASSWORD=<same-strong-password>
DB_EXPOSE_PORT=3308

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_EXPOSE_PORT=6381
```

### Laravel Env

Live path:

```text
/opt/metr-sync/site/backend/.env
```

Example:

```env
APP_NAME=MEtR Sync
APP_ENV=production
APP_KEY=<generate-on-server>
APP_DEBUG=false
APP_URL=https://<METR_SYNC_HOSTNAME>

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=metr_sync
DB_USERNAME=metr_sync
DB_PASSWORD=<strong-password>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=<METR_SYNC_HOSTNAME>
SESSION_SECURE_COOKIE=true

REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

SANCTUM_STATEFUL_DOMAINS=<METR_SYNC_HOSTNAME>
```

Do not commit either live env file.

## 14. Server First Deploy

### 14.1 Prepare DNS And Hostname

Choose a hostname first, for example:

```text
metr.example.com
```

Point DNS to `the18th`.

If DNS is not ready, deploy behind local port `8090` and test with:

```bash
ssh -L 8090:127.0.0.1:8090 the18th
```

Then visit:

```text
http://127.0.0.1:8090
```

### 14.2 Create Server Directories

```bash
ssh the18th

sudo mkdir -p /opt/metr-sync/site
sudo chown -R deploy:deploy /opt/metr-sync
```

### 14.3 Initial Code Sync

From local machine:

```bash
cd /Users/petarpetkov/Developer/MEtR

rsync -av --delete --no-perms \
  --exclude '.env' \
  --exclude 'backend/.env' \
  --exclude 'backend/vendor/' \
  --exclude 'backend/node_modules/' \
  --exclude 'backend/storage/' \
  --exclude 'backend/bootstrap/cache/' \
  sync-server/ the18th:/opt/metr-sync/site/
```

Never run:

```bash
rsync -av --delete . the18th:/opt/metr-sync/site/
```

The repo root is the desktop app. Only `sync-server/` belongs on this server path.

### 14.4 Create Live Env Files

On server:

```bash
ssh the18th
cd /opt/metr-sync/site

nano .env
nano backend/.env

chown deploy:deploy .env backend/.env
chmod 600 .env
chmod 644 backend/.env
```

Do not copy local `.env` files over these after this point.

### 14.5 Build Containers

```bash
ssh the18th
cd /opt/metr-sync/site

docker compose config >/dev/null
docker compose build php scheduler
docker compose up -d db redis php scheduler nginx
docker compose ps
```

### 14.6 Install Dependencies And Generate Key

Because the app source is bind-mounted, install dependencies inside the PHP container:

```bash
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && composer install --no-dev --optimize-autoloader'
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && npm ci && npm run build'
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && php artisan key:generate --force'
```

Important:

- `php artisan key:generate` writes to `backend/.env`.
- Only run it on first deploy or if intentionally rotating the app key.
- Back up `backend/.env` before rotating keys.

### 14.7 Run Migrations

```bash
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && php artisan migrate --force'
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && php artisan db:seed --force'
```

### 14.8 Create First User

Add an artisan command:

```text
metr:user:create {username} {--email=} {--name=}
```

It should prompt for password securely.

Run:

```bash
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && php artisan metr:user:create petar --name="Petar"'
```

## 15. Main Proxy On the18th

p2pbg is behind a main proxy container. MEtR Sync should be added beside it, not inside p2pbg's app nginx.

Create a separate host proxy config, for example:

```text
/opt/proxy/conf.d/76-metr-sync.conf
```

Inside the `main_proxy` container it should appear under:

```text
/etc/nginx/conf.d/76-metr-sync.conf
```

Example HTTP proxy block:

```nginx
server {
    listen 80;
    server_name <METR_SYNC_HOSTNAME>;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

If the existing proxy stack handles TLS/certbot elsewhere, follow that established pattern. Do not edit `/opt/p2pbg/site/nginx/default.conf` for MEtR Sync.

Reload proxy:

```bash
ssh the18th
docker exec main_proxy nginx -t
docker exec main_proxy nginx -s reload
```

If the container name differs, find it:

```bash
docker ps --format '{{.Names}}' | grep -i proxy
```

## 16. Preflight Script

Create `sync-server/scripts/metr_sync_preflight.sh`.

Minimum checks:

- `/opt/metr-sync/site/.env` exists
- `/opt/metr-sync/site/backend/.env` exists
- env file owners/modes are correct
- Docker Compose config is valid
- `metr_sync_db_data` volume exists after first deploy
- `metr-sync-db`, `metr-sync-redis`, `metr-sync-php`, `metr-sync-scheduler`, `metr-sync-nginx` are running
- Laravel can boot
- migrations are current
- `/health` returns 200 from local nginx
- public hostname returns 200 if DNS is ready

Suggested script outline:

```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT="${METR_SYNC_ROOT:-/opt/metr-sync/site}"
BASE_URL="${METR_SYNC_BASE_URL:-https://<METR_SYNC_HOSTNAME>}"

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*" >&2; exit 1; }

cd "$ROOT" || fail "missing root $ROOT"

test -f .env || fail "missing root .env"
test -f backend/.env || fail "missing backend/.env"

docker compose config >/dev/null || fail "docker compose config failed"
docker compose ps || fail "docker compose ps failed"

docker volume inspect metr_sync_db_data >/dev/null || fail "missing db volume metr_sync_db_data"

for c in metr-sync-db metr-sync-redis metr-sync-php metr-sync-scheduler metr-sync-nginx; do
  docker inspect "$c" >/dev/null 2>&1 || fail "missing container $c"
  state="$(docker inspect -f '{{.State.Running}}' "$c")"
  [ "$state" = "true" ] || fail "$c is not running"
  pass "$c running"
done

docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan about --only=environment' >/dev/null \
  || fail "Laravel does not boot"
pass "Laravel boots"

docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan migrate:status' >/dev/null \
  || fail "migration status failed"
pass "migrations visible"

curl -fsS http://127.0.0.1:8090/health >/dev/null || fail "local health failed"
pass "local health ok"

if [ "$BASE_URL" != "https://<METR_SYNC_HOSTNAME>" ]; then
  curl -fsS "$BASE_URL/health" >/dev/null || fail "public health failed"
  pass "public health ok"
fi
```

Add `/health` route in Laravel:

```php
Route::get('/health', fn () => response()->json(['ok' => true]));
```

## 17. Routine Deployment

This mirrors p2pbg. Code sync only. Env and docker-compose are live ops files.

### 17.1 Local Checks

```bash
cd /Users/petarpetkov/Developer/MEtR/sync-server/backend

composer test
composer run pint
npm run build
```

If Docker local stack exists:

```bash
cd /Users/petarpetkov/Developer/MEtR/sync-server
docker compose up -d
docker exec -i metr-sync-php sh -lc 'cd /var/www/html && php artisan test'
```

### 17.2 Server Preflight

```bash
ssh the18th
cd /opt/metr-sync/site
./scripts/metr_sync_preflight.sh
```

If preflight fails, stop and fix before syncing.

### 17.3 Backup Runtime Files

```bash
ssh the18th
cd /opt/metr-sync/site

TS=$(date +%Y%m%d-%H%M%S)
cp -a .env .env.pre-deploy-$TS
cp -a docker-compose.yml docker-compose.yml.pre-deploy-$TS
cp -a backend/.env backend/.env.pre-deploy-$TS
```

### 17.4 Sync Code Only

From local:

```bash
cd /Users/petarpetkov/Developer/MEtR

rsync -av --delete --no-perms \
  --exclude '.env' \
  --exclude 'backend/.env' \
  --exclude 'backend/vendor/' \
  --exclude 'backend/node_modules/' \
  --exclude 'backend/storage/' \
  --exclude 'backend/bootstrap/cache/' \
  sync-server/ the18th:/opt/metr-sync/site/
```

Do not sync p2pbg. Do not sync MEtR desktop build artifacts. Do not overwrite live env files.

### 17.5 Build/Restart

On server:

```bash
ssh the18th
cd /opt/metr-sync/site

docker compose build php scheduler
docker compose up -d php scheduler nginx
```

If only PHP code changed and no Dockerfile/composer/npm changes:

```bash
docker compose restart php scheduler nginx
```

### 17.6 Dependencies, Migrations, Caches

```bash
docker exec metr-sync-php sh -lc 'cd /var/www/html && composer install --no-dev --optimize-autoloader'
docker exec metr-sync-php sh -lc 'cd /var/www/html && npm ci && npm run build'
docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan migrate --force'
docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan optimize:clear'
docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan config:cache'
docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan route:cache'
```

Only run `npm ci && npm run build` if the web UI/assets changed.

### 17.7 Permissions

```bash
ssh the18th

mkdir -p \
  /opt/metr-sync/site/backend/storage/framework/cache \
  /opt/metr-sync/site/backend/storage/framework/sessions \
  /opt/metr-sync/site/backend/storage/framework/views \
  /opt/metr-sync/site/backend/storage/logs \
  /opt/metr-sync/site/backend/bootstrap/cache

chmod 777 \
  /opt/metr-sync/site/backend/storage \
  /opt/metr-sync/site/backend/storage/framework \
  /opt/metr-sync/site/backend/storage/framework/cache \
  /opt/metr-sync/site/backend/storage/framework/sessions \
  /opt/metr-sync/site/backend/storage/framework/views \
  /opt/metr-sync/site/backend/storage/logs \
  /opt/metr-sync/site/backend/bootstrap/cache

chown deploy:deploy /opt/metr-sync/site/.env /opt/metr-sync/site/backend/.env
chmod 600 /opt/metr-sync/site/.env
chmod 644 /opt/metr-sync/site/backend/.env
```

### 17.8 Smoke Test

```bash
ssh the18th
cd /opt/metr-sync/site

curl -fsS http://127.0.0.1:8090/health
docker compose ps
docker logs --tail=100 metr-sync-php
docker logs --tail=100 metr-sync-nginx
docker logs --tail=100 metr-sync-scheduler
./scripts/metr_sync_preflight.sh
```

If DNS/TLS is configured:

```bash
curl -fsS https://<METR_SYNC_HOSTNAME>/health
```

## 18. Red Flags

Stop immediately if any of these happen:

- Any command would overwrite `/opt/metr-sync/site/.env`.
- Any command would overwrite `/opt/metr-sync/site/backend/.env` unintentionally.
- `docker compose config` points to the wrong database.
- A new unexpected DB volume appears.
- `APP_ENV` is not `production` on live.
- `APP_URL` is localhost on live.
- `/health` works locally but public hostname fails after proxy reload.
- `php artisan` works but HTTP requests fail with missing app key or permission errors.
- Events upload but duplicate on retry.
- Missing price causes event upload failure.

## 19. Rollback

For code-only rollback:

```bash
ssh the18th
cd /opt/metr-sync/site

# If you have a previous synced copy, restore it. Otherwise redeploy previous Git commit from local.
docker compose restart php scheduler nginx
docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan optimize:clear'
```

For env rollback:

```bash
ssh the18th
cd /opt/metr-sync/site

ls -1 .env.pre-deploy-* backend/.env.pre-deploy-* docker-compose.yml.pre-deploy-*
cp -a .env.pre-deploy-YYYYMMDD-HHMMSS .env
cp -a backend/.env.pre-deploy-YYYYMMDD-HHMMSS backend/.env
cp -a docker-compose.yml.pre-deploy-YYYYMMDD-HHMMSS docker-compose.yml

chown deploy:deploy .env backend/.env
chmod 600 .env
chmod 644 backend/.env

docker compose config >/dev/null
docker compose up -d
```

For bad migration:

- Do not automatically roll back production migrations unless the migration was explicitly written to be reversible and data-safe.
- Prefer forward fix migrations.
- Restore DB backup only if the data is unusable and you accept losing writes since the backup.

## 20. Implementation Milestones

### Milestone 1: Backend Skeleton

- Laravel app under `sync-server/backend`
- Docker runtime files
- health route
- username/password auth
- Sanctum tokens
- device registration
- local tests pass

### Milestone 2: Core Sync

- providers seed
- devices
- projects/project roots
- conversations
- usage events
- `/api/v1/sync/events`
- idempotent uploads
- dashboard summary API

### Milestone 3: Accounts And Subscriptions

- provider accounts
- account attribution rules
- subscriptions
- backfill/reapply attribution command
- dashboard by provider account

### Milestone 4: Pricing History

- model price periods
- pricing lookup by event timestamp
- cost calculation
- missing price handling
- current seed prices copied from desktop app

### Milestone 5: Price Cron

- `metr:prices:update`
- price observations
- daily scheduler entry
- no mutation on parse failure

### Milestone 6: Desktop Sync

- local sync settings
- login UI
- device registration
- sync now command
- batch upload
- pull shared prices/subscriptions/rules
- sync status UI

### Milestone 7: Production Deploy

- deploy to `the18th`
- main proxy config
- first user created
- desktop can login
- desktop can sync one batch
- dashboard shows events by device/project/provider account

## 21. First Seed Data

After creating the first user, add provider accounts:

```text
OpenAI Enterprise - Windows
OpenAI Personal - MacBook
OpenAI Personal - Mac Mini
Claude Pro - Personal
Kimi Code - Personal
```

Add subscriptions:

```text
Claude Pro - Personal       USD 20/month
OpenAI Personal - MacBook   USD 20/month
Kimi Code - Personal        USD 39/month
```

If the enterprise GPT account is paid by company and should not count as personal spend, create subscription with monthly price `0` and notes `Enterprise account, paid externally`.

Add attribution rules:

```text
provider=openai, device=<Windows device>, account=OpenAI Enterprise - Windows, priority=10
provider=openai, device=<MacBook>, account=OpenAI Personal - MacBook, priority=10
provider=openai, device=<Mac Mini>, account=OpenAI Personal - Mac Mini, priority=10
provider=anthropic, any Mac device, account=Claude Pro - Personal, priority=20
provider=kimi, any Mac device, account=Kimi Code - Personal, priority=20
```

If actual use differs, adjust with date-ranged rules.

## 22. What Not To Build Yet

Do not build these in phase one:

- Sign in with Apple
- Google login
- GitHub login
- real-time websocket sync
- team/multi-user organization features
- raw prompt/response cloud backup
- direct iCloud database sync
- automatic billing-provider login scraping

Keep phase one boring and reliable.

## 23. Definition Of Done

The sync backend is done when:

- A fresh user can register/login.
- Three devices can register under the same user.
- Each device can upload the same batch twice without duplicates.
- Aggregated dashboard totals match the sum of device totals.
- Project grouping merges Mac and Windows roots with the same project name.
- OpenAI Windows usage can be attributed to enterprise while Mac usage remains personal.
- Missing model price does not block event import.
- Historical price periods produce different costs for events before/after a price change.
- Laravel scheduler is running in the `metr-sync-scheduler` container.
- `/health` passes locally and through the public hostname.
- Deployment on `the18th` does not touch p2pbg files or containers.

