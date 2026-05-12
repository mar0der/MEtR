@extends('layouts.app')

@section('title', 'Pricing - MEtR Sync')

@section('content')
<h1>Model Prices</h1>

<div class="card">
    <p class="muted">Showing {{ $usedCount }} models with usage events. {{ $unusedCount }} additional models available for matching.</p>
    <table>
        <thead>
            <tr><th>Provider</th><th>Model</th><th>Input</th><th>Output</th><th>Cached</th></tr>
        </thead>
        <tbody>
            @php $showingUsed = true; @endphp
            @forelse($prices as $p)
                @if($showingUsed && $loop->index >= $usedCount)
                    @php $showingUsed = false; @endphp
                    <tr><td colspan="5" class="muted" style="text-align:center; font-weight:600;">— Models not yet seen in logs —</td></tr>
                @endif
            <tr>
                <td>{{ $p->provider->display_name }}</td>
                <td>
                    {{ $p->model }}
                    @if(!empty(json_decode($p->aliases_json ?? '[]')))
                        <div class="muted" style="font-size:12px;">aliases: {{ implode(', ', json_decode($p->aliases_json)) }}</div>
                    @endif
                </td>
                <td>{{ $p->input_per_1m !== null ? '$'.number_format((float) $p->input_per_1m, 4) : '—' }}</td>
                <td>{{ $p->output_per_1m !== null ? '$'.number_format((float) $p->output_per_1m, 4) : '—' }}</td>
                <td>{{ $p->cached_input_per_1m !== null ? '$'.number_format((float) $p->cached_input_per_1m, 4) : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="muted">No prices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
