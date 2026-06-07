@extends('layouts.app')

@section('title', 'Create Account — MEtR')
@section('description', 'Create a free MEtR account to sync your LLM usage data across devices and access the web dashboard.')

@section('content')
<div class="login-bg">
    <div class="login-card">
        <div class="logo" style="justify-content:center;margin-bottom:8px;">
            <span class="logo-mark">M</span>
        </div>
        <h1 style="text-align:center;font-size:20px;margin-bottom:4px;">Create your account</h1>
        <p class="muted subtitle" style="text-align:center;margin-bottom:24px;font-size:14px;">Start tracking your LLM usage across all your devices.</p>

        @if($errors->any())
            <div style="color:#991b1b;background:var(--danger-soft);border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/register">
            @csrf
            <label>Full Name</label>
            <input type="text" name="name" required autofocus placeholder="Jane Doe" value="{{ old('name') }}">

            <label>Username</label>
            <input type="text" name="username" required placeholder="janedoe" value="{{ old('username') }}">

            <label>Email <span class="muted">(optional)</span></label>
            <input type="email" name="email" placeholder="you@example.com" value="{{ old('email') }}">

            <label>Password</label>
            <input type="password" name="password" required placeholder="Min 8 characters">

            <label>Confirm Password</label>
            <input type="password" name="password_confirmation" required placeholder="Repeat password">

            <button class="btn" style="width:100%;margin-top:4px;padding:10px;font-size:15px;">Create Account</button>
        </form>

        <p style="text-align:center;margin-top:18px;font-size:13px;color:var(--muted);">
            Already have an account? <a href="/login" style="color:var(--accent);text-decoration:none;font-weight:600;">Sign in</a>
        </p>
    </div>
</div>
@endsection
