@extends('layouts.app')

@section('title', 'Devices - MEtR Sync')

@section('content')
<h1>Devices</h1>
<div class="card">
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
                        <input type="text" name="alias" value="{{ $d->alias ?? '' }}" placeholder="Add label..." style="min-height:28px;padding:0 6px;font-size:13px;width:140px;">
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
            <tr><td colspan="6" class="muted">No devices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
