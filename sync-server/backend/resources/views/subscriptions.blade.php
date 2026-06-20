@extends('layouts.app')

@section('title', 'Subscriptions - MEtR Sync')

@section('content')
<div class="page-heading">
    <div>
        <h1>Subscriptions</h1>
        <div class="muted">Fixed-price plans used for cost reporting and break-even comparison.</div>
    </div>
    <a class="btn secondary" href="/subscriptions">Reset</a>
</div>

@if($errors->any())
    <div class="flash flash-error">
        {{ $errors->first() }}
    </div>
@endif

<div class="tabs">
    <a class="tab-btn {{ $tab === 'accounts' ? 'active' : '' }}" href="/subscriptions?tab=accounts">Accounts</a>
    <a class="tab-btn {{ $tab === 'instances' ? 'active' : '' }}" href="/subscriptions?tab=instances">Instances</a>
</div>

@if($tab === 'accounts')
    <div class="card">
        <div class="table-meta">
            <h3 style="margin:0;">Subscription accounts</h3>
            <span class="muted">{{ number_format(count($subscriptionGroups)) }} account(s)</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Provider</th>
                        <th style="text-align:right;">Instances</th>
                        <th style="text-align:right;">Total Paid</th>
                        <th style="text-align:right;">Current Price</th>
                        <th style="text-align:right;">Renews At</th>
                        <th>Current End</th>
                        <th>Auto-renew</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscriptionGroups as $group)
                        <tr>
                            <td><strong>{{ $group['plan_name'] }}</strong></td>
                            <td>{{ $group['provider']?->display_name ?? $group['provider_id'] }}</td>
                            <td style="text-align:right;">{{ number_format($group['instance_count']) }}</td>
                            <td style="text-align:right;">${{ number_format($group['total_paid'], 2) }}</td>
                            <td style="text-align:right;">${{ number_format($group['current_price'], 2) }}</td>
                            <td style="text-align:right;">${{ number_format($group['renews_at_price'], 2) }}</td>
                            <td>{{ $group['current_end']?->format('Y-m-d') ?? '—' }}</td>
                            <td>{{ $group['autorenew'] ? 'Yes' : 'No' }}</td>
                            <td><span class="pill {{ $group['active'] ? 'success' : 'warning' }}">{{ $group['active'] ? 'Active' : 'Inactive' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="muted">No subscriptions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="card">
        <h3 style="margin-top:0;">Add subscription</h3>
        <form method="POST" action="/subscriptions" class="filters">
            @csrf
            <div>
                <label>Provider</label>
                <select name="provider_id" required>
                    <option value="">Choose provider</option>
                    @foreach($providers as $provider)
                        <option value="{{ $provider->id }}" @selected(old('provider_id') === $provider->id)>{{ $provider->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Account</label>
                <select name="provider_account_id">
                    <option value="">—</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected(old('provider_account_id') === $account->id)>{{ $account->label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Plan name</label>
                <input type="text" name="plan_name" value="{{ old('plan_name') }}" placeholder="e.g. ChatGPT Plus" required>
            </div>
            <div>
                <label>Price</label>
                <input type="number" step="0.01" min="0" name="monthly_price" value="{{ old('monthly_price') }}" placeholder="0.00" required>
            </div>
            <div>
                <label>Renews at</label>
                <input type="number" step="0.01" min="0" name="renewal_price" value="{{ old('renewal_price') }}" placeholder="Normal price">
            </div>
            <div>
                <label>Currency</label>
                <input type="text" name="currency" value="{{ old('currency', 'USD') }}" placeholder="USD" required>
            </div>
            <div>
                <label>Billing day</label>
                <input type="number" min="1" max="31" name="billing_anchor_day" value="{{ old('billing_anchor_day') }}" placeholder="1–31">
            </div>
            <div>
                <label>Start</label>
                <input type="date" name="started_on" value="{{ old('started_on') }}">
            </div>
            <div>
                <label>End</label>
                <input type="date" name="ended_on" value="{{ old('ended_on') }}">
            </div>
            <div>
                <label>Active</label>
                <select name="active">
                    <option value="1" @selected(old('active', '1') === '1')>Active</option>
                    <option value="0" @selected(old('active') === '0')>Inactive</option>
                </select>
            </div>
            <div>
                <label>Auto-renew</label>
                <select name="autorenew">
                    <option value="1" @selected(old('autorenew') === '1')>Yes</option>
                    <option value="0" @selected(old('autorenew', '0') === '0')>No</option>
                </select>
            </div>
            <div class="wide">
                <label>Notes</label>
                <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Optional notes">
            </div>
            <div>
                <label>&nbsp;</label>
                <button class="btn">Add</button>
            </div>
        </form>
    </div>

    <div class="card">
        <form method="GET" action="/subscriptions" class="filters">
            <input type="hidden" name="tab" value="instances">
            <div class="wide">
                <label>Search</label>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Plan, currency, or notes">
            </div>
            <div>
                <label>Provider</label>
                <select name="provider_id">
                    <option value="">All providers</option>
                    @foreach($providers as $provider)
                        <option value="{{ $provider->id }}" @selected(request('provider_id') === $provider->id)>{{ $provider->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Plan</label>
                <select name="plan_name">
                    <option value="">All plans</option>
                    @foreach($plans as $plan)
                        <option value="{{ $plan }}" @selected(request('plan_name') === $plan)>{{ $plan }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="active">
                    <option value="">All subscriptions</option>
                    <option value="1" @selected(request('active') === '1')>Active</option>
                    <option value="0" @selected(request('active') === '0')>Inactive</option>
                </select>
            </div>
            <div>
                <label>Rows</label>
                <select name="per_page">
                    @foreach([10, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected((int) request('per_page', 25) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>&nbsp;</label>
                <button class="btn">Apply</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-meta">
            <h3 style="margin:0;">Subscription instances</h3>
            <span class="muted">{{ number_format($subscriptions->total()) }} matching instance(s)</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Provider</th>
                        <th>Account</th>
                        <th>Price</th>
                        <th>Renews At</th>
                        <th>Billing Day</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Auto-renew</th>
                        <th>Active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscriptions as $s)
                    <tr>
                        <td>{{ $s->plan_name }}</td>
                        <td>{{ $s->provider?->display_name ?? $s->provider_id }}</td>
                        <td>{{ $s->providerAccount?->label ?? '—' }}</td>
                        <td>{{ $s->currency }} {{ number_format((float) $s->monthly_price, 2) }}</td>
                        <td>{{ $s->renewal_price ? $s->currency.' '.number_format((float) $s->renewal_price, 2) : '—' }}</td>
                        <td>{{ $s->billing_anchor_day ?? '—' }}</td>
                        <td>{{ $s->started_on?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $s->ended_on?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $s->autorenew ? 'Yes' : 'No' }}</td>
                        <td><span class="pill {{ $s->active ? 'success' : 'warning' }}">{{ $s->active ? 'Active' : 'Inactive' }}</span></td>
                        <td>
                            <a class="btn secondary" href="/subscriptions/{{ $s->id }}/edit">Edit</a>
                            <form method="POST" action="/subscriptions/{{ $s->id }}" style="display:inline">
                                @csrf
                                @method('DELETE')
                                <button class="btn danger" type="submit" onclick="return confirm('Delete this subscription?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="muted">No subscriptions match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $subscriptions->onEachSide(1)->links('partials.pagination') }}
    </div>
@endif
@endsection
