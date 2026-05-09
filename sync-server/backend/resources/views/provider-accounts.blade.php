@extends('layouts.app')

@section('title', 'Provider Accounts - MEtR Sync')

@section('content')
<h1>Provider Accounts</h1>
<div class="card">
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
                <td>{{ $a->active ? 'Yes' : 'No' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="muted">No accounts yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
