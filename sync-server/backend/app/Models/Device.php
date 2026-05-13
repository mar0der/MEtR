<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'device_uuid',
        'display_name',
        'alias',
        'platform',
        'hostname_hash',
        'os_version',
        'app_version',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function usageEvents()
    {
        return $this->hasMany(UsageEvent::class);
    }

    public function projectRoots()
    {
        return $this->hasMany(ProjectRoot::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function syncBatches()
    {
        return $this->hasMany(SyncBatch::class);
    }
}
