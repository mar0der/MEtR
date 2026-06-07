<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Models\ModelPrice;
use App\Models\Project;
use App\Models\ProviderAccount;
use App\Models\Provider;
use App\Models\ReportFavorite;
use App\Models\Subscription;
use App\Models\UpdateRelease;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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
            'remember' => ['nullable', 'boolean'],
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (Auth::attempt([$field => $data['login'], 'password' => $data['password']], $request->boolean('remember'))) {
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

        return redirect('/');
    }

    public function registerForm()
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        return view('register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }

    public function forgotPasswordForm()
    {
        return view('forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        if ($request->input('email') === 'demo@metr.app') {
            return back()->withErrors(['email' => 'Password reset is disabled for the demo account.']);
        }

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? back()->with(['status' => __($status)])
            : back()->withErrors(['email' => __($status)]);
    }

    public function resetPasswordForm(Request $request, string $token)
    {
        return view('reset-password', ['token' => $token, 'email' => $request->email]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));
                $user->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => [__($status)]]);
    }

    public function dashboard(Request $request)
    {
        $user = Auth::user();
        $activeTab = in_array($request->query('tab'), ['devices', 'accounts', 'events', 'all'], true)
            ? $request->query('tab')
            : 'devices';

        $query = $this->filteredUsageQuery($request, $user->id);

        $summary = Cache::remember(
            $this->cacheKey('dashboard:summary', $request),
            60,
            function () use ($query) {
                return (clone $query)->select([
                    DB::raw('SUM(input_tokens) as input_tokens'),
                    DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0)) as effective_input_tokens'),
                    DB::raw('SUM(output_tokens) as output_tokens'),
                    DB::raw('SUM(cached_input_tokens + cache_write_tokens + cache_read_tokens) as cached_tokens'),
                    DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
                    DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
                    DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
                    DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
                    DB::raw('SUM(tool_tokens) as tool_tokens'),
                    DB::raw('SUM(unknown_tokens) as unknown_tokens'),
                    DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0) + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
                    DB::raw('COUNT(*) as event_count'),
                    DB::raw('SUM(CASE WHEN official_api_cost_usd IS NULL THEN 1 ELSE 0 END) as unpriced_count'),
                    DB::raw('SUM(CASE WHEN provider_account_id IS NULL THEN 1 ELSE 0 END) as unattributed_count'),
                    DB::raw('SUM(official_api_cost_usd) as total_cost'),
                ])->first();
            }
        );

        $byDevice = (clone $query)
            ->leftJoin('devices', 'devices.id', '=', 'usage_events.device_id')
            ->select([
                DB::raw("COALESCE(devices.alias, devices.display_name) as label"),
                'devices.platform as meta',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(cached_input_tokens + cache_write_tokens + cache_read_tokens) as cached_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0)) as effective_input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0) + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('devices.id', 'devices.alias', 'devices.display_name', 'devices.platform')
            ->tap(fn (Builder $q) => $this->applyDashboardTableSort($q, $request, 'device', 'label'))
            ->limit(10)
            ->get();

        $byProject = (clone $query)
            ->leftJoin('projects', 'projects.id', '=', 'usage_events.project_id')
            ->select([
                DB::raw("COALESCE(projects.manual_name, projects.canonical_name, 'Unknown project') as label"),
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(cached_input_tokens + cache_write_tokens + cache_read_tokens) as cached_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0)) as effective_input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0) + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('projects.id', 'projects.manual_name', 'projects.canonical_name')
            ->tap(fn (Builder $q) => $this->applyDashboardTableSort($q, $request, 'project', 'label'))
            ->limit(10)
            ->get();

        $byProviderAccount = (clone $query)
            ->leftJoin('provider_accounts', 'provider_accounts.id', '=', 'usage_events.provider_account_id')
            ->select([
                'provider_accounts.label as account_label',
                'usage_events.provider_id',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(cached_input_tokens + cache_write_tokens + cache_read_tokens) as cached_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0)) as effective_input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0) + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('provider_accounts.label', 'usage_events.provider_id')
            ->tap(fn (Builder $q) => $this->applyDashboardTableSort($q, $request, 'account', 'provider_accounts.label'))
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
                DB::raw('SUM(cached_input_tokens + cache_write_tokens + cache_read_tokens) as cached_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0)) as effective_input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(GREATEST(input_tokens - cached_input_tokens, 0) + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('provider_id', 'model')
            ->tap(fn (Builder $q) => $this->applyDashboardTableSort($q, $request, 'model', 'model'))
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

    public function reports(Request $request)
    {
        $user = Auth::user();
        $dateRange = $this->reportDateRange($request);
        $metric = in_array($request->query('metric'), ['cost', 'tokens'], true)
            ? $request->query('metric')
            : 'cost';

        $query = $this->filteredUsageQuery($request, $user->id, $dateRange);

        $summary = Cache::remember(
            $this->cacheKey('reports:summary', $request, ['metric' => $metric]),
            300,
            function () use ($query) {
                $summaryRaw = (clone $query)->select([
                    DB::raw('SUM(input_tokens) as input_tokens'),
                    DB::raw('SUM(output_tokens) as output_tokens'),
                    DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
                    DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
                    DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
                    DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
                    DB::raw('SUM(tool_tokens) as tool_tokens'),
                    DB::raw('SUM(unknown_tokens) as unknown_tokens'),
                    DB::raw('COUNT(*) as event_count'),
                    DB::raw('SUM(official_api_cost_usd) as total_cost'),
                ])->first();
                return $this->reportTotals($summaryRaw);
            }
        );

        $dailyRows = Cache::remember(
            $this->cacheKey('reports:daily', $request, ['metric' => $metric]),
            300,
            function () use ($query, $metric) {
                return (clone $query)
                    ->select([
                        DB::raw('DATE(usage_events.timestamp) as bucket'),
                        DB::raw('SUM(input_tokens) as input_tokens'),
                        DB::raw('SUM(output_tokens) as output_tokens'),
                        DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
                        DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
                        DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
                        DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
                        DB::raw('SUM(tool_tokens) as tool_tokens'),
                        DB::raw('SUM(unknown_tokens) as unknown_tokens'),
                        DB::raw('COUNT(*) as event_count'),
                        DB::raw('SUM(official_api_cost_usd) as total_cost'),
                    ])
                    ->groupBy(DB::raw('DATE(usage_events.timestamp)'))
                    ->orderBy('bucket')
                    ->get()
                    ->map(fn ($row) => $this->reportChartRow($row, $metric));
            }
        );

        $maxValue = max(1, (float) $dailyRows->max('value'));

        $byProject = $this->reportGroupBy($query, 'project_id', 'projects', "COALESCE(projects.manual_name, projects.canonical_name, 'Unknown project')");
        $byProvider = $this->reportGroupBy($query, 'provider_id');
        $byDevice = $this->reportGroupBy($query, 'device_id', 'devices', "COALESCE(devices.alias, devices.display_name, 'Unknown device')");
        $byModel = $this->reportGroupBy($query, 'model');

        return view('reports', [
            'summary' => $summary,
            'rows' => $dailyRows,
            'maxValue' => $maxValue,
            'metric' => $metric,
            'dateRange' => $dateRange,
            'filterOptions' => $this->dashboardFilterOptions($user->id),
            'presets' => $this->reportPresets(),
            'favorites' => ReportFavorite::where('user_id', $user->id)->orderBy('name')->get(),
            'activeFavoriteId' => $request->query('favorite_id'),
            'byProject' => $byProject,
            'byProvider' => $byProvider,
            'byDevice' => $byDevice,
            'byModel' => $byModel,
        ]);
    }

    public function storeReportFavorite(Request $request)
    {
        if ($this->isDemoUser()) {
            return redirect('/reports')->withErrors(['demo' => 'Demo account data cannot be modified.']);
        }

        $data = $request->validate([
            'favorite_name' => ['required', 'string', 'max:80'],
        ]);

        $query = collect($request->except(['_token', 'favorite_name', 'favorite_id']))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->only($this->reportFavoriteKeys())
            ->all();

        ReportFavorite::create([
            'user_id' => Auth::id(),
            'name' => $data['favorite_name'],
            'query_json' => $query,
        ]);

        return redirect('/reports?'.http_build_query($query))->with('success', 'Report favorite saved.');
    }

    public function loadReportFavorite($id)
    {
        $favorite = ReportFavorite::where('user_id', Auth::id())->findOrFail($id);
        $query = array_merge($favorite->query_json ?? [], ['favorite_id' => $favorite->id]);

        return redirect('/reports?'.http_build_query($query));
    }

    public function deleteReportFavorite($id)
    {
        if ($this->isDemoUser()) {
            return redirect('/reports')->withErrors(['demo' => 'Demo account data cannot be modified.']);
        }

        ReportFavorite::where('user_id', Auth::id())->where('id', $id)->delete();

        return redirect('/reports')->with('success', 'Report favorite deleted.');
    }

    public function updateDeviceAlias(Request $request, $id)
    {
        if ($this->isDemoUser()) {
            return redirect('/devices')->withErrors(['demo' => 'Demo account data cannot be modified.']);
        }

        $validated = $request->validate(["alias" => ["nullable", "string", "max:255"]]);
        DB::table("devices")->where("id", $id)->where("user_id", Auth::id())->update(["alias" => $validated["alias"] ?? null]);
        return redirect("/devices")->with("success", "Device alias updated.");
    }

    public function deleteDevice($id)
    {
        if ($this->isDemoUser()) {
            return redirect('/devices')->withErrors(['demo' => 'Demo account data cannot be modified.']);
        }

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

        $cacheKey = 'pricing:used_models:'.$user->id;
        $usedModels = Cache::remember($cacheKey, 3600, function () use ($user) {
            return UsageEvent::where('usage_events.user_id', $user->id)
                ->whereNotNull('model')
                ->select('provider_id', 'model')
                ->distinct()
                ->get();
        });

        $allPrices = Cache::remember('pricing:catalog', 3600, function () {
            return ModelPrice::with('provider')
                ->whereNull('effective_to')
                ->orderBy('provider_id')
                ->orderBy('model')
                ->get();
        });

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
        if ($this->isDemoUser()) {
            return redirect('/settings')->withErrors(['demo' => 'Demo account data cannot be modified.']);
        }

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

    private function filteredUsageQuery(Request $request, int $userId, ?array $dateRange = null): Builder
    {
        $query = UsageEvent::where('usage_events.user_id', $userId);
        $this->applyUsageFilters($query, $request, $dateRange);

        return $query;
    }

    private function cacheKey(string $prefix, Request $request, array $extra = []): string
    {
        $filters = array_merge($request->only([
            'from', 'to', 'provider_id', 'device_id', 'project_id',
            'provider_account_id', 'model', 'metric', 'preset', 'q',
        ]), $extra);
        ksort($filters);

        return $prefix.':'.Auth::id().':'.md5(serialize($filters));
    }

    private function applyUsageFilters(Builder $query, Request $request, ?array $dateRange = null): void
    {
        $from = $dateRange['from'] ?? ($request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null);
        $to = $dateRange['to'] ?? ($request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null);

        if ($from) {
            $query->where('usage_events.timestamp', '>=', $from);
        }
        if ($to) {
            $query->where('usage_events.timestamp', '<=', $to);
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

    private function applyDashboardTableSort(Builder $query, Request $request, string $tableKey, string $nameColumn): Builder
    {
        [$sort, $direction] = $this->dashboardTableSort($request, $tableKey);

        return match ($sort) {
            'name' => $query->orderBy($nameColumn, $direction)->orderByDesc('event_count'),
            'tokens' => $query->orderBy('total_tokens', $direction)->orderBy($nameColumn, 'asc'),
            'avg_cache' => $query->orderByRaw('SUM(cached_input_tokens + cache_write_tokens + cache_read_tokens) / NULLIF(COUNT(*), 0) '.$direction)->orderByDesc('event_count'),
            'avg_input' => $query->orderByRaw('SUM(input_tokens - cached_input_tokens) / NULLIF(COUNT(*), 0) '.$direction)->orderByDesc('event_count'),
            'avg_output' => $query->orderByRaw('SUM(output_tokens) / NULLIF(COUNT(*), 0) '.$direction)->orderByDesc('event_count'),
            'cost' => $query->orderBy('total_cost', $direction)->orderByDesc('event_count'),
            'avg_cost' => $query->orderByRaw('SUM(official_api_cost_usd) / NULLIF(COUNT(*), 0) '.$direction)->orderByDesc('event_count'),
            default => $query->orderBy('event_count', $direction)->orderBy($nameColumn, 'asc'),
        };
    }

    private function dashboardTableSort(Request $request, string $tableKey): array
    {
        $allowed = ['name', 'events', 'tokens', 'avg_cache', 'avg_input', 'avg_output', 'cost', 'avg_cost'];
        $sort = $request->query($tableKey.'_sort', 'events');
        $direction = strtolower((string) $request->query($tableKey.'_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, $allowed, true)) {
            $sort = 'events';
        }

        return [$sort, $direction];
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

    private function reportPresets(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This week',
            'last_week' => 'Last week',
            'this_month' => 'This month',
            'custom' => 'Custom',
        ];
    }

    private function reportFavoriteKeys(): array
    {
        return [
            'preset',
            'from',
            'to',
            'provider_id',
            'device_id',
            'project_id',
            'provider_account_id',
            'model',
            'metric',
            'q',
        ];
    }

    /**
     * @return array{preset: string, from: Carbon, to: Carbon}
     */
    private function reportDateRange(Request $request): array
    {
        $preset = array_key_exists($request->query('preset', 'this_week'), $this->reportPresets())
            ? $request->query('preset', 'this_week')
            : 'this_week';
        $now = now();

        return match ($preset) {
            'today' => [
                'preset' => $preset,
                'from' => $now->copy()->startOfDay(),
                'to' => $now->copy()->endOfDay(),
            ],
            'yesterday' => [
                'preset' => $preset,
                'from' => $now->copy()->subDay()->startOfDay(),
                'to' => $now->copy()->subDay()->endOfDay(),
            ],
            'last_week' => [
                'preset' => $preset,
                'from' => $now->copy()->subWeek()->startOfWeek(),
                'to' => $now->copy()->subWeek()->endOfWeek(),
            ],
            'this_month' => [
                'preset' => $preset,
                'from' => $now->copy()->startOfMonth(),
                'to' => $now->copy()->endOfDay(),
            ],
            'custom' => [
                'preset' => $preset,
                'from' => $this->parseReportDate($request->query('from'), $now->copy()->startOfMonth(), false),
                'to' => $this->parseReportDate($request->query('to'), $now->copy()->endOfDay(), true),
            ],
            default => [
                'preset' => 'this_week',
                'from' => $now->copy()->startOfWeek(),
                'to' => $now->copy()->endOfDay(),
            ],
        };
    }

    private function parseReportDate(?string $value, Carbon $fallback, bool $endOfDay): Carbon
    {
        if (! $value) {
            return $fallback;
        }

        try {
            $date = Carbon::parse($value);

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array<string, int|float>
     */
    private function reportTotals(object $row): array
    {
        $input = (int) ($row->input_tokens ?? 0);
        $cachedInput = (int) ($row->cached_input_tokens ?? 0);
        $cacheWrite = (int) ($row->cache_write_tokens ?? 0);
        $cacheRead = (int) ($row->cache_read_tokens ?? 0);
        $output = (int) ($row->output_tokens ?? 0);
        $reasoning = (int) ($row->reasoning_tokens ?? 0);
        $tool = (int) ($row->tool_tokens ?? 0);
        $unknown = (int) ($row->unknown_tokens ?? 0);
        $effectiveInput = max(0, $input - $cachedInput);
        $cached = $cachedInput + $cacheWrite + $cacheRead;
        $other = $reasoning + $tool + $unknown;

        return [
            'cost' => (float) ($row->total_cost ?? 0),
            'events' => (int) ($row->event_count ?? 0),
            'cached' => $cached,
            'input' => $effectiveInput,
            'output' => $output,
            'other' => $other,
            'total_tokens' => $cached + $effectiveInput + $output + $other,
        ];
    }

    private function reportChartRow(object $row, string $metric): array
    {
        $totals = $this->reportTotals($row);
        $tokenBase = max(1, $totals['total_tokens']);
        $cost = (float) $totals['cost'];
        $isCost = $metric === 'cost';

        $segments = collect([
            ['key' => 'cached', 'label' => 'Cached', 'tokens' => $totals['cached']],
            ['key' => 'input', 'label' => 'Input', 'tokens' => $totals['input']],
            ['key' => 'output', 'label' => 'Output', 'tokens' => $totals['output']],
            ['key' => 'other', 'label' => 'Other', 'tokens' => $totals['other']],
        ])->map(function ($segment) use ($tokenBase, $cost, $isCost) {
            $share = $segment['tokens'] / $tokenBase;

            return [
                'key' => $segment['key'],
                'label' => $segment['label'],
                'tokens' => $segment['tokens'],
                'share' => $share,
                'value' => $isCost ? $cost * $share : $segment['tokens'],
            ];
        })->all();

        return [
            'bucket' => $row->bucket,
            'label' => Carbon::parse($row->bucket)->format('M j'),
            'events' => $totals['events'],
            'cost' => $cost,
            'total_tokens' => $totals['total_tokens'],
            'value' => $isCost ? $cost : $totals['total_tokens'],
            'segments' => $segments,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reportGroupBy(Builder $baseQuery, string $groupColumn, ?string $joinTable = null, ?string $labelExpr = null): array
    {
        $query = clone $baseQuery;

        if ($joinTable !== null && $labelExpr !== null) {
            $query->leftJoin($joinTable, "{$joinTable}.id", '=', "usage_events.{$groupColumn}");
        }

        $select = [
            DB::raw('COUNT(*) as event_count'),
            DB::raw('SUM(input_tokens) as input_tokens'),
            DB::raw('SUM(output_tokens) as output_tokens'),
            DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
            DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
            DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
            DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
            DB::raw('SUM(tool_tokens) as tool_tokens'),
            DB::raw('SUM(unknown_tokens) as unknown_tokens'),
            DB::raw('SUM(official_api_cost_usd) as total_cost'),
        ];

        if ($labelExpr !== null) {
            $select[] = DB::raw("{$labelExpr} as label");
        } else {
            $select[] = DB::raw("usage_events.{$groupColumn} as label");
        }

        $results = $query->select($select)
            ->groupBy("usage_events.{$groupColumn}")
            ->orderByDesc(DB::raw('total_cost'))
            ->limit(50)
            ->get();

        return $results->map(function ($row) {
            $totals = $this->reportTotals($row);

            return [
                'label' => $row->label ?: 'Unknown',
                'events' => $totals['events'],
                'cost' => $totals['cost'],
                'cached' => $totals['cached'],
                'input' => $totals['input'],
                'output' => $totals['output'],
                'total_tokens' => $totals['total_tokens'],
            ];
        })->all();
    }

    private function perPage(Request $request, int $default): int
    {
        $value = (int) $request->query('per_page', $default);

        return in_array($value, [10, 25, 50, 100], true) ? $value : $default;
    }

    private function isDemoUser(): bool
    {
        $user = Auth::user();

        return $user && ($user->email === 'demo@metr.app' || $user->username === 'demo');
    }
}
