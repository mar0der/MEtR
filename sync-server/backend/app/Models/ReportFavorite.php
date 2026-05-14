<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportFavorite extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'name',
        'query_json',
    ];

    protected $casts = [
        'query_json' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
