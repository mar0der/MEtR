<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountAttributionRule extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'account_attribution_rules';

    protected $fillable = [
        'user_id',
        'provider_id',
        'provider_account_id',
        'device_id',
        'project_id',
        'source_path_pattern',
        'model_pattern',
        'starts_at',
        'ends_at',
        'priority',
        'enabled',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
        'enabled' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function providerAccount()
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
