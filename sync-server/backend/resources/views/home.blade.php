@extends('layouts.app')

@section('title', 'MEtR — Track Your LLM Usage & Costs')

@section('content')
<style>
    .hero { text-align: center; padding: 64px 20px 48px; max-width: 800px; margin: 0 auto; }
    .hero h1 { font-size: 48px; font-weight: 800; letter-spacing: -0.03em; margin-bottom: 16px; line-height: 1.1; }
    .hero p { font-size: 18px; color: var(--muted); max-width: 560px; margin: 0 auto 32px; line-height: 1.6; }
    .hero-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    .hero-btns .btn { padding: 12px 24px; font-size: 15px; }
    .hero-btns .btn.secondary { font-weight: 500; }
    .dashboard-mockup {
        max-width: 960px; margin: 0 auto 64px;
        background: var(--card); border: 1px solid var(--border); border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .mockup-header {
        display: flex; align-items: center; gap: 8px; padding: 12px 16px;
        border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.02);
    }
    .mockup-dot { width: 10px; height: 10px; border-radius: 50%; }
    .mockup-body { padding: 24px; }
    .mockup-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
    .mockup-stat { padding: 16px; border-radius: 10px; background: var(--bg); border: 1px solid var(--border); }
    .mockup-stat .num { font-size: 22px; font-weight: 700; }
    .mockup-stat .lbl { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 4px; }
    .mockup-chart { height: 160px; border-radius: 10px; background: var(--bg); border: 1px solid var(--border); display: flex; align-items: flex-end; padding: 16px; gap: 8px; }
    .mockup-bar { flex: 1; border-radius: 4px 4px 0 0; background: linear-gradient(to top, var(--accent), #7c3aed); opacity: 0.85; }
    .features { max-width: 1000px; margin: 0 auto; padding: 48px 20px; }
    .features h2 { text-align: center; font-size: 28px; font-weight: 700; margin-bottom: 40px; }
    .feature-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .feature-card { padding: 24px; border-radius: 12px; border: 1px solid var(--border); background: var(--card); }
    .feature-card .icon { font-size: 28px; margin-bottom: 12px; }
    .feature-card h3 { font-size: 16px; font-weight: 600; margin: 0 0 6px; }
    .feature-card p { font-size: 14px; color: var(--muted); margin: 0; line-height: 1.5; }
    .cta-section { text-align: center; padding: 64px 20px; max-width: 600px; margin: 0 auto; }
    .cta-section h2 { font-size: 28px; font-weight: 700; margin-bottom: 12px; }
    .cta-section p { color: var(--muted); margin-bottom: 24px; }
    .providers-strip { display: flex; gap: 24px; justify-content: center; flex-wrap: wrap; margin-top: 32px; opacity: 0.7; }
    .providers-strip span { font-size: 13px; color: var(--muted); font-weight: 500; }
    footer { text-align: center; padding: 32px 20px; border-top: 1px solid var(--border); color: var(--muted); font-size: 13px; }
    @media (max-width: 768px) {
        .hero h1 { font-size: 34px; }
        .feature-grid { grid-template-columns: 1fr; }
        .mockup-stats { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div class="hero">
    <h1>Know exactly what your LLM habit costs.</h1>
    <p>MEtR reads your local AI chat logs — Claude, ChatGPT, Cursor, Ollama, and more — and tells you what the same usage would cost on the API. Track subscriptions, compare spend, and never guess again.</p>
    <div class="hero-btns">
        <a href="/demo-login" class="btn">Try Live Demo</a>
        <a href="/download" class="btn secondary">Download Free</a>
        <a href="/register" class="btn secondary">Create Account</a>
    </div>
</div>

<div class="dashboard-mockup">
    <div class="mockup-header">
        <div class="mockup-dot" style="background:#ef4444;"></div>
        <div class="mockup-dot" style="background:#f59e0b;"></div>
        <div class="mockup-dot" style="background:#10b981;"></div>
        <span class="muted" style="font-size:12px;margin-left:4px;">MEtR Dashboard</span>
    </div>
    <div class="mockup-body">
        <div class="mockup-stats">
            <div class="mockup-stat">
                <div class="num">2.4M</div>
                <div class="lbl">Total Tokens</div>
            </div>
            <div class="mockup-stat">
                <div class="num">$847.32</div>
                <div class="lbl">API Equivalent</div>
            </div>
            <div class="mockup-stat">
                <div class="num">$240.00</div>
                <div class="lbl">Subscriptions</div>
            </div>
            <div class="mockup-stat">
                <div class="num">3.5x</div>
                <div class="lbl">Value Ratio</div>
            </div>
        </div>
        <div class="mockup-chart">
            <div class="mockup-bar" style="height:45%"></div>
            <div class="mockup-bar" style="height:62%"></div>
            <div class="mockup-bar" style="height:38%"></div>
            <div class="mockup-bar" style="height:78%"></div>
            <div class="mockup-bar" style="height:55%"></div>
            <div class="mockup-bar" style="height:90%"></div>
            <div class="mockup-bar" style="height:65%"></div>
            <div class="mockup-bar" style="height:48%"></div>
            <div class="mockup-bar" style="height:72%"></div>
            <div class="mockup-bar" style="height:58%"></div>
            <div class="mockup-bar" style="height:85%"></div>
            <div class="mockup-bar" style="height:60%"></div>
        </div>
    </div>
</div>

<div class="features">
    <h2>Everything you need to stay on top of AI spend</h2>
    <div class="feature-grid">
        <div class="feature-card">
            <div class="icon">📁</div>
            <h3>Local-First Parsing</h3>
            <p>Reads conversation logs from Claude, ChatGPT, Cursor, Kimi, Ollama, LM Studio, and more. Your raw files never leave your machine.</p>
        </div>
        <div class="feature-card">
            <div class="icon">💰</div>
            <h3>Real Pricing Data</h3>
            <p>Live model pricing from OpenAI, Anthropic, Google, and others. Cost calculations update automatically as prices change.</p>
        </div>
        <div class="feature-card">
            <div class="icon">📊</div>
            <h3>Dashboard & Reports</h3>
            <p>Web dashboard with daily breakdowns, project grouping, provider splits, and device-level reporting. Filter by date, model, project, and more.</p>
        </div>
        <div class="feature-card">
            <div class="icon">🔄</div>
            <h3>Multi-Device Sync</h3>
            <p>Optional cloud sync keeps your usage data consistent across all your machines. End-to-end encrypted in transit.</p>
        </div>
        <div class="feature-card">
            <div class="icon">🧾</div>
            <h3>Subscription Tracking</h3>
            <p>Log your monthly AI subscriptions and see how your actual usage compares to what you're paying. Are you getting your money's worth?</p>
        </div>
        <div class="feature-card">
            <div class="icon">🔒</div>
            <h3>Privacy by Default</h3>
            <p>All parsing happens locally. Only anonymized token counts and model names are synced. No conversation content ever touches our servers.</p>
        </div>
    </div>
</div>

<div class="cta-section">
    <h2>Ready to see your real LLM costs?</h2>
    <p>Download MEtR for macOS or Windows, create a free account, and get your first report in under a minute.</p>
    <div class="hero-btns">
        <a href="/demo-login" class="btn">Try Live Demo</a>
        <a href="/download" class="btn secondary">Download for Free</a>
        <a href="/register" class="btn secondary">Create Account</a>
    </div>
    <div class="providers-strip">
        <span>Claude</span>
        <span>ChatGPT</span>
        <span>Cursor</span>
        <span>Kimi</span>
        <span>Ollama</span>
        <span>LM Studio</span>
        <span>Continue</span>
        <span>Cline</span>
    </div>
</div>

<footer>
    &copy; {{ date('Y') }} MEtR. Open source on <a href="https://github.com/mar0der/MEtR" target="_blank" style="color:var(--accent);text-decoration:none;">GitHub</a>.
</footer>
@endsection
