@extends('layouts.app')

@section('title', 'MEtR Guide — How to Track LLM Usage & API Costs')
@section('description', 'Complete guide to MEtR: track LLM token usage from Claude, ChatGPT, Cursor, Ollama, and more. Understand pricing, caching, and how to calculate real API-equivalent costs.')

@section('content')
<style>
    .guide-hero { text-align: center; padding: 48px 20px 32px; max-width: 780px; margin: 0 auto; }
    .guide-hero h1 { font-size: 36px; font-weight: 800; letter-spacing: -0.03em; margin-bottom: 12px; }
    .guide-hero p { font-size: 17px; color: var(--muted); line-height: 1.6; }
    .guide-nav {
        position: sticky; top: 0; z-index: 10;
        background: var(--bg); border-bottom: 1px solid var(--border);
        padding: 12px 20px; margin: 0 -20px 32px;
        display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;
    }
    .guide-nav a {
        padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;
        color: var(--muted); text-decoration: none; background: var(--card); border: 1px solid var(--border);
        transition: all .15s;
    }
    .guide-nav a:hover { color: var(--accent); border-color: var(--accent); }
    .guide-section { max-width: 780px; margin: 0 auto; padding: 32px 20px; }
    .guide-section h2 { font-size: 24px; font-weight: 700; margin: 0 0 16px; letter-spacing: -0.02em; }
    .guide-section h3 { font-size: 18px; font-weight: 600; margin: 28px 0 10px; }
    .guide-section p { color: var(--muted); line-height: 1.7; margin: 0 0 14px; }
    .guide-section ul { color: var(--muted); line-height: 1.8; padding-left: 20px; margin: 0 0 14px; }
    .guide-section code {
        background: var(--bg); padding: 2px 6px; border-radius: 4px;
        font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        border: 1px solid var(--border);
    }
    .guide-section pre {
        background: var(--bg); padding: 14px 16px; border-radius: 10px;
        border: 1px solid var(--border); overflow-x: auto; font-size: 13px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        line-height: 1.6; margin: 0 0 16px;
    }
    .guide-section pre code { background: none; padding: 0; border: none; }
    .provider-table { width: 100%; font-size: 14px; }
    .provider-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; }
    .provider-table td { vertical-align: top; }
    .provider-table code { white-space: nowrap; }
    .pricing-source {
        display: flex; align-items: center; gap: 10px; padding: 12px 14px;
        border-radius: 10px; border: 1px solid var(--border); background: var(--card);
        margin-bottom: 10px;
    }
    .pricing-source .name { font-weight: 600; min-width: 120px; }
    .pricing-source .url { color: var(--muted); font-size: 13px; word-break: break-all; }
    .pricing-source a { color: var(--accent); text-decoration: none; font-size: 13px; }
    .toc-card {
        background: var(--card); border: 1px solid var(--border); border-radius: 12px;
        padding: 20px; margin-bottom: 24px;
    }
    .toc-card h3 { margin: 0 0 12px; font-size: 15px; }
    .toc-card ul { margin: 0; padding-left: 18px; }
    .toc-card li { margin-bottom: 6px; }
    .toc-card a { color: var(--muted); text-decoration: none; font-size: 14px; }
    .toc-card a:hover { color: var(--accent); }
    .highlight-box {
        padding: 16px 18px; border-radius: 10px;
        background: var(--accent-soft); border: 1px solid var(--accent-soft);
        margin-bottom: 16px;
    }
    .highlight-box p { color: inherit; margin: 0; }
    .cta-bar {
        text-align: center; padding: 48px 20px;
        background: linear-gradient(180deg, transparent, rgba(37,99,235,0.04));
        border-top: 1px solid var(--border);
    }
    .cta-bar h2 { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
    .cta-bar p { color: var(--muted); margin-bottom: 20px; }
    @media (max-width: 640px) {
        .guide-hero h1 { font-size: 28px; }
        .guide-section h2 { font-size: 20px; }
    }
</style>

<div class="guide-hero">
    <h1>MEtR Guide</h1>
    <p>Everything you need to know about tracking LLM token usage, understanding API pricing, and calculating what your AI habit actually costs.</p>
</div>

<div class="guide-nav">
    <a href="#why-track">Why Track</a>
    <a href="#install">Install</a>
    <a href="#providers">Providers</a>
    <a href="#how-pricing-works">Pricing</a>
    <a href="#configure">Configure</a>
    <a href="#sync">Sync</a>
    <a href="#faq">FAQ</a>
</div>

<div class="guide-section" id="why-track">
    <h2>Why Track Your LLM Usage?</h2>
    <p>Most developers have no idea how much their AI usage would cost on a pay-per-token API. A $20/month ChatGPT Plus subscription might cover 500K–1M tokens, but if you're a power user, the same usage on the raw API could cost $50–200. The opposite is also true — light users often overpay for unlimited subscriptions.</p>
    <p>MEtR solves this by reading your local conversation logs and calculating the API-equivalent cost in real time. It also tracks your subscription spend so you can answer questions like:</p>
    <ul>
        <li>Am I getting my money's worth from Claude Pro?</li>
        <li>Would I save money switching to API billing?</li>
        <li>Which project consumes the most tokens?</li>
        <li>How much am I spending on AI per month across all tools?</li>
    </ul>
    <p>All parsing happens locally. Your conversation content never leaves your machine. Only anonymized token counts and model names are optionally synced to the cloud dashboard.</p>
</div>

<div class="guide-section" id="install">
    <h2>Installation & Quick Start</h2>
    <h3>1. Download MEtR</h3>
    <p>MEtR is a desktop app built with Tauri. Download the latest release for your platform:</p>
    <ul>
        <li><strong>macOS</strong> — Apple Silicon (M1/M2/M3/M4). Download the <code>.dmg</code> installer.</li>
        <li><strong>Windows</strong> — Windows 10/11 x64. Download the <code>.msi</code> installer.</li>
    </ul>

    <h3>2. Add Your Log Sources</h3>
    <p>On first launch, MEtR auto-detects common LLM tool directories. You can also manually add sources in <strong>Settings → Sources</strong>:</p>
    <pre><code>~/.claude              → Claude conversations
~/.codex               → OpenAI Codex CLI logs
~/.kimi                → Kimi / Moonshot sessions
~/.gemini              → Gemini CLI logs
~/.continue            → Continue.dev logs
~/Library/Application Support/Cursor  → Cursor composer logs
~/Library/Application Support/Code/User/globalStorage  → Cline / Roo Code</code></pre>

    <h3>3. Run Your First Scan</h3>
    <p>Click <strong>Scan</strong> in the app. MEtR will index all supported log files, extract token counts, match models to pricing data, and calculate costs. For large histories (10K+ events), this may take 30–60 seconds.</p>

    <h3>4. Add Subscriptions</h3>
    <p>Go to <strong>Settings → Subscriptions</strong> and add your monthly AI subscriptions (Claude Pro, ChatGPT Plus, Cursor Pro, etc.). MEtR will compare your subscription cost against the API-equivalent usage cost.</p>
</div>

<div class="guide-section" id="providers">
    <h2>Supported Providers & Log Paths</h2>
    <p>MEtR parses conversation logs from the following tools. Each uses a different log format, so parser assignment is handled automatically based on the source path.</p>

    <div class="table-wrap">
        <table class="provider-table">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Parser</th>
                    <th>Typical Log Path</th>
                    <th>Pricing Page</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Claude</strong> (Anthropic)</td>
                    <td><code>claude</code></td>
                    <td><code>~/.claude</code></td>
                    <td><a href="https://docs.anthropic.com/en/docs/about-claude/pricing" target="_blank">Anthropic Pricing</a></td>
                </tr>
                <tr>
                    <td><strong>OpenAI / Codex</strong></td>
                    <td><code>codex</code></td>
                    <td><code>~/.codex</code></td>
                    <td><a href="https://openai.com/api/pricing/" target="_blank">OpenAI Pricing</a></td>
                </tr>
                <tr>
                    <td><strong>Cursor</strong></td>
                    <td><code>generic_jsonl</code></td>
                    <td><code>~/Library/Application Support/Cursor</code></td>
                    <td><a href="https://www.cursor.com/pricing" target="_blank">Cursor Pricing</a></td>
                </tr>
                <tr>
                    <td><strong>Gemini</strong> (Google)</td>
                    <td><code>gemini</code></td>
                    <td><code>~/.gemini</code></td>
                    <td><a href="https://ai.google.dev/gemini-api/docs/pricing" target="_blank">Gemini Pricing</a></td>
                </tr>
                <tr>
                    <td><strong>Kimi / Moonshot</strong></td>
                    <td><code>generic_jsonl</code></td>
                    <td><code>~/.kimi</code> or <code>~/.moonshot</code></td>
                    <td><a href="https://platform.moonshot.cn/" target="_blank">Moonshot Platform</a></td>
                </tr>
                <tr>
                    <td><strong>Cline / Roo Code</strong></td>
                    <td><code>generic_jsonl</code></td>
                    <td><code>~/Library/Application Support/Code/User/globalStorage</code></td>
                    <td>—</td>
                </tr>
                <tr>
                    <td><strong>Continue.dev</strong></td>
                    <td><code>continue</code></td>
                    <td><code>~/.continue</code></td>
                    <td>—</td>
                </tr>
                <tr>
                    <td><strong>Ollama</strong></td>
                    <td><code>generic_jsonl</code></td>
                    <td><code>~/.ollama</code></td>
                    <td>—</td>
                </tr>
                <tr>
                    <td><strong>LM Studio</strong></td>
                    <td><code>generic_jsonl</code></td>
                    <td><code>~/.lmstudio</code></td>
                    <td>—</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="guide-section" id="how-pricing-works">
    <h2>How Pricing Works</h2>
    <p>MEtR calculates API-equivalent costs by matching each event's model name to live pricing data, then applying the provider's specific token counting rules.</p>

    <h3>Token Types</h3>
    <ul>
        <li><strong>Input</strong> — tokens sent to the model in your prompt (excluding cached tokens)</li>
        <li><strong>Output</strong> — tokens generated by the model in its response</li>
        <li><strong>Cached</strong> — tokens that were read from cache (discounted rate)</li>
        <li><strong>Cache Write</strong> — tokens stored into cache for future reuse</li>
        <li><strong>Cache Read</strong> — tokens retrieved from cache</li>
        <li><strong>Reasoning</strong> — thinking/reasoning tokens (OpenAI o-series models)</li>
        <li><strong>Tool</strong> — tokens used in tool/function calls</li>
    </ul>

    <h3>Provider-Specific Token Semantics</h3>
    <p>Different providers report input tokens differently. MEtR normalizes this so cost calculations are accurate:</p>

    <div class="highlight-box">
        <p><strong>OpenAI / Codex:</strong> <code>input_tokens</code> includes cached tokens. Effective input = <code>input_tokens - cached_input_tokens</code>.</p>
    </div>
    <div class="highlight-box">
        <p><strong>Anthropic:</strong> <code>input_tokens</code> is uncached only. Cached tokens are reported separately. Displayed as-is.</p>
    </div>
    <div class="highlight-box">
        <p><strong>Kimi:</strong> <code>input_other</code> is uncached only. Displayed as-is with no subtraction.</p>
    </div>

    <h3>Cost Formula</h3>
    <pre><code>cost = (effective_input / 1_000_000 * input_price)
     + (output / 1_000_000 * output_price)
     + (cached / 1_000_000 * cached_price)
     + (cache_write / 1_000_000 * cache_write_price)
     + (cache_read / 1_000_000 * cache_read_price)
     + (reasoning / 1_000_000 * reasoning_price)</code></pre>

    <h3>Where Pricing Data Comes From</h3>
    <p>MEtR pulls live pricing from multiple authoritative sources and stores them in a local SQLite database. Prices are updated automatically via a scheduled job on the sync server.</p>

    <div class="pricing-source">
        <span class="name">LiteLLM</span>
        <span class="url"><a href="https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json" target="_blank">github.com/BerriAI/litellm</a> — OpenAI, Anthropic, Gemini, Moonshot</span>
    </div>
    <div class="pricing-source">
        <span class="name">Anthropic</span>
        <span class="url"><a href="https://docs.anthropic.com/en/docs/about-claude/pricing" target="_blank">docs.anthropic.com</a></span>
    </div>
    <div class="pricing-source">
        <span class="name">OpenAI</span>
        <span class="url"><a href="https://openai.com/api/pricing/" target="_blank">openai.com/api/pricing</a></span>
    </div>
    <div class="pricing-source">
        <span class="name">Google</span>
        <span class="url"><a href="https://ai.google.dev/gemini-api/docs/pricing" target="_blank">ai.google.dev</a></span>
    </div>
    <div class="pricing-source">
        <span class="name">Moonshot</span>
        <span class="url"><a href="https://platform.moonshot.cn/" target="_blank">platform.moonshot.cn</a></span>
    </div>

    <p>If a model price is missing, MEtR falls back to the client-provided cost from the log file (when available). You can also manually override prices in the web dashboard under <strong>Pricing</strong>.</p>
</div>

<div class="guide-section" id="configure">
    <h2>Configuration Guide</h2>

    <h3>Log Sources</h3>
    <p>Each source folder is assigned a parser. MEtR auto-detects common paths on first run. To add more:</p>
    <ol>
        <li>Open <strong>Settings → Sources</strong></li>
        <li>Click <strong>Add Source</strong></li>
        <li>Select the folder containing your LLM logs</li>
        <li>Pick the correct parser from the dropdown</li>
        <li>Click <strong>Scan</strong> to index</li>
    </ol>
    <p>For incremental updates, click <strong>Scan</strong> again. MEtR only processes new or changed files.</p>

    <h3>Subscriptions</h3>
    <p>Subscriptions help you compare flat-fee plans against API usage:</p>
    <ol>
        <li>Go to <strong>Settings → Subscriptions</strong></li>
        <li>Add each plan (Claude Pro $20/mo, ChatGPT Plus $20/mo, etc.)</li>
        <li>Set the <strong>billing anchor day</strong> (the day of the month you're charged)</li>
    </ol>
    <p>The dashboard will show your total subscription cost alongside API-equivalent usage cost.</p>

    <h3>Provider Accounts</h3>
    <p>If you use multiple API keys or accounts (e.g., work vs. personal), create provider accounts and set attribution rules so usage is split correctly in reports.</p>
</div>

<div class="guide-section" id="sync">
    <h2>Cloud Sync</h2>
    <p>MEtR is local-first — all parsing and storage happens on your machine. Optional cloud sync uploads anonymized usage events to your private dashboard at <code>metr.petarpetkov.com</code>.</p>

    <h3>What Gets Synced</h3>
    <ul>
        <li>Token counts (input, output, cached, etc.)</li>
        <li>Model names and provider IDs</li>
        <li>Project names and conversation IDs</li>
        <li>Calculated API-equivalent costs</li>
    </ul>

    <h3>What Does NOT Get Synced</h3>
    <ul>
        <li>Conversation content / message text</li>
        <li>Raw log files</li>
        <li>File paths (only project root names)</li>
        <li>Any personal identifiable information</li>
    </ul>

    <h3>How to Enable Sync</h3>
    <ol>
        <li>Create a free account at <a href="/register">metr.petarpetkov.com</a></li>
        <li>In the MEtR desktop app, go to <strong>Settings → Sync</strong></li>
        <li>Enter your username and password</li>
        <li>Click <strong>Sync Now</strong></li>
    </ol>
    <p>Events are deduplicated by <code>device_id + source_event_id</code>, so re-syncing never creates duplicates.</p>
</div>

<div class="guide-section" id="faq">
    <h2>FAQ</h2>

    <h3>Does MEtR read my conversation content?</h3>
    <p>No. MEtR parses only metadata from log files: token counts, model names, timestamps, and project paths. The actual message text is never read or stored.</p>

    <h3>Can I use MEtR without an internet connection?</h3>
    <p>Yes. All parsing, cost calculation, and local dashboard functionality works offline. Internet is only needed for optional cloud sync and price updates.</p>

    <h3>How accurate are the cost calculations?</h3>
    <p>Within ±5% for most providers, assuming the correct model price is matched. Edge cases (promotional pricing, enterprise discounts, custom rates) may differ. MEtR shows a confidence level for each event's pricing match.</p>

    <h3>What if my model isn't in the pricing catalog?</h3>
    <p>MEtR will flag it as "missing price" and use the client-provided cost from the log file if available. You can manually add prices in the web dashboard under <strong>Pricing</strong>.</p>

    <h3>Is my data portable?</h3>
    <p>Yes. The local database is a standard SQLite file. You can query it directly, export to CSV, or back it up like any other file.</p>

    <h3>How do I delete my cloud data?</h3>
    <p>Log in to the web dashboard, go to <strong>Settings → Clear All Server Data</strong>. This removes all usage events, projects, and conversations from the server while keeping your account intact.</p>
</div>

<div class="cta-bar">
    <h2>Ready to track your LLM spend?</h2>
    <p>Download MEtR for free and get your first cost report in under a minute.</p>
    <div class="hero-btns" style="justify-content:center;">
        <a href="/download" class="btn">Download Free</a>
        <a href="/demo-login" class="btn secondary">Try Live Demo</a>
    </div>
</div>
@endsection
