@extends('layouts.app')

@section('title', 'Admin Dashboard - MEtR')

@section('content')
<style>
    .admin-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
    @media (max-width: 768px) { .admin-grid { grid-template-columns: repeat(2, 1fr); } }
    .admin-stat { text-align: center; padding: 20px; }
    .admin-stat .num { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
    .admin-stat .lbl { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-top: 4px; }
    .admin-section { margin-bottom: 32px; }
    .admin-section h2 { font-size: 18px; font-weight: 600; margin: 0 0 14px; }
    .token-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    @media (max-width: 768px) { .token-grid { grid-template-columns: repeat(2, 1fr); } }
    .token-box { padding: 14px; border-radius: 10px; background: var(--bg); border: 1px solid var(--border); text-align: center; }
    .token-box .val { font-size: 18px; font-weight: 700; }
    .token-box .lbl { font-size: 11px; color: var(--muted); margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
    .logout-btn { float: right; }
</style>

<div class="page-heading">
    <h1>Admin Dashboard</h1>
    <form method="POST" action="/admin/logout" style="margin:0;">@csrf<button class="btn secondary" style="padding:6px 14px;font-size:13px;">Logout</button></form>
</div>

<div class="admin-grid">
    <div class="card admin-stat">
        <div class="num">{{ number_format($stats->user_count) }}</div>
        <div class="lbl">Users</div>
    </div>
    <div class="card admin-stat">
        <div class="num">{{ number_format($stats->event_count) }}</div>
        <div class="lbl">Total Events</div>
    </div>
    <div class="card admin-stat">
        <div class="num">{{ number_format($dbSize, 2) }} MB</div>
        <div class="lbl">Database Size</div>
    </div>
    <div class="card admin-stat">
        <div class="num">{{ number_format(($stats->input_tokens + $stats->output_tokens + $stats->cached_input_tokens + $stats->cache_write_tokens + $stats->cache_read_tokens + $stats->reasoning_tokens + $stats->tool_tokens + $stats->unknown_tokens)) }}</div>
        <div class="lbl">Total Tokens</div>
    </div>
</div>

<div class="admin-section">
    <h2>Token Breakdown</h2>
    <div class="token-grid">
        <div class="token-box">
            <div class="val">{{ number_format($stats->input_tokens) }}</div>
            <div class="lbl">Input</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->output_tokens) }}</div>
            <div class="lbl">Output</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->cached_input_tokens) }}</div>
            <div class="lbl">Cached Input</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->cache_write_tokens) }}</div>
            <div class="lbl">Cache Write</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->cache_read_tokens) }}</div>
            <div class="lbl">Cache Read</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->reasoning_tokens) }}</div>
            <div class="lbl">Reasoning</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->tool_tokens) }}</div>
            <div class="lbl">Tool</div>
        </div>
        <div class="token-box">
            <div class="val">{{ number_format($stats->unknown_tokens) }}</div>
            <div class="lbl">Unknown</div>
        </div>
    </div>
</div>

<div class="admin-section">
    <h2>Users</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Username</th>
                    <th style="text-align:right">Events</th>
                    <th style="text-align:right">Tokens</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td>{{ $user['id'] }}</td>
                    <td>{{ $user['name'] }}</td>
                    <td>{{ $user['email'] ?? '-' }}</td>
                    <td>{{ $user['username'] }}</td>
                    <td style="text-align:right">{{ number_format($user['event_count']) }}</td>
                    <td style="text-align:right">{{ number_format($user['total_tokens']) }}</td>
                    <td>{{ $user['created_at']->format('M j, Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
