@extends('layouts.app')

@section('title', 'Edit Provider Account - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Edit Provider Account</h1>
        <div class="muted">Update account label, type, or status.</div>
    </div>
    <a class="btn secondary" href="/provider-accounts">Back</a>
</div>

@if($errors->any())
    <div class="flash flash-error">
        {{ $errors->first() }}
    </div>
@endif

<div class="card">
    <form method="POST" action="/provider-accounts/{{ $account->id }}" class="filters">
        @csrf
        @method('PUT')
        <div>
            <label>Provider</label>
            <select name="provider_id" required>
                @foreach($providers as $provider)
                    <option value="{{ $provider->id }}" @selected(old('provider_id', $account->provider_id) === $provider->id)>{{ $provider->display_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Label</label>
            <input type="text" name="label" value="{{ old('label', $account->label) }}" required>
        </div>
        <div>
            <label>Type</label>
            <input type="text" name="account_type" value="{{ old('account_type', $account->account_type) }}">
        </div>
        <div>
            <label>Active</label>
            <select name="active">
                <option value="1" @selected(old('active', (string) (int) $account->active) === '1')>Active</option>
                <option value="0" @selected(old('active', (string) (int) $account->active) === '0')>Inactive</option>
            </select>
        </div>
        <div class="wide">
            <label>Notes</label>
            <input type="text" name="notes" value="{{ old('notes', $account->notes) }}">
        </div>
        <div>
            <label>&nbsp;</label>
            <button class="btn">Save</button>
        </div>
    </form>
</div>
@endsection
