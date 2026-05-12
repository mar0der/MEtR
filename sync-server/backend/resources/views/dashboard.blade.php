@extends('layouts.app')

@section('title', 'Dashboard - MEtR Sync')

@section('content')
<h1>Dashboard</h1>

<div class="grid stats-grid">
    {{-- Row 1: Money & totals --}}
    <div class="card stat" style="border-left:4px solid var(--accent);">
        <div class="value" style="color:var(--accent);">{{ ($summary['total_cost'] ?? null) !== null ? '$'.number_format((float) $summary['total_cost'], 2) : '—' }}</div>
        <div class="label">API Equivalent Cost</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['total_tokens'] ?? 0) }}</div>
        <div class="label">Total Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['event_count'] ?? 0) }}</div>
        <div class="label">Events</div>
    </div>

    {{-- Row 2: Token breakdown --}}
    <div class="card stat">
        <div class="value">{{ number_format($summary['input_tokens'] ?? 0) }}</div>
        <div class="label">Input Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['output_tokens'] ?? 0) }}</div>
        <div class="label">Output Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['cached_input_tokens'] ?? 0) }}</div>
        <div class="label">Cached Input Tokens</div>
    </div>

    {{-- Row 3: Data quality indicators --}}
    <div class="card stat">
        <div class="value">{{ number_format($summary['unpriced_count'] ?? 0) }}</div>
        <div class="label">Unpriced Events</div>
        <div class="muted" style="font-size:11px; margin-top:4px;">Missing token breakdown — can't calculate cost</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['unattributed_count'] ?? 0) }}</div>
        <div class="label">Unattributed Account</div>
        <div class="muted" style="font-size:11px; margin-top:4px;">No API key matched for these events</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['unknown_tokens'] ?? 0) }}</div>
        <div class="label">Unknown Tokens</div>
        <div class="muted" style="font-size:11px; margin-top:4px;">Total tokens with no category breakdown</div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">Filters</h3>
    <form method="GET" action="/dashboard" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label>From</label>
            <input type="date" name="from" value="{{ request('from') }}" style="width:160px;margin:0;">
        </div>
        <div>
            <label>To</label>
            <input type="date" name="to" value="{{ request('to') }}" style="width:160px;margin:0;">
        </div>
        <div>
            <button class="btn" style="margin:0;">Apply</button>
        </div>
    </form>
</div>

<div class="tabs">
    <button class="tab-btn active" onclick="switchTab(event, 'tab-devices')">Devices & Projects</button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-accounts')">Accounts & Models</button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-all')">All Tables</button>
</div>

<div id="tab-devices" class="tab-content" style="display:block;">
    @include('partials.dashboard-table', ['title' => 'By Device', 'rows' => $byDevice])
    @include('partials.dashboard-table', ['title' => 'By Project', 'rows' => $byProject])
</div>

<div id="tab-accounts" class="tab-content" style="display:none;">
    @include('partials.dashboard-table', ['title' => 'By Provider Account', 'rows' => $byProviderAccount])
    @include('partials.dashboard-table', ['title' => 'By Model', 'rows' => $byModel])
</div>

<div id="tab-all" class="tab-content" style="display:none;">
    @include('partials.dashboard-table', ['title' => 'By Device', 'rows' => $byDevice])
    @include('partials.dashboard-table', ['title' => 'By Project', 'rows' => $byProject])
    @include('partials.dashboard-table', ['title' => 'By Provider Account', 'rows' => $byProviderAccount])
    @include('partials.dashboard-table', ['title' => 'By Model', 'rows' => $byModel])
</div>

<script>
function switchTab(evt, tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).style.display = 'block';
    evt.currentTarget.classList.add('active');
}
</script>
@endsection
