<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageEvent extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'usage_events';

    protected $fillable = [
        'user_id',
        'device_id',
        'provider_id',
        'provider_account_id',
        'account_attribution_confidence',
        'account_attribution_reason',
        'project_id',
        'conversation_id',
        'source_event_id',
        'source_event_hash',
        'source_file_hash',
        'source_offset',
        'timestamp',
        'model',
        'input_tokens',
        'output_tokens',
        'cached_input_tokens',
        'cache_write_tokens',
        'cache_read_tokens',
        'reasoning_tokens',
        'tool_tokens',
        'unknown_tokens',
        'official_api_cost_usd',
        'model_price_id',
        'pricing_match_confidence',
        'warnings_json',
        'client_created_at',
        'client_updated_at',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'client_created_at' => 'datetime',
        'client_updated_at' => 'datetime',
        'official_api_cost_usd' => 'decimal:10',
        'source_offset' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'tool_tokens' => 'integer',
        'unknown_tokens' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function providerAccount()
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function modelPrice()
    {
        return $this->belongsTo(ModelPrice::class);
    }
}
