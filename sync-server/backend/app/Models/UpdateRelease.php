<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpdateRelease extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'release_notes',
        'released_at',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(UpdateAsset::class);
    }
}
