<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Device;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeed extends Command
{
    protected $signature = 'metr:demo:seed {--fresh : Delete existing demo data first}';
    protected $description = 'Seed demo account with rich usage data for screenshots';

    public function handle(): int
    {
        $email = 'demo@metr.app';
        $username = 'demo';

        $existing = User::where('email', $email)->orWhere('username', $username)->first();

        if ($existing && ! $this->option('fresh')) {
            $this->info("Demo user already exists. Use --fresh to recreate.");
            $this->info("Login: {$username} / demo1234");
            return self::SUCCESS;
        }

        if ($existing) {
            $this->info('Deleting existing demo data...');
            $this->deleteDemoData($existing);
        }

        $user = User::create([
            'name' => 'Demo User',
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('demo1234'),
        ]);

        $this->info('Creating demo devices...');
        $devices = $this->createDevices($user);

        $this->info('Creating demo projects...');
        $projects = $this->createProjects($user);

        $this->info('Creating demo subscriptions...');
        $this->createSubscriptions($user);

        $this->info('Creating demo conversations and events...');
        $this->createEvents($user, $devices, $projects);

        $this->newLine();
        $this->info('Demo account ready!');
        $this->info("Login: {$username} / demo1234");
        $this->info("URL: https://metr.petarpetkov.com/login");

        return self::SUCCESS;
    }

    private function deleteDemoData(User $user): void
    {
        UsageEvent::where('user_id', $user->id)->delete();
        Conversation::where('user_id', $user->id)->delete();
        Project::where('user_id', $user->id)->delete();
        Device::where('user_id', $user->id)->delete();
        Subscription::where('user_id', $user->id)->delete();
        $user->delete();
    }

    /** @return array<int, Device> */
    private function createDevices(User $user): array
    {
        return [
            Device::create([
                'user_id' => $user->id,
                'device_uuid' => (string) Str::uuid(),
                'display_name' => "Petar's MacBook Air",
                'platform' => 'macos',
                'last_seen_at' => now(),
            ]),
            Device::create([
                'user_id' => $user->id,
                'device_uuid' => (string) Str::uuid(),
                'display_name' => 'Windows Desktop',
                'platform' => 'windows',
                'last_seen_at' => now()->subDays(2),
            ]),
        ];
    }

    /** @return array<int, Project> */
    private function createProjects(User $user): array
    {
        $names = [
            'metr-sync-backend',
            'personal-website',
            'ai-experiments',
            'client-work-acme',
            'blog-content',
        ];

        $projects = [];
        foreach ($names as $name) {
            $projects[] = Project::create([
                'user_id' => $user->id,
                'canonical_name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ]);
        }

        return $projects;
    }

    private function createSubscriptions(User $user): void
    {
        $subs = [
            ['provider_id' => 'anthropic', 'plan_name' => 'Claude Pro', 'monthly_price' => 20, 'currency' => 'USD', 'billing_anchor_day' => 15, 'active' => true],
            ['provider_id' => 'openai', 'plan_name' => 'ChatGPT Plus', 'monthly_price' => 20, 'currency' => 'USD', 'billing_anchor_day' => 3, 'active' => true],
            ['provider_id' => 'cursor', 'plan_name' => 'Cursor Pro', 'monthly_price' => 20, 'currency' => 'USD', 'billing_anchor_day' => 22, 'active' => true],
            ['provider_id' => 'google', 'plan_name' => 'Gemini Advanced', 'monthly_price' => 19.99, 'currency' => 'USD', 'billing_anchor_day' => 10, 'active' => true],
            ['provider_id' => 'kimi', 'plan_name' => 'Kimi Premium', 'monthly_price' => 9.99, 'currency' => 'USD', 'billing_anchor_day' => 1, 'active' => false],
        ];

        foreach ($subs as $sub) {
            Subscription::create(array_merge($sub, [
                'user_id' => $user->id,
                'source_subscription_id' => Str::random(16),
            ]));
        }
    }

    /**
     * @param array<int, Device> $devices
     * @param array<int, Project> $projects
     */
    private function createEvents(User $user, array $devices, array $projects): void
    {
        $providers = ['anthropic', 'openai', 'cursor', 'google', 'kimi', 'cline'];
        $models = [
            'anthropic' => ['claude-sonnet-4-20250514', 'claude-opus-4-20250514'],
            'openai' => ['gpt-4.1', 'gpt-4.1-mini', 'o3-mini'],
            'cursor' => ['claude-3-7-sonnet', 'gpt-4o'],
            'google' => ['gemini-2.5-pro-preview-05-06', 'gemini-2.5-flash-preview-05-06'],
            'kimi' => ['kimi-k2-5', 'kimi-k1-5'],
            'cline' => ['claude-3-5-sonnet', 'deepseek-chat-v3-0324'],
        ];

        $now = Carbon::now();
        $events = [];
        $conversations = [];

        for ($day = 29; $day >= 0; $day--) {
            $date = $now->copy()->subDays($day);
            $dayEvents = rand(3, 12);

            for ($i = 0; $i < $dayEvents; $i++) {
                $provider = $providers[array_rand($providers)];
                $model = $models[$provider][array_rand($models[$provider])];
                $device = $devices[array_rand($devices)];
                $project = rand(0, 3) === 0 ? null : $projects[array_rand($projects)];

                $inputTokens = rand(500, 25000);
                $cachedInput = rand(0, (int) ($inputTokens * 0.4));
                $outputTokens = rand(200, 15000);
                $cacheWrite = rand(0, 5000);
                $cacheRead = rand(0, 8000);
                $reasoning = $provider === 'openai' ? rand(0, 5000) : 0;
                $tool = rand(0, 2000);
                $unknown = 0;

                $effectiveInput = max(0, $inputTokens - $cachedInput);
                $total = $effectiveInput + $outputTokens + $cachedInput + $cacheWrite + $cacheRead + $reasoning + $tool;

                // Approximate cost calc
                $inputCost = $effectiveInput * (rand(15, 150) / 1_000_000);
                $outputCost = $outputTokens * (rand(60, 600) / 1_000_000);
                $cachedCost = ($cachedInput + $cacheRead) * (rand(3, 40) / 1_000_000);
                $cacheWriteCost = $cacheWrite * (rand(50, 150) / 1_000_000);
                $reasoningCost = $reasoning * (rand(30, 300) / 1_000_000);
                $cost = round($inputCost + $outputCost + $cachedCost + $cacheWriteCost + $reasoningCost, 6);

                $convKey = $provider . '_' . ($project?->id ?? 'null');
                if (!isset($conversations[$convKey])) {
                    $conversations[$convKey] = Conversation::create([
                        'user_id' => $user->id,
                        'provider_id' => $provider,
                        'device_id' => $device->id,
                        'project_id' => $project?->id,
                        'external_conversation_id' => 'conv_' . Str::random(12),
                        'display_name' => ucfirst($provider) . ' Chat ' . rand(1, 99),
                        'started_at' => $date->copy()->subHours(rand(1, 8)),
                        'last_seen_at' => $date->copy()->subMinutes(rand(1, 30)),
                    ]);
                }

                $events[] = [
                    'id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'device_id' => $device->id,
                    'provider_id' => $provider,
                    'provider_account_id' => null,
                    'account_attribution_confidence' => 'unknown',
                    'project_id' => $project?->id,
                    'conversation_id' => $conversations[$convKey]->id,
                    'source_event_id' => 'evt_' . Str::random(16),
                    'source_event_hash' => hash('sha256', Str::random(32)),
                    'timestamp' => $date->copy()->subHours(rand(1, 12))->subMinutes(rand(0, 59)),
                    'model' => $model,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cached_input_tokens' => $cachedInput,
                    'cache_write_tokens' => $cacheWrite,
                    'cache_read_tokens' => $cacheRead,
                    'reasoning_tokens' => $reasoning,
                    'tool_tokens' => $tool,
                    'unknown_tokens' => $unknown,
                    'official_api_cost_usd' => $cost,
                    'model_price_id' => null,
                    'pricing_match_confidence' => 'estimated',
                    'client_created_at' => $date,
                    'client_updated_at' => $date,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Batch insert every 100
                if (count($events) >= 100) {
                    UsageEvent::insert($events);
                    $events = [];
                }
            }
        }

        if (!empty($events)) {
            UsageEvent::insert($events);
        }

        $this->info('Created ' . UsageEvent::where('user_id', $user->id)->count() . ' usage events.');
    }
}
