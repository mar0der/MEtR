<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function providerAccounts()
    {
        return $this->hasMany(ProviderAccount::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function usageEvents()
    {
        return $this->hasMany(UsageEvent::class);
    }

    public function attributionRules()
    {
        return $this->hasMany(AccountAttributionRule::class);
    }
}
