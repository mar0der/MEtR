@extends('layouts.app')

@section('title', 'Pricing - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Model Prices</h1>
        <div class="muted">{{ number_format($usedCount) }} models match synced usage. {{ number_format($unusedCount) }} current models are available but unused.</div>
    </div>
    <a class="btn secondary" href="/pricing">Reset</a>
</div>

<div class="card">
    <form method="GET" action="/pricing" class="filters">
        <div class="wide">
            <label>Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Model, alias, or source URL">
        </div>
        <div>
            <label>Provider</label>
            <select name="provider_id">
                <option value="">All providers</option>
                @foreach($providers as $provider)
                    <option value="{{ $provider->id }}" @selected(request('provider_id') === $provider->id)>{{ $provider->display_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Usage</label>
            <select name="usage">
                <option value="">Used first</option>
                <option value="used" @selected(request('usage') === 'used')>Used only</option>
                <option value="unused" @selected(request('usage') === 'unused')>Unused only</option>
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
        <div>
            <button class="btn">Apply</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-meta">
        <h3 style="margin:0;">Prices</h3>
        <span class="muted">{{ number_format($prices->total()) }} matching prices</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Provider</th><th>Model</th><th>Status</th><th>Input</th><th>Output</th><th>Cached</th><th>Cache Write</th><th>Cache Read</th></tr>
            </thead>
            <tbody>
                @forelse($prices as $p)
                @php $aliases = json_decode($p->aliases_json ?? '[]', true) ?: []; @endphp
                <tr>
                    <td>{{ $p->provider->display_name }}</td>
                    <td>
                        {{ $p->model }}
                        @if(!empty($aliases))
                            <div class="muted" style="font-size:12px;">aliases: {{ implode(', ', $aliases) }}</div>
                        @endif
                    </td>
                    <td><span class="pill {{ in_array($p->id, $usedPriceIds, true) ? 'success' : '' }}">{{ in_array($p->id, $usedPriceIds, true) ? 'Used' : 'Unused' }}</span></td>
                    <td>{{ $p->input_per_1m !== null ? '$'.number_format((float) $p->input_per_1m, 4) : '—' }}</td>
                    <td>{{ $p->output_per_1m !== null ? '$'.number_format((float) $p->output_per_1m, 4) : '—' }}</td>
                    <td>{{ $p->cached_input_per_1m !== null ? '$'.number_format((float) $p->cached_input_per_1m, 4) : '—' }}</td>
                    <td>{{ $p->cache_write_per_1m !== null ? '$'.number_format((float) $p->cache_write_per_1m, 4) : '—' }}</td>
                    <td>{{ $p->cache_read_per_1m !== null ? '$'.number_format((float) $p->cache_read_per_1m, 4) : '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="muted">No prices match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $prices->onEachSide(1)->links('partials.pagination') }}
</div>
@endsection
