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
        'request_id',
        'message_id',
        'account_hint_hash',
        'source_file_hash',
        'source_offset',
        'timestamp',
        'model',
        'input_tokens',
        'output_tokens',
        'cached_input_tokens',
        'cache_write_tokens',
        'cache_write_1h_tokens',
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
        'cache_write_1h_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'tool_tokens' => 'integer',
        'unknown_tokens' => 'integer',
    ];

    /**
     * Warning stored on a Claude event whose client did not report how much of
     * cache_write used the 1-hour TTL; its cost uses the estimated share.
     */
    public const ONE_HOUR_SPLIT_UNREPORTED = 'cache_write_1h_unreported';

    public function hasUnreportedOneHourSplit(): bool
    {
        return in_array(self::ONE_HOUR_SPLIT_UNREPORTED, self::decodeWarnings($this->warnings_json), true);
    }

    /** warnings_json with the unreported-split warning added or removed. */
    public static function warningsWithUnreportedSplit(?string $json, bool $unreported): ?string
    {
        $all = self::decodeWarnings($json);
        $warnings = array_filter($all, fn ($warning) => $warning !== self::ONE_HOUR_SPLIT_UNREPORTED);
        if (array_is_list($all)) {
            $warnings = array_values($warnings);
        }
        if ($unreported) {
            $warnings[] = self::ONE_HOUR_SPLIT_UNREPORTED;
        }

        return $warnings === [] ? null : json_encode($warnings);
    }

    /** @return array<mixed> */
    private static function decodeWarnings(?string $json): array
    {
        $warnings = $json === null ? [] : json_decode($json, true);

        return is_array($warnings) ? $warnings : [];
    }

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
