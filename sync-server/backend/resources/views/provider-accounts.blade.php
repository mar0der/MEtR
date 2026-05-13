@extends('layouts.app')

@section('title', 'Provider Accounts - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Provider Accounts</h1>
        <div class="muted">Account labels used for attribution and subscription comparison.</div>
    </div>
    <a class="btn secondary" href="/provider-accounts">Reset</a>
</div>

<div class="card">
    <form method="GET" action="/provider-accounts" class="filters">
        <div class="wide">
            <label>Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Label, type, or notes">
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
                <option value="">All accounts</option>
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
        <h3 style="margin:0;">Accounts</h3>
        <span class="muted">{{ number_format($accounts->total()) }} matching accounts</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Label</th><th>Provider</th><th>Type</th><th>Active</th></tr>
            </thead>
            <tbody>
                @forelse($accounts as $a)
                <tr>
                    <td>{{ $a->label }}</td>
                    <td>{{ $a->provider->display_name }}</td>
                    <td>{{ $a->account_type }}</td>
                    <td><span class="pill {{ $a->active ? 'success' : 'warning' }}">{{ $a->active ? 'Active' : 'Inactive' }}</span></td>
                </tr>
                @empty
                <tr><td colspan="4" class="muted">No accounts match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $accounts->onEachSide(1)->links('partials.pagination') }}
</div>
@endsection
