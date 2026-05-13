<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Models\ModelPrice;
use App\Models\Project;
use App\Models\ProviderAccount;
use App\Models\Provider;
use App\Models\Subscription;
use App\Models\UpdateRelease;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WebController extends Controller
{
    public function download()
    {
        $latest = UpdateRelease::orderByDesc('released_at')->first();
        $assets = $latest ? $latest->assets()->get()->keyBy('platform') : collect();

        return view('download', [
            'release' => $latest,
            'assets' => $assets,
        ]);
    }

    public function loginForm()
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        return view('login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (Auth::attempt([$field => $data['login'], 'password' => $data['password']])) {
            $request->session()->regenerate();

            return redirect()->intended('/dashboard');
        }

        return back()->withErrors(['login' => 'Invalid credentials.']);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function dashboard(Request $request)
    {
        $user = Auth::user();
        $activeTab = in_array($request->query('tab'), ['devices', 'accounts', 'events', 'all'], true)
            ? $request->query('tab')
            : 'devices';

        $query = $this->filteredUsageQuery($request, $user->id);

        $summary = (clone $query)->select([
            DB::raw('SUM(input_tokens) as input_tokens'),
            DB::raw('SUM(input_tokens - cached_input_tokens) as effective_input_tokens'),
            DB::raw('SUM(output_tokens) as output_tokens'),
            DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
            DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
            DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
            DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
            DB::raw('SUM(tool_tokens) as tool_tokens'),
            DB::raw('SUM(unknown_tokens) as unknown_tokens'),
            DB::raw('SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            DB::raw('COUNT(*) as event_count'),
            DB::raw('SUM(CASE WHEN official_api_cost_usd IS NULL THEN 1 ELSE 0 END) as unpriced_count'),
            DB::raw('SUM(CASE WHEN provider_account_id IS NULL THEN 1 ELSE 0 END) as unattributed_count'),
            DB::raw('SUM(official_api_cost_usd) as total_cost'),
        ])->first();

        $byDevice = (clone $query)
            ->leftJoin('devices', 'devices.id', '=', 'usage_events.device_id')
            ->select([
                DB::raw("COALESCE(devices.alias, devices.display_name) as label"),
                'devices.platform as meta',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('devices.id', 'devices.alias', 'devices.display_name', 'devices.platform')
            ->orderByDesc('event_count')
            ->limit(10)
            ->get();

        $byProject = (clone $query)
            ->leftJoin('projects', 'projects.id', '=', 'usage_events.project_id')
            ->select([
                DB::raw("COALESCE(projects.manual_name, projects.canonical_name, 'Unknown project') as label"),
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('projects.id', 'projects.manual_name', 'projects.canonical_name')
            ->orderByDesc('event_count')
            ->limit(10)
            ->get();

        $byProviderAccount = (clone $query)
            ->leftJoin('provider_accounts', 'provider_accounts.id', '=', 'usage_events.provider_account_id')
            ->select([
                'provider_accounts.label as account_label',
                'usage_events.provider_id',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('provider_accounts.label', 'usage_events.provider_id')
            ->orderByDesc('event_count')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $row->label = $row->account_label ?: $row->provider_id.' / unknown account';

                return $row;
            });

        $byModel = (clone $query)
            ->select([
                'provider_id',
                'model',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('provider_id', 'model')
            ->orderByDesc('event_count')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $row->label = $row->provider_id.' / '.($row->model ?: 'unknown model');

                return $row;
            });

        $eventsQuery = (clone $query)
            ->leftJoin('projects', 'projects.id', '=', 'usage_events.project_id')
            ->leftJoin('devices', 'devices.id', '=', 'usage_events.device_id')
            ->leftJoin('provider_accounts', 'provider_accounts.id', '=', 'usage_events.provider_account_id')
            ->select([
                'usage_events.*',
                DB::raw("COALESCE(projects.manual_name, projects.canonical_name, 'Unknown') as project_name"),
                DB::raw("COALESCE(devices.alias, devices.display_name, 'Unknown device') as device_name"),
                DB::raw("COALESCE(provider_accounts.label, 'Unattributed') as provider_account_name"),
            ])
            ->orderByDesc('usage_events.timestamp');

        $events = $eventsQuery->paginate($this->perPage($request, 50))->withQueryString();

        return view('dashboard', [
            'summary' => $summary,
            'byDevice' => $byDevice,
            'byProject' => $byProject,
            'byProviderAccount' => $byProviderAccount,
            'byModel' => $byModel,
            'events' => $events,
            'activeTab' => $activeTab,
            'filterOptions' => $this->dashboardFilterOptions($user->id),
        ]);
    }

    public function updateDeviceAlias(Request $request, $id)
    {
        $validated = $request->validate(["alias" => ["nullable", "string", "max:255"]]);
        DB::table("devices")->where("id", $id)->where("user_id", Auth::id())->update(["alias" => $validated["alias"] ?? null]);
        return redirect("/devices")->with("success", "Device alias updated.");
    }

    public function deleteDevice($id)
    {
        DB::table("devices")->where("id", $id)->where("user_id", Auth::id())->delete();
        return redirect("/devices")->with("success", "Device removed.");
    }

    public function devices()
    {
        $query = Device::where('user_id', Auth::id())->orderByDesc('last_seen_at');

        if (request()->filled('q')) {
            $term = request('q');
            $query->where(function ($q) use ($term) {
                $q->where('display_name', 'like', "%{$term}%")
                    ->orWhere('alias', 'like', "%{$term}%")
                    ->orWhere('platform', 'like', "%{$term}%")
                    ->orWhere('device_uuid', 'like', "%{$term}%");
            });
        }
        if (request()->filled('platform')) {
            $query->where('platform', request('platform'));
        }

        return view('devices', [
            'devices' => $query->paginate($this->perPage(request(), 25))->withQueryString(),
            'platforms' => Device::where('user_id', Auth::id())
                ->whereNotNull('platform')
                ->distinct()
                ->orderBy('platform')
                ->pluck('platform'),
        ]);
    }

    public function providerAccounts()
    {
        $query = ProviderAccount::where('user_id', Auth::id())->with('provider');

        if (request()->filled('q')) {
            $term = request('q');
            $query->where(function ($q) use ($term) {
                $q->where('label', 'like', "%{$term}%")
                    ->orWhere('account_type', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%");
            });
        }
        if (request()->filled('provider_id')) {
            $query->where('provider_id', request('provider_id'));
        }
        if (request()->filled('active')) {
            $query->where('active', request('active') === '1');
        }

        return view('provider-accounts', [
            'accounts' => $query->orderBy('provider_id')->orderBy('label')->paginate($this->perPage(request(), 25))->withQueryString(),
            'providers' => Provider::orderBy('display_name')->get(),
        ]);
    }

    public function subscriptions()
    {
        $query = Subscription::where('user_id', Auth::id())->with(['provider', 'providerAccount']);

        if (request()->filled('q')) {
            $term = request('q');
            $query->where(function ($q) use ($term) {
                $q->where('plan_name', 'like', "%{$term}%")
                    ->orWhere('currency', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%");
            });
        }
        if (request()->filled('provider_id')) {
            $query->where('provider_id', request('provider_id'));
        }
        if (request()->filled('active')) {
            $query->where('active', request('active') === '1');
        }

        return view('subscriptions', [
            'subscriptions' => $query->orderByDesc('active')->orderBy('provider_id')->paginate($this->perPage(request(), 25))->withQueryString(),
            'providers' => Provider::orderBy('display_name')->get(),
        ]);
    }

    public function projects(Request $request)
    {
        $query = Project::where('user_id', Auth::id())
            ->withCount('projectRoots')
            ->withMax('usageEvents', 'timestamp');

        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('canonical_name', 'like', "%{$term}%")
                    ->orWhere('manual_name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }
        if ($request->filled('active')) {
            $query->where('active', $request->input('active') === '1');
        }

        return view('projects', [
            'projects' => $query
                ->orderByDesc('usage_events_max_timestamp')
                ->orderBy('canonical_name')
                ->paginate($this->perPage($request, 25))
                ->withQueryString(),
        ]);
    }

    public function pricing(Request $request)
    {
        $user = Auth::user();

        $usedModels = UsageEvent::where('usage_events.user_id', $user->id)
            ->whereNotNull('model')
            ->select('provider_id', 'model')
            ->distinct()
            ->get();

        $allPrices = ModelPrice::with('provider')
            ->whereNull('effective_to')
            ->orderBy('provider_id')
            ->orderBy('model')
            ->get();

        $usedKeys = $usedModels
            ->map(fn ($e) => strtolower($e->provider_id.'|'.$e->model))
            ->all();
        $usedPriceIds = [];
        foreach ($allPrices as $price) {
            $key = strtolower($price->provider_id.'|'.$price->model);
            $aliases = json_decode($price->aliases_json ?? '[]', true);
            $hasMatch = in_array($key, $usedKeys, true);
            foreach ($aliases as $alias) {
                if (in_array(strtolower($price->provider_id.'|'.$alias), $usedKeys, true)) {
                    $hasMatch = true;
                    break;
                }
            }
            if ($hasMatch) {
                $usedPriceIds[] = $price->id;
            }
        }

        $query = ModelPrice::with('provider')->whereNull('effective_to');

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->input('provider_id'));
        }
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('model', 'like', "%{$term}%")
                    ->orWhere('aliases_json', 'like', "%{$term}%")
                    ->orWhere('source_url', 'like', "%{$term}%");
            });
        }
        if ($request->input('usage') === 'used') {
            $query->whereIn('id', $usedPriceIds ?: ['__none__']);
        } elseif ($request->input('usage') === 'unused' && ! empty($usedPriceIds)) {
            $query->whereNotIn('id', $usedPriceIds);
        }

        if (! empty($usedPriceIds)) {
            $query->orderByRaw(
                'CASE WHEN id IN ('.implode(',', array_fill(0, count($usedPriceIds), '?')).') THEN 0 ELSE 1 END',
                $usedPriceIds
            );
        }

        $prices = $query->orderBy('provider_id')
            ->orderBy('model')
            ->paginate($this->perPage($request, 50))
            ->withQueryString();

        return view('pricing', [
            'prices' => $prices,
            'usedPriceIds' => $usedPriceIds,
            'usedCount' => count($usedPriceIds),
            'unusedCount' => max(0, $allPrices->count() - count($usedPriceIds)),
            'providers' => Provider::orderBy('display_name')->get(),
        ]);

    }
    public function settings()
    {
        $user = Auth::user();
        $eventCount = UsageEvent::where('usage_events.user_id', $user->id)->count();
        $projectCount = Project::where('user_id', $user->id)->count();

        return view('settings', [
            'eventCount' => $eventCount,
            'projectCount' => $projectCount,
        ]);
    }

    public function clearData(Request $request)
    {
        $user = Auth::user();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('usage_events')->where('user_id', $user->id)->delete();
        DB::table('conversations')->where('user_id', $user->id)->delete();
        DB::table('project_roots')->whereIn('project_id', function ($q) use ($user) {
            $q->select('id')->from('projects')->where('user_id', $user->id);
        })->delete();
        DB::table('projects')->where('user_id', $user->id)->delete();
        DB::table('sync_batches')->where('user_id', $user->id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return redirect('/settings')->with('success', 'All usage data cleared from server.');
    }

    private function filteredUsageQuery(Request $request, int $userId): Builder
    {
        $query = UsageEvent::where('usage_events.user_id', $userId);
        $this->applyUsageFilters($query, $request);

        return $query;
    }

    private function applyUsageFilters(Builder $query, Request $request): void
    {
        if ($request->filled('from')) {
            $query->where('usage_events.timestamp', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('usage_events.timestamp', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }
        if ($request->filled('provider_id')) {
            $query->where('usage_events.provider_id', $request->input('provider_id'));
        }
        if ($request->filled('device_id')) {
            $query->where('usage_events.device_id', $request->input('device_id'));
        }
        if ($request->filled('project_id')) {
            $query->where('usage_events.project_id', $request->input('project_id'));
        }
        if ($request->filled('provider_account_id')) {
            if ($request->input('provider_account_id') === '__none__') {
                $query->whereNull('usage_events.provider_account_id');
            } else {
                $query->where('usage_events.provider_account_id', $request->input('provider_account_id'));
            }
        }
        if ($request->filled('model')) {
            $query->where('usage_events.model', $request->input('model'));
        }
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('usage_events.model', 'like', "%{$term}%")
                    ->orWhere('usage_events.source_event_id', 'like', "%{$term}%")
                    ->orWhereHas('project', function ($projectQuery) use ($term) {
                        $projectQuery->where('canonical_name', 'like', "%{$term}%")
                            ->orWhere('manual_name', 'like', "%{$term}%");
                    });
            });
        }
    }

    private function dashboardFilterOptions(int $userId): array
    {
        return [
            'providers' => Provider::whereIn(
                'id',
                UsageEvent::where('user_id', $userId)->select('provider_id')->distinct()
            )->orderBy('display_name')->get(),
            'devices' => Device::where('user_id', $userId)->orderBy('display_name')->get(),
            'projects' => Project::where('user_id', $userId)->orderBy('canonical_name')->get(),
            'accounts' => ProviderAccount::where('user_id', $userId)->orderBy('label')->get(),
            'models' => UsageEvent::where('user_id', $userId)
                ->whereNotNull('model')
                ->select('model')
                ->distinct()
                ->orderBy('model')
                ->pluck('model'),
        ];
    }

    private function perPage(Request $request, int $default): int
    {
        $value = (int) $request->query('per_page', $default);

        return in_array($value, [10, 25, 50, 100], true) ? $value : $default;
    }
}
