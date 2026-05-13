@extends('layouts.app')

@section('title', 'Subscriptions - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Subscriptions</h1>
        <div class="muted">Synced fixed-price plans used for API break-even comparison.</div>
    </div>
    <a class="btn secondary" href="/subscriptions">Reset</a>
</div>

<div class="card">
    <form method="GET" action="/subscriptions" class="filters">
        <div class="wide">
            <label>Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Plan, currency, or notes">
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
            <label>Status</label>
            <select name="active">
                <option value="">All subscriptions</option>
                <option value="1" @selected(request('active') === '1')>Active</option>
                <option value="0" @selected(request('active') === '0')>Inactive</option>
            </select>
        </div>
        <div>
            <label>Rows</label>
            <select name="per_page">
                @foreach([10, 25, 50, 100] as $size)
                    <option value="{{ $size }}" @selected((int) request('per_page', 25) === $size)>{{ $size }}</option>
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
        <h3 style="margin:0;">Subscriptions</h3>
        <span class="muted">{{ number_format($subscriptions->total()) }} matching subscriptions</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Plan</th><th>Provider</th><th>Account</th><th>Price</th><th>Billing Day</th><th>Active</th></tr>
            </thead>
            <tbody>
                @forelse($subscriptions as $s)
                <tr>
                    <td>{{ $s->plan_name }}</td>
                    <td>{{ $s->provider?->display_name ?? $s->provider_id }}</td>
                    <td>{{ $s->providerAccount?->label ?? '—' }}</td>
                    <td>{{ $s->currency }} {{ number_format((float) $s->monthly_price, 2) }}</td>
                    <td>{{ $s->billing_anchor_day ?? '—' }}</td>
                    <td><span class="pill {{ $s->active ? 'success' : 'warning' }}">{{ $s->active ? 'Active' : 'Inactive' }}</span></td>
                </tr>
                @empty
                <tr><td colspan="6" class="muted">No subscriptions match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $subscriptions->onEachSide(1)->links('partials.pagination') }}
</div>
@endsection
