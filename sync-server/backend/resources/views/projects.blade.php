@extends('layouts.app')

@section('title', 'Projects - MEtR Sync')

@section('content')
<h1>Projects</h1>
<div class="card">
    <table>
        <thead>
            <tr><th>Name</th><th>Roots</th><th>Active</th></tr>
        </thead>
        <tbody>
            @forelse($projects as $p)
            <tr>
                <td>{{ $p->manual_name ?? $p->canonical_name }}</td>
                <td>{{ $p->project_roots_count }}</td>
                <td>{{ $p->active ? 'Yes' : 'No' }}</td>
            </tr>
            @empty
            <tr><td colspan="3" class="muted">No projects yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
