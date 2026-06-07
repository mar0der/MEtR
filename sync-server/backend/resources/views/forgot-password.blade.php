@extends('layouts.app')

@section('title', 'Reset Password - MEtR')

@section('content')
<div class="login-bg">
    <div class="login-card">
        <div class="logo" style="justify-content:center;margin-bottom:8px;">
            <span class="logo-mark">M</span>
        </div>
        <h1 style="text-align:center;font-size:20px;margin-bottom:4px;">Forgot password?</h1>
        <p class="muted subtitle" style="text-align:center;margin-bottom:24px;font-size:14px;">Enter your email and we'll send you a reset link.</p>

        @if(session('status'))
            <div style="color:#064e3b;background:var(--success-soft);border:1px solid #bbf7d0;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div style="color:#991b1b;background:var(--danger-soft);border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/forgot-password">
            @csrf
            <label>Email</label>
            <input type="email" name="email" required autofocus placeholder="you@example.com" value="{{ old('email') }}">

            <button class="btn" style="width:100%;margin-top:4px;padding:10px;font-size:15px;">Send Reset Link</button>
        </form>

        <p style="text-align:center;margin-top:18px;font-size:13px;color:var(--muted);">
            Remember your password? <a href="/login" style="color:var(--accent);text-decoration:none;font-weight:600;">Sign in</a>
        </p>
    </div>
</div>
@endsection
