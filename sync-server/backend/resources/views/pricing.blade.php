@extends('layouts.app')

@section('title', 'Pricing - MEtR Sync')

@section('content')
<h1>Model Prices</h1>
<div class="card">
    <table>
        <thead>
            <tr><th>Provider</th><th>Model</th><th>Input</th><th>Output</th><th>Effective From</th></tr>
        </thead>
        <tbody>
            @forelse($prices as $p)
            <tr>
                <td>{{ $p->provider->display_name }}</td>
                <td>{{ $p->model }}</td>
                <td>{{ $p->input_per_1m ?? '—' }}</td>
                <td>{{ $p->output_per_1m ?? '—' }}</td>
                <td>{{ $p->effective_from->format('Y-m-d') }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="muted">No prices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
