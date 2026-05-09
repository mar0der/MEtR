@extends('layouts.app')

@section('title', 'Devices - MEtR Sync')

@section('content')
<h1>Devices</h1>
<div class="card">
    <table>
        <thead>
            <tr><th>Name</th><th>Platform</th><th>UUID</th><th>Last Seen</th></tr>
        </thead>
        <tbody>
            @forelse($devices as $d)
            <tr>
                <td>{{ $d->display_name }}</td>
                <td>{{ $d->platform }}</td>
                <td class="muted">{{ $d->device_uuid }}</td>
                <td>{{ $d->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="muted">No devices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
