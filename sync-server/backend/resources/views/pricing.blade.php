@extends('layouts.app')

@section('title', 'Pricing - MEtR Sync')

@section('content')
<h1>Model Prices</h1>
<div class="card">
    <table>
        <thead>
            <tr><th>Provider</th><th>Model</th><th>Input</th><th>Output</th><th>Cached</th><th>Effective From</th></tr>
        </thead>
        <tbody>
            @forelse($prices as $p)
            <tr>
                <td>{{ $p->provider->display_name }}</td>
                <td>{{ $p->model }}</td>
                <td>{{ $p->input_per_1m !== null ? '$'.number_format((float) $p->input_per_1m, 4) : '—' }}</td>
                <td>{{ $p->output_per_1m !== null ? '$'.number_format((float) $p->output_per_1m, 4) : '—' }}</td>
                <td>{{ $p->cached_input_per_1m !== null ? '$'.number_format((float) $p->cached_input_per_1m, 4) : '—' }}</td>
                <td>{{ $p->effective_from->format('Y-m-d') }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="muted">No prices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
