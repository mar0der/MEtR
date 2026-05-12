<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpdateAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'update_release_id',
        'platform',
        'filename',
        'signature',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(UpdateRelease::class, 'update_release_id');
    }
}
