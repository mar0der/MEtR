@extends('layouts.app')

@section('title', 'Devices - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Devices</h1>
        <div class="muted">Synced desktop installations for this account.</div>
    </div>
    <a class="btn secondary" href="/devices">Reset</a>
</div>

<div class="card">
    <form method="GET" action="/devices" class="filters">
        <div class="wide">
            <label>Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, alias, platform, or UUID">
        </div>
        <div>
            <label>Platform</label>
            <select name="platform">
                <option value="">All platforms</option>
                @foreach($platforms as $platform)
                    <option value="{{ $platform }}" @selected(request('platform') === $platform)>{{ $platform }}</option>
                @endforeach
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
        <h3 style="margin:0;">Devices</h3>
        <span class="muted">{{ number_format($devices->total()) }} matching devices</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Alias</th><th>Platform</th><th>UUID</th><th>Last Seen</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($devices as $d)
                <tr>
                    <td>{{ $d->display_name }}</td>
                    <td>
                        <form method="POST" action="/devices/{{ $d->id }}/alias" style="margin:0;display:flex;gap:6px;">
                            @csrf
                            <input type="text" name="alias" value="{{ $d->alias ?? '' }}" placeholder="Add label..." style="min-height:28px;padding:0 6px;font-size:13px;width:140px;margin:0;">
                            <button type="submit" class="btn" style="padding:4px 10px;font-size:12px;">Save</button>
                        </form>
                    </td>
                    <td>{{ $d->platform }}</td>
                    <td class="muted">{{ $d->device_uuid }}</td>
                    <td>{{ $d->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        <form method="POST" action="/devices/{{ $d->id }}/delete" style="margin:0;" onsubmit="return confirm('Remove this device?');">
                            @csrf
                            <button type="submit" class="btn" style="padding:4px 10px;font-size:12px;background:#dc2626;border-color:#dc2626;color:#fff;">Remove</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="muted">No devices match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $devices->onEachSide(1)->links('partials.pagination') }}
</div>
@endsection
