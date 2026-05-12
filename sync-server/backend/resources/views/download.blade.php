@extends('layouts.app')

@section('title', 'Download MEtR')

@section('content')
<div style="text-align:center; padding:40px 20px;">
    <h1 style="font-size:32px; margin-bottom:8px;">MEtR</h1>
    <p class="muted" style="font-size:16px; max-width:500px; margin:0 auto 32px;">
        Local LLM usage tracker. Track subscriptions, token usage, and API-equivalent costs across Claude, Kimi, OpenAI, Ollama, and LM Studio.
    </p>

    @if($release)
        <div class="muted" style="margin-bottom:24px;">
            Latest release: <strong>{{ $release->version }}</strong> &middot; {{ $release->released_at->format('M j, Y') }}
        </div>
    @endif

    <div class="grid two-col" style="max-width:600px; margin:0 auto;">
        <div class="card" style="text-align:center;">
            <div style="font-size:36px; margin-bottom:8px;">🍎</div>
            <h3 style="margin:0 0 6px;">macOS</h3>
            <p class="muted" style="font-size:13px; margin:0 0 14px;">Apple Silicon (M1/M2/M3/M4)</p>
            @if(isset($assets['darwin-aarch64']))
                <a class="btn" href="/updates/{{ $assets['darwin-aarch64']->filename }}">
                    Download DMG
                </a>
            @else
                <span class="muted" style="font-size:13px;">Coming soon</span>
            @endif
        </div>

        <div class="card" style="text-align:center;">
            <div style="font-size:36px; margin-bottom:8px;">🪟</div>
            <h3 style="margin:0 0 6px;">Windows</h3>
            <p class="muted" style="font-size:13px; margin:0 0 14px;">Windows 10/11 (x64)</p>
            @if(isset($assets['windows-x86_64']))
                <a class="btn" href="/updates/{{ $assets['windows-x86_64']->filename }}">
                    Download MSI
                </a>
            @else
                <span class="muted" style="font-size:13px;">Coming soon</span>
            @endif
        </div>
    </div>

    <div class="card" style="max-width:600px; margin:24px auto 0; text-align:left;">
        <h3 style="margin-top:0;">What MEtR does</h3>
        <ul class="muted" style="padding-left:18px; line-height:1.8;">
            <li>Indexes local LLM conversation logs from Claude, Kimi, OpenAI, Ollama, and LM Studio</li>
            <li>Calculates API-equivalent cost using live pricing data</li>
            <li>Tracks subscription fees vs. what API usage would cost</li>
            <li>Syncs across devices via this server (optional)</li>
            <li>All data stays local unless you explicitly sync</li>
        </ul>
    </div>
</div>
@endsection
