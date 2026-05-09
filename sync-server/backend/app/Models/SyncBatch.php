<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncBatch extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'sync_batches';

    protected $fillable = [
        'user_id',
        'device_id',
        'client_batch_id',
        'direction',
        'status',
        'event_count',
        'error',
    ];

    protected $casts = [
        'event_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
