<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'provider_id',
        'device_id',
        'project_id',
        'external_conversation_id',
        'display_name',
        'started_at',
        'last_seen_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function usageEvents()
    {
        return $this->hasMany(UsageEvent::class);
    }
}
