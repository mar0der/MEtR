@extends('layouts.app')

@section('title', 'Admin Login - MEtR')

@section('content')
<div class="login-bg">
    <div class="login-card">
        <div class="logo" style="justify-content:center;margin-bottom:8px;">
            <span class="logo-mark">M</span>
        </div>
        <h1 style="text-align:center;font-size:20px;margin-bottom:4px;">Admin Panel</h1>
        <p class="muted subtitle" style="text-align:center;margin-bottom:24px;font-size:14px;">Restricted access.</p>

        @if($errors->any())
            <div style="color:#991b1b;background:var(--danger-soft);border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/admin/login">
            @csrf
            <label>Email</label>
            <input type="email" name="email" required autofocus placeholder="admin@example.com" value="{{ old('email') }}">

            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">

            <button class="btn" style="width:100%;margin-top:4px;padding:10px;font-size:15px;">Sign In</button>
        </form>
    </div>
</div>
@endsection
