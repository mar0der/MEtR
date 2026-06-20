@extends('layouts.app')

@section('title', 'Dashboard - MEtR Sync')

@section('content')
@php
    $tabQuery = fn (string $tab) => url('/dashboard').'?'.http_build_query(array_merge(request()->except('page'), ['tab' => $tab]));
    $otherTokens = (int) (($summary['reasoning_tokens'] ?? 0) + ($summary['tool_tokens'] ?? 0) + ($summary['unknown_tokens'] ?? 0));
@endphp

<div class="page-heading">
    <div>
        <h1>Dashboard</h1>
        <div class="muted">Server-side usage analytics across synced devices.</div>
    </div>
    <a class="btn secondary" href="/dashboard">Reset</a>
</div>

<div class="grid stats-grid">
    {{-- Row 1: Cost, Subscription Spend, Events, Total Tokens --}}
    <div class="card stat stat-accent">
        <div class="value" style="color:var(--accent);">{{ ($summary['total_cost'] ?? null) !== null ? '$'.number_format((float) $summary['total_cost'], 2) : '—' }}</div>
        <div class="label">API Equivalent Cost</div>
    </div>
    <div class="card stat stat-accent">
        <div class="value" style="color:var(--accent);">${{ number_format((float) ($summary['subscription_cost'] ?? 0), 2) }}</div>
        <div class="label">Total Cost Spent</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['event_count'] ?? 0) }}</div>
        <div class="label">Events</div>
    </div>
    <div class="card stat stat-success">
        <div class="value" style="color:var(--success);">{{ number_format($summary['total_tokens'] ?? 0) }}</div>
        <div class="label">Total Tokens</div>
    </div>

    {{-- Row 2: Token breakdown --}}
    <div class="card stat">
        <div class="value">{{ number_format($summary['cached_tokens'] ?? 0) }}</div>
        <div class="label">Cached Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['effective_input_tokens'] ?? 0) }}</div>
        <div class="label">Real Input Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['output_tokens'] ?? 0) }}</div>
        <div class="label">Output Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($otherTokens) }}</div>
        <div class="label">Other Tokens</div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">Filters</h3>
    <form method="GET" action="/dashboard" class="filters">
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <div>
            <label>From</label>
            <input type="date" name="from" value="{{ request('from') }}">
        </div>
        <div>
            <label>To</label>
            <input type="date" name="to" value="{{ request('to') }}">
        </div>
        <div>
            <label>Provider</label>
            <select name="provider_id">
                <option value="">All providers</option>
                @foreach($filterOptions['providers'] as $provider)
                    <option value="{{ $provider->id }}" @selected(request('provider_id') === $provider->id)>{{ $provider->display_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Device</label>
            <select name="device_id">
                <option value="">All devices</option>
                @foreach($filterOptions['devices'] as $device)
                    <option value="{{ $device->id }}" @selected(request('device_id') === $device->id)>{{ $device->alias ?: $device->display_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Project</label>
            <select name="project_id">
                <option value="">All projects</option>
                @foreach($filterOptions['projects'] as $project)
                    <option value="{{ $project->id }}" @selected(request('project_id') === $project->id)>{{ $project->manual_name ?: $project->canonical_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Account</label>
            <select name="provider_account_id">
                <option value="">All accounts</option>
                <option value="__none__" @selected(request('provider_account_id') === '__none__')>Unattributed</option>
                @foreach($filterOptions['accounts'] as $account)
                    <option value="{{ $account->id }}" @selected(request('provider_account_id') === $account->id)>{{ $account->label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Model</label>
            <select name="model">
                <option value="">All models</option>
                @foreach($filterOptions['models'] as $model)
                    <option value="{{ $model }}" @selected(request('model') === $model)>{{ $model }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Rows</label>
            <select name="per_page">
                @foreach([10, 25, 50, 100] as $size)
                    <option value="{{ $size }}" @selected((int) request('per_page', 50) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
        <div class="wide">
            <label>Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Model, project, or source event id">
        </div>
        <div>
            <button class="btn">Apply</button>
        </div>
    </form>
</div>

<div class="tabs">
    <a class="tab-btn {{ $activeTab === 'devices' ? 'active' : '' }}" href="{{ $tabQuery('devices') }}">Devices & Projects</a>
    <a class="tab-btn {{ $activeTab === 'accounts' ? 'active' : '' }}" href="{{ $tabQuery('accounts') }}">Accounts & Models</a>
    <a class="tab-btn {{ $activeTab === 'events' ? 'active' : '' }}" href="{{ $tabQuery('events') }}">Recent Events</a>
    <a class="tab-btn {{ $activeTab === 'all' ? 'active' : '' }}" href="{{ $tabQuery('all') }}">All Tables</a>
</div>

@if($activeTab === 'devices')
    @include('partials.dashboard-table', ['title' => 'By Device', 'rows' => $byDevice, 'tableKey' => 'device'])
    @include('partials.dashboard-table', ['title' => 'By Project', 'rows' => $byProject, 'tableKey' => 'project'])
@endif

@if($activeTab === 'accounts')
    @include('partials.dashboard-table', ['title' => 'By Provider Account', 'rows' => $byProviderAccount, 'tableKey' => 'account'])
    @include('partials.dashboard-table', ['title' => 'By Model', 'rows' => $byModel, 'tableKey' => 'model'])
@endif

@if($activeTab === 'events')
    <div class="card">
        <div class="table-meta">
            <h3 style="margin:0;">Recent Events</h3>
            <span class="muted">{{ number_format($events->total()) }} matching events</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Project</th>
                        <th>Device</th>
                        <th>Account</th>
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
                        <td>{{ $e->device_name ?? '—' }}</td>
                        <td>{{ $e->provider_account_name ?? 'Unattributed' }}</td>
                        <td style="font-size:12px;">{{ $e->provider_id }}</td>
                        <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;">{{ $e->model ?? '—' }}</td>
                        <td style="text-align:right;white-space:nowrap;font-size:13px;">
                            {{ number_format(max($e->input_tokens - $e->cached_input_tokens, 0) + $e->output_tokens + $e->cached_input_tokens + $e->cache_write_tokens + $e->cache_read_tokens + $e->reasoning_tokens + $e->tool_tokens + $e->unknown_tokens) }}
                        </td>
                        <td style="text-align:right;white-space:nowrap;font-size:13px;">
                            {{ $e->official_api_cost_usd !== null ? '$'.number_format((float) $e->official_api_cost_usd, 4) : '—' }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="muted">No synced usage matches these filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $events->onEachSide(1)->links('partials.pagination') }}
    </div>
@endif

@if($activeTab === 'all')
    @include('partials.dashboard-table', ['title' => 'By Device', 'rows' => $byDevice, 'tableKey' => 'device'])
    @include('partials.dashboard-table', ['title' => 'By Project', 'rows' => $byProject, 'tableKey' => 'project'])
    @include('partials.dashboard-table', ['title' => 'By Provider Account', 'rows' => $byProviderAccount, 'tableKey' => 'account'])
    @include('partials.dashboard-table', ['title' => 'By Model', 'rows' => $byModel, 'tableKey' => 'model'])
@endif
@endsection
