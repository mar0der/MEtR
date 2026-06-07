@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="card" style="max-width:640px; margin:0 auto;">
    <h2 style="margin-top:0;">Settings</h2>

    <div style="margin-bottom:24px;">
        <h3 style="font-size:15px; margin-bottom:8px;">Data Summary</h3>
        <p class="muted" style="margin:0 0 4px;">{{ number_format($eventCount) }} usage events stored</p>
        <p class="muted" style="margin:0;">{{ number_format($projectCount) }} projects stored</p>
    </div>

    <div style="border-top:1px solid var(--border); padding-top:20px;">
        <h3 style="font-size:15px; color:#dc2626; margin-bottom:8px;">Danger Zone</h3>
        <p class="muted" style="margin:0 0 14px; max-width:460px;">
            Permanently delete all usage events, projects, conversations, and sync history from the server.
            Your account, devices, subscriptions, and pricing data stay intact.
        </p>
        @if(auth()->user()->email === 'demo@metr.app' || auth()->user()->username === 'demo')
            <button type="button" class="btn" style="background:#9ca3af; cursor:not-allowed; opacity:0.7;" disabled>
                Clear All Server Data — Demo Account
            </button>
        @else
            <form method="POST" action="/settings/clear-data" onsubmit="return confirm('WARNING: This will permanently delete ALL usage data from the server. This cannot be undone. Continue?');">
                @csrf
                <button type="submit" class="btn" style="background:#dc2626; border-color:#dc2626; color:#fff;">
                    Clear All Server Data
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
