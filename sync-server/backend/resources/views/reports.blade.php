@extends('layouts.app')

@section('title', 'Reports - MEtR Sync')

@section('content')
@php
    $baseQuery = request()->except(['preset', 'page']);
    $presetLink = fn (string $preset) => url('/reports').'?'.http_build_query(array_merge($baseQuery, ['preset' => $preset]));
    $metricLink = fn (string $nextMetric) => url('/reports').'?'.http_build_query(array_merge(request()->query(), ['metric' => $nextMetric]));
    $formatValue = fn (float|int $value) => $metric === 'cost' ? '$'.number_format((float) $value, 2) : number_format((int) $value);
@endphp

<div class="page-heading">
    <div>
        <h1>Reports</h1>
        <div class="muted">Daily usage trends by cost or token volume.</div>
    </div>
    <a class="btn secondary" href="/reports">Reset</a>
</div>

<div class="range-presets">
    @foreach($presets as $key => $label)
        <a class="btn secondary {{ $dateRange['preset'] === $key ? 'active' : '' }}" href="{{ $presetLink($key) }}">{{ $label }}</a>
    @endforeach
</div>

<div class="grid stats-grid">
    <div class="card stat stat-accent">
        <div class="value" style="color:var(--accent);">${{ number_format((float) $summary['cost'], 2) }}</div>
        <div class="label">API Equivalent Cost</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['events']) }}</div>
        <div class="label">Events</div>
    </div>
    <div class="card stat stat-success">
        <div class="value" style="color:var(--success);">{{ number_format($summary['total_tokens']) }}</div>
        <div class="label">Total Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['cached']) }}</div>
        <div class="label">Cached Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['input']) }}</div>
        <div class="label">Real Input Tokens</div>
    </div>
    <div class="card stat">
        <div class="value">{{ number_format($summary['output']) }}</div>
        <div class="label">Output Tokens</div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">Filters</h3>
    <form method="GET" action="/reports" class="filters">
        <div>
            <label>Range</label>
            <select name="preset">
                @foreach($presets as $key => $label)
                    <option value="{{ $key }}" @selected($dateRange['preset'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>From</label>
            <input type="date" name="from" value="{{ $dateRange['from']->toDateString() }}">
        </div>
        <div>
            <label>To</label>
            <input type="date" name="to" value="{{ $dateRange['to']->toDateString() }}">
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
            <label>Metric</label>
            <select name="metric">
                <option value="cost" @selected($metric === 'cost')>Cost</option>
                <option value="tokens" @selected($metric === 'tokens')>Tokens</option>
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

<div class="card chart-card">
    <div class="table-meta">
        <div>
            <h3 style="margin:0;">Daily {{ $metric === 'cost' ? 'Cost' : 'Token' }} Report</h3>
            <span class="muted">{{ $dateRange['from']->format('M j, Y') }} through {{ $dateRange['to']->format('M j, Y') }}</span>
        </div>
        <div class="mode-toggle">
            <a class="btn secondary {{ $metric === 'cost' ? 'active' : '' }}" href="{{ $metricLink('cost') }}">Cost</a>
            <a class="btn secondary {{ $metric === 'tokens' ? 'active' : '' }}" href="{{ $metricLink('tokens') }}">Tokens</a>
        </div>
    </div>

    <div class="chart-legend">
        <span class="legend-item"><span class="legend-swatch segment-cached"></span> Cached</span>
        <span class="legend-item"><span class="legend-swatch segment-input"></span> Input</span>
        <span class="legend-item"><span class="legend-swatch segment-output"></span> Output</span>
        <span class="legend-item"><span class="legend-swatch segment-other"></span> Other</span>
    </div>

    <div class="report-chart">
        @forelse($rows as $row)
            @php
                $barWidth = max(2, min(100, ((float) $row['value'] / $maxValue) * 100));
            @endphp
            <div class="report-row">
                <div class="report-date">{{ $row['label'] }}</div>
                <div class="report-bar-track" title="{{ $formatValue($row['value']) }} across {{ number_format($row['events']) }} event(s)">
                    <div class="report-bar-fill" style="width: {{ $barWidth }}%;">
                        @foreach($row['segments'] as $segment)
                            @if($segment['share'] > 0)
                                <span
                                    class="report-segment segment-{{ $segment['key'] }}"
                                    style="width: {{ max(1, $segment['share'] * 100) }}%;"
                                    title="{{ $segment['label'] }}: {{ $metric === 'cost' ? '$'.number_format((float) $segment['value'], 2) : number_format((int) $segment['value']) }} ({{ number_format($segment['share'] * 100, 1) }}%)"
                                ></span>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="report-value">
                    {{ $formatValue($row['value']) }}
                    <small>{{ number_format($row['events']) }} event(s)</small>
                </div>
            </div>
        @empty
            <div class="muted" style="padding:24px 0;">No synced usage matches these report filters.</div>
        @endforelse
    </div>
</div>

<div class="card">
    <div class="table-meta">
        <h3 style="margin:0;">Daily Totals</h3>
        <span class="muted">{{ number_format($rows->count()) }} day(s)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th style="text-align:right;">Events</th>
                    <th style="text-align:right;">Cost</th>
                    <th style="text-align:right;">Cached</th>
                    <th style="text-align:right;">Input</th>
                    <th style="text-align:right;">Output</th>
                    <th style="text-align:right;">Total Tokens</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php
                        $segments = collect($row['segments'])->keyBy('key');
                    @endphp
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($row['bucket'])->format('M j, Y') }}</td>
                        <td style="text-align:right;">{{ number_format($row['events']) }}</td>
                        <td style="text-align:right;">${{ number_format((float) $row['cost'], 2) }}</td>
                        <td style="text-align:right;">{{ number_format($segments['cached']['tokens'] ?? 0) }}</td>
                        <td style="text-align:right;">{{ number_format($segments['input']['tokens'] ?? 0) }}</td>
                        <td style="text-align:right;">{{ number_format($segments['output']['tokens'] ?? 0) }}</td>
                        <td style="text-align:right;">{{ number_format($row['total_tokens']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No report rows found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
