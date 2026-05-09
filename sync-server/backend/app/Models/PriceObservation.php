<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceObservation extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'price_observations';

    protected $fillable = [
        'provider_id',
        'source_url',
        'fetched_at',
        'source_hash',
        'parsed_json',
        'status',
        'error',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'parsed_json' => 'array',
    ];
}
