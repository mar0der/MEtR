@extends('layouts.app')

@section('title', 'Projects - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Projects</h1>
        <div class="muted">Canonical synced project identities and their known roots.</div>
    </div>
    <a class="btn secondary" href="/projects">Reset</a>
</div>

<div class="card">
    <form method="GET" action="/projects" class="filters">
        <div class="wide">
            <label>Search</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Project name or slug">
        </div>
        <div>
            <label>Status</label>
            <select name="active">
                <option value="">All projects</option>
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
        <h3 style="margin:0;">Projects</h3>
        <span class="muted">{{ number_format($projects->total()) }} matching projects</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Slug</th><th>Roots</th><th>Last Event</th><th>Active</th></tr>
            </thead>
            <tbody>
                @forelse($projects as $p)
                <tr>
                    <td>{{ $p->manual_name ?? $p->canonical_name }}</td>
                    <td class="muted">{{ $p->slug }}</td>
                    <td>{{ number_format($p->project_roots_count) }}</td>
                    <td>{{ $p->usage_events_max_timestamp ? \Carbon\Carbon::parse($p->usage_events_max_timestamp)->diffForHumans() : '—' }}</td>
                    <td><span class="pill {{ $p->active ? 'success' : 'warning' }}">{{ $p->active ? 'Active' : 'Inactive' }}</span></td>
                </tr>
                @empty
                <tr><td colspan="5" class="muted">No projects match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $projects->onEachSide(1)->links('partials.pagination') }}
</div>
@endsection
