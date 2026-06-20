<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'provider_account_id',
        'source_subscription_id',
        'provider_id',
        'plan_name',
        'monthly_price',
        'renewal_price',
        'currency',
        'billing_anchor_day',
        'started_on',
        'ended_on',
        'active',
        'autorenew',
        'notes',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:6',
        'renewal_price' => 'decimal:6',
        'billing_anchor_day' => 'integer',
        'started_on' => 'date',
        'ended_on' => 'date',
        'active' => 'boolean',
        'autorenew' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function providerAccount()
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
