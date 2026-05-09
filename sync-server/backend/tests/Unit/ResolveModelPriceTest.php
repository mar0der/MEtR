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
}
