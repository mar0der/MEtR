<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderAccount extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'provider_accounts';

    protected $fillable = [
        'user_id',
        'provider_id',
        'label',
        'account_type',
        'default_device_id',
        'external_account_hint_hash',
        'active',
        'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function defaultDevice()
    {
        return $this->belongsTo(Device::class, 'default_device_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function usageEvents()
    {
        return $this->hasMany(UsageEvent::class);
    }
}
