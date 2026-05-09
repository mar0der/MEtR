@extends('layouts.app')

@section('title', 'Login - MEtR Sync')

@section('content')
<div class="card" style="max-width:420px;margin:80px auto;">
    <h1>MEtR Sync</h1>
    <p class="muted">Sign in to manage your sync backend.</p>
    @if($errors->any())
        <div style="color:#991b1b;background:#fee2e2;border:1px solid #fecaca;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:14px;">
            {{ $errors->first() }}
        </div>
    @endif
    <form method="POST" action="/login">
        @csrf
        <label>Username or Email</label>
        <input type="text" name="login" required autofocus>
        <label>Password</label>
        <input type="password" name="password" required>
        <button class="btn" style="width:100%;">Sign In</button>
    </form>
</div>
@endsection
