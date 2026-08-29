<?php

namespace Tests\Unit;

use App\Services\Cursor\CursorUsageCsvParser;
use Tests\TestCase;

class CursorUsageCsvParserTest extends TestCase
{
    public function test_parses_token_rows_and_skips_empty(): void
    {
        $parsed = (new CursorUsageCsvParser)->parse(
            base_path('tests/fixtures/cursor/usage-events.csv')
        );

        $this->assertSame(1, $parsed['skipped']);
        $this->assertCount(3, $parsed['events']);
        $this->assertSame([
            'cursor-grok-4.6-high' => 1,
            'auto' => 1,
            'composer-2.5-fast' => 1,
        ], $parsed['models']);

        $grok = $parsed['events'][0];
        $this->assertSame('cursor', $grok['provider_id']);
        $this->assertSame('/Cursor', $grok['project']['root_path']);
        $this->assertSame(1000, $grok['tokens']['input']);
        $this->assertSame(2000, $grok['tokens']['cache_read']);
        $this->assertSame(100, $grok['tokens']['output']);
        $this->assertStringStartsWith('cursor-csv:', $grok['source_event_id']);
    }

    public function test_stable_ids_across_parses(): void
    {
        $parser = new CursorUsageCsvParser;
        $path = base_path('tests/fixtures/cursor/usage-events.csv');
        $first = $parser->parse($path);
        $second = $parser->parse($path);

        $this->assertSame(
            array_column($first['events'], 'source_event_id'),
            array_column($second['events'], 'source_event_id')
        );
    }
}
