<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelPrice extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'model_prices';

    protected $fillable = [
        'provider_id',
        'model',
        'aliases_json',
        'currency',
        'input_per_1m',
        'output_per_1m',
        'cached_input_per_1m',
        'cache_write_per_1m',
        'cache_read_per_1m',
        'reasoning_per_1m',
        'tool_per_1m',
        'effective_from',
        'effective_to',
        'source_url',
        'source_hash',
        'catalog_version',
        'user_override',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'user_override' => 'boolean',
        'input_per_1m' => 'decimal:10',
        'output_per_1m' => 'decimal:10',
        'cached_input_per_1m' => 'decimal:10',
        'cache_write_per_1m' => 'decimal:10',
        'cache_read_per_1m' => 'decimal:10',
        'reasoning_per_1m' => 'decimal:10',
        'tool_per_1m' => 'decimal:10',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function usageEvents()
    {
        return $this->hasMany(UsageEvent::class);
    }
}
