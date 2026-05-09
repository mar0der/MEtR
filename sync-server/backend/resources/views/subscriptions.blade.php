@extends('layouts.app')

@section('title', 'Subscriptions - MEtR Sync')

@section('content')
<h1>Subscriptions</h1>
<div class="card">
    <table>
        <thead>
            <tr><th>Plan</th><th>Account</th><th>Price</th><th>Currency</th><th>Active</th></tr>
        </thead>
        <tbody>
            @forelse($subscriptions as $s)
            <tr>
                <td>{{ $s->plan_name }}</td>
                <td>{{ $s->providerAccount?->label ?? '—' }}</td>
                <td>{{ $s->monthly_price }}</td>
                <td>{{ $s->currency }}</td>
                <td>{{ $s->active ? 'Yes' : 'No' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="muted">No subscriptions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
