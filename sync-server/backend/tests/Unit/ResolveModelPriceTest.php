<?php

namespace Tests\Unit;

use App\Models\ModelPrice;
use App\Models\Provider;
use App\Services\Pricing\ResolveModelPrice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveModelPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_period_lookup_chooses_correct_historical_period(): void
    {
        $provider = Provider::factory()->create(['id' => 'openai']);

        $oldPrice = ModelPrice::create([
            'provider_id' => 'openai',
            'model' => 'gpt-4',
            'input_per_1m' => 30.00,
            'output_per_1m' => 60.00,
            'effective_from' => Carbon::parse('2024-01-01'),
            'effective_to' => Carbon::parse('2024-06-01'),
        ]);

        $newPrice = ModelPrice::create([
            'provider_id' => 'openai',
            'model' => 'gpt-4',
            'input_per_1m' => 20.00,
            'output_per_1m' => 40.00,
            'effective_from' => Carbon::parse('2024-06-01'),
            'effective_to' => null,
        ]);

        $resolver = new ResolveModelPrice;

        $resultOld = $resolver->handle('openai', 'gpt-4', Carbon::parse('2024-03-15'));
        $this->assertNotNull($resultOld);
        $this->assertEquals(30.00, (float) $resultOld->input_per_1m);

        $resultNew = $resolver->handle('openai', 'gpt-4', Carbon::parse('2024-07-15'));
        $this->assertNotNull($resultNew);
        $this->assertEquals(20.00, (float) $resultNew->input_per_1m);
    }

    public function test_catalog_price_wins_over_a_newer_manual_price(): void
    {
        Provider::factory()->create(['id' => 'anthropic']);

        $catalog = ModelPrice::create([
            'provider_id' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'input_per_1m' => 4.00,
            'output_per_1m' => 20.00,
            'cache_write_per_1m' => 5.00,
            'cache_read_per_1m' => 0.20,
            'effective_from' => Carbon::parse('2025-01-01'),
            'catalog_version' => 'catalog-hash',
            'user_override' => false,
        ]);

        ModelPrice::create([
            'provider_id' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'input_per_1m' => 4.00,
            'output_per_1m' => 20.00,
            'effective_from' => Carbon::parse('2026-09-23 08:50:15'),
            'catalog_version' => 'user',
            'user_override' => false,
        ]);

        $price = (new ResolveModelPrice)->handle('anthropic', 'claude-opus-5-5', Carbon::parse('2026-09-23 12:00:00'));

        $this->assertNotNull($price);
        $this->assertSame($catalog->id, $price->id);
        $this->assertEquals(0.20, (float) $price->cache_read_per_1m);
    }

    public function test_manual_price_is_used_when_no_catalog_row_exists(): void
    {
        Provider::factory()->create(['id' => 'openai']);

        $manual = ModelPrice::create([
            'provider_id' => 'openai',
            'model' => 'gpt-6-luna',
            'input_per_1m' => 0.10,
            'output_per_1m' => 0.50,
            'effective_from' => Carbon::parse('2026-09-22'),
            'catalog_version' => 'user',
            'user_override' => true,
        ]);

        $price = (new ResolveModelPrice)->handle('openai', 'gpt-6-luna', Carbon::parse('2026-09-23'));

        $this->assertNotNull($price);
        $this->assertSame($manual->id, $price->id);
    }
}
