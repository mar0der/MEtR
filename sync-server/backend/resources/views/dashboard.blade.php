@extends('layouts.app')

@section('title', 'Dashboard - MEtR Sync')

@section('content')
<h1>Dashboard</h1>

<div class="grid">
    <div class="card stat">
        <div class="value">{{ number_format($summary['event_count'] ?? 0) }}</div>
        <div class="label">Events</div>
    </div>
    <div class="card stat">
        <div class="value">{{ ($summary['total_cost'] ?? null) !== null ? '$'.number_format((float) $summary['total_cost'], 2) : '—' }}</div>
        <div class="label">API Equivalent Cost</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['input_tokens'] ?? 0) }}</div>
        <div class="label">Input Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['output_tokens'] ?? 0) }}</div>
        <div class="label">Output Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['missing_price_count'] ?? 0) }}</div>
        <div class="label">Missing Prices</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['unknown_account_count'] ?? 0) }}</div>
        <div class="label">Unknown Account</div>
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

<div class="grid">
    @include('partials.dashboard-table', ['title' => 'By Device', 'rows' => $byDevice])
    @include('partials.dashboard-table', ['title' => 'By Project', 'rows' => $byProject])
    @include('partials.dashboard-table', ['title' => 'By Provider Account', 'rows' => $byProviderAccount])
    @include('partials.dashboard-table', ['title' => 'By Model', 'rows' => $byModel])
</div>
@endsection
