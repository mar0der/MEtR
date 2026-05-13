@extends('layouts.app')

@section('title', 'Dashboard - MEtR Sync')

@section('content')
<h1>Dashboard</h1>

<div class="grid stats-grid">
    {{-- Row 1: Cost, Events, Total Tokens --}}
    <div class="card stat stat-accent">
        <div class="value" style="color:var(--accent);">{{ ($summary['total_cost'] ?? null) !== null ? '$'.number_format((float) $summary['total_cost'], 2) : '—' }}</div>
        <div class="label">API Equivalent Cost</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['event_count'] ?? 0) }}</div>
        <div class="label">Events</div>
    </div>
    <div class="card stat stat-success">
        <div class="value" style="color:var(--success);">{{ number_format($summary['total_tokens'] ?? 0) }}</div>
        <div class="label">Total Tokens</div>
    </div>

    {{-- Row 2: Cached, Real Input, Output --}}
    <div class="card stat">
        <div class="value">{{ number_format($summary['cached_input_tokens'] ?? 0) }}</div>
        <div class="label">Cached Input Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['effective_input_tokens'] ?? 0) }}</div>
        <div class="label">Real Input Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['output_tokens'] ?? 0) }}</div>
        <div class="label">Output Tokens</div>
    </div>
</div><div class="card">
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
    <button class="tab-btn" onclick="switchTab(event, 'tab-events')">Recent Events</button>
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

<div id="tab-events" class="tab-content" style="display:none;">
    <div class="card">
        <h3 style="margin-top:0;">Recent Events ({{ $events->total() }} total)</h3>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Project</th>
                    <th>Type</th>
                    <th>Provider</th>
                    <th>Model</th>
                    <th style="text-align:right;">Tokens</th>
                    <th style="text-align:right;">Cost</th>
                </tr>
            </thead>
            <tbody>
                @forelse($events as $e)
                <tr>
                    <td style="white-space:nowrap;font-size:12px;">{{ \Carbon\Carbon::parse($e->timestamp)->format('M j, g:i A') }}</td>
                    <td>{{ $e->project_name ?? '—' }}</td>
                    <td>
                        @if($e->event_type)
                            <span style="font-size:11px;background:var(--accent-soft);color:var(--accent);padding:2px 6px;border-radius:4px;font-weight:600;">{{ $e->event_type }}</span>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td style="font-size:12px;">{{ $e->provider_id }}</td>
                    <td style="font-size:12px;max-width:140px;overflow:hidden;text-overflow:ellipsis;">{{ $e->model ?? '—' }}</td>
                    <td style="text-align:right;white-space:nowrap;font-size:13px;">
                        {{ number_format($e->input_tokens + $e->output_tokens + $e->cache_write_tokens + $e->cache_read_tokens + $e->reasoning_tokens + $e->tool_tokens + $e->unknown_tokens) }}
                    </td>
                    <td style="text-align:right;white-space:nowrap;font-size:13px;">
                        {{ $e->official_api_cost_usd !== null ? '$'.number_format((float) $e->official_api_cost_usd, 4) : '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="muted">No synced usage yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($events->hasPages())
        <div style="margin-top:16px;display:flex;justify-content:center;gap:6px;flex-wrap:wrap;">
            @if($events->onFirstPage())
                <span class="btn secondary" style="opacity:0.5;cursor:default;">← Prev</span>
            @else
                <a href="{{ $events->previousPageUrl() }}" class="btn secondary">← Prev</a>
            @endif

            @foreach($events->getUrlRange(1, $events->lastPage()) as $page => $url)
                @if($page == $events->currentPage())
                    <span class="btn" style="min-width:36px;padding:6px 10px;">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" class="btn secondary" style="min-width:36px;padding:6px 10px;">{{ $page }}</a>
                @endif
            @endforeach

            @if($events->hasMorePages())
                <a href="{{ $events->nextPageUrl() }}" class="btn secondary">Next →</a>
            @else
                <span class="btn secondary" style="opacity:0.5;cursor:default;">Next →</span>
            @endif
        </div>
        @endif
    </div>
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
