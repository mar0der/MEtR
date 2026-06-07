@extends('layouts.app')

@section('title', 'Reset Password - MEtR')

@section('content')
<div class="login-bg">
    <div class="login-card">
        <div class="logo" style="justify-content:center;margin-bottom:8px;">
            <span class="logo-mark">M</span>
        </div>
        <h1 style="text-align:center;font-size:20px;margin-bottom:4px;">Set new password</h1>
        <p class="muted subtitle" style="text-align:center;margin-bottom:24px;font-size:14px;">Choose a strong password for your account.</p>

        @if($errors->any())
            <div style="color:#991b1b;background:var(--danger-soft);border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/reset-password">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label>Email</label>
            <input type="email" name="email" required placeholder="you@example.com" value="{{ $email ?? old('email') }}">

            <label>New Password</label>
            <input type="password" name="password" required placeholder="Min 8 characters">

            <label>Confirm New Password</label>
            <input type="password" name="password_confirmation" required placeholder="Repeat password">

            <button class="btn" style="width:100%;margin-top:4px;padding:10px;font-size:15px;">Reset Password</button>
        </form>
    </div>
</div>
@endsection
