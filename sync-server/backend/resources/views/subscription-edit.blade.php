@extends('layouts.app')

@section('title', 'Edit Subscription - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Edit Subscription</h1>
        <div class="muted">Update plan details, dates, or status.</div>
    </div>
    <a class="btn secondary" href="/subscriptions">Back</a>
</div>

@if($errors->any())
    <div class="flash flash-error">
        {{ $errors->first() }}
    </div>
@endif

<div class="card">
    <form method="POST" action="/subscriptions/{{ $subscription->id }}" class="filters">
        @csrf
        @method('PUT')
        <div>
            <label>Provider</label>
            <select name="provider_id" required>
                @foreach($providers as $provider)
                    <option value="{{ $provider->id }}" @selected(old('provider_id', $subscription->provider_id) === $provider->id)>{{ $provider->display_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Account</label>
            <select name="provider_account_id">
                <option value="">—</option>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected(old('provider_account_id', $subscription->provider_account_id) === $account->id)>{{ $account->label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Plan name</label>
            <input type="text" name="plan_name" value="{{ old('plan_name', $subscription->plan_name) }}" required>
        </div>
        <div>
            <label>Price</label>
            <input type="number" step="0.01" min="0" name="monthly_price" value="{{ old('monthly_price', $subscription->monthly_price) }}" required>
        </div>
        <div>
            <label>Renews at</label>
            <input type="number" step="0.01" min="0" name="renewal_price" value="{{ old('renewal_price', $subscription->renewal_price) }}" placeholder="Normal price">
        </div>
        <div>
            <label>Currency</label>
            <input type="text" name="currency" value="{{ old('currency', $subscription->currency) }}" required>
        </div>
        <div>
            <label>Billing day</label>
            <input type="number" min="1" max="31" name="billing_anchor_day" value="{{ old('billing_anchor_day', $subscription->billing_anchor_day) }}">
        </div>
        <div>
            <label>Start</label>
            <input type="date" name="started_on" value="{{ old('started_on', $subscription->started_on?->format('Y-m-d')) }}">
        </div>
        <div>
            <label>End</label>
            <input type="date" name="ended_on" value="{{ old('ended_on', $subscription->ended_on?->format('Y-m-d')) }}">
        </div>
        <div>
            <label>Active</label>
            <select name="active">
                <option value="1" @selected(old('active', (string) (int) $subscription->active) === '1')>Active</option>
                <option value="0" @selected(old('active', (string) (int) $subscription->active) === '0')>Inactive</option>
            </select>
        </div>
        <div>
            <label>Auto-renew</label>
            <select name="autorenew">
                <option value="1" @selected(old('autorenew', (string) (int) $subscription->autorenew) === '1')>Yes</option>
                <option value="0" @selected(old('autorenew', (string) (int) $subscription->autorenew) === '0')>No</option>
            </select>
        </div>
        <div class="wide">
            <label>Notes</label>
            <input type="text" name="notes" value="{{ old('notes', $subscription->notes) }}">
        </div>
        <div>
            <label>&nbsp;</label>
            <button class="btn">Save</button>
        </div>
    </form>
</div>
@endsection
