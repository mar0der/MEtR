<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['id' => 'openai', 'display_name' => 'OpenAI / Codex'],
            ['id' => 'anthropic', 'display_name' => 'Claude'],
            ['id' => 'kimi', 'display_name' => 'Kimi / Moonshot'],
            ['id' => 'google', 'display_name' => 'Gemini'],
            ['id' => 'cursor', 'display_name' => 'Cursor'],
            ['id' => 'lmstudio', 'display_name' => 'LM Studio'],
            ['id' => 'ollama', 'display_name' => 'Ollama'],
            ['id' => 'cloudflare', 'display_name' => 'Cloudflare Workers AI'],
            ['id' => 'generic', 'display_name' => 'Generic JSONL'],
        ];

        foreach ($providers as $provider) {
            DB::table('providers')->updateOrInsert(
                ['id' => $provider['id']],
                array_merge($provider, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
