@extends('layouts.app')

@section('title', 'Login — MEtR Dashboard')
@section('description', 'Sign in to your MEtR dashboard to view LLM usage reports, manage devices, and track subscription costs.')

@section('content')
<div class="login-bg">
    <div class="login-card">
        <div class="logo" style="justify-content:center;margin-bottom:8px;">
            <span class="logo-mark">M</span>
        </div>
        <h1 style="text-align:center;font-size:20px;margin-bottom:4px;">MEtR Sync</h1>
        <p class="muted subtitle" style="text-align:center;margin-bottom:24px;font-size:14px;">Sign in to manage your sync backend.</p>

        @if($errors->any())
            <div style="color:#991b1b;background:var(--danger-soft);border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/login">
            @csrf
            <label>Username or Email</label>
            <input type="text" name="login" required autofocus placeholder="you@example.com" value="{{ old('login') }}">

            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">

            <label class="remember-row">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>Remember me</span>
            </label>

            <button class="btn" style="width:100%;margin-top:4px;padding:10px;font-size:15px;">Sign In</button>
        </form>

        <div style="display:flex;justify-content:space-between;margin-top:16px;font-size:13px;">
            <a href="/forgot-password" style="color:var(--muted);text-decoration:none;">Forgot password?</a>
            <a href="/register" style="color:var(--accent);text-decoration:none;font-weight:600;">Create account</a>
        </div>
    </div>
</div>
@endsection
