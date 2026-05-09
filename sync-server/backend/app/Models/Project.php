<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'canonical_name',
        'slug',
        'manual_name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function projectRoots()
    {
        return $this->hasMany(ProjectRoot::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function usageEvents()
    {
        return $this->hasMany(UsageEvent::class);
    }
}
