<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Models\ModelPrice;
use App\Models\Project;
use App\Models\ProviderAccount;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WebController extends Controller
{
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

        $query = UsageEvent::where('usage_events.user_id', $user->id);

        if ($request->filled('from')) {
            $query->where('usage_events.timestamp', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('usage_events.timestamp', '<=', $request->input('to'));
        }

        $summary = (clone $query)->select([
            DB::raw('SUM(input_tokens) as input_tokens'),
            DB::raw('SUM(output_tokens) as output_tokens'),
            DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
            DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
            DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
            DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
            DB::raw('SUM(tool_tokens) as tool_tokens'),
            DB::raw('SUM(unknown_tokens) as unknown_tokens'),
            DB::raw('COUNT(*) as event_count'),
            DB::raw('SUM(CASE WHEN official_api_cost_usd IS NULL THEN 1 ELSE 0 END) as missing_price_count'),
            DB::raw('SUM(CASE WHEN provider_account_id IS NULL THEN 1 ELSE 0 END) as unknown_account_count'),
            DB::raw('SUM(official_api_cost_usd) as total_cost'),
        ])->first();

        $byDevice = (clone $query)
            ->leftJoin('devices', 'devices.id', '=', 'usage_events.device_id')
            ->select([
                'devices.display_name as label',
                'devices.platform as meta',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(official_api_cost_usd) as total_cost'),
                DB::raw('SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens'),
            ])
            ->groupBy('devices.id', 'devices.display_name', 'devices.platform')
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
            ->groupBy('projects.manual_name', 'projects.canonical_name')
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

        return view('dashboard', [
            'summary' => $summary,
            'byDevice' => $byDevice,
            'byProject' => $byProject,
            'byProviderAccount' => $byProviderAccount,
            'byModel' => $byModel,
        ]);
    }

    public function devices()
    {
        return view('devices', [
            'devices' => Device::where('user_id', Auth::id())->get(),
        ]);
    }

    public function providerAccounts()
    {
        return view('provider-accounts', [
            'accounts' => ProviderAccount::where('user_id', Auth::id())->with('provider')->get(),
        ]);
    }

    public function subscriptions()
    {
        return view('subscriptions', [
            'subscriptions' => Subscription::where('user_id', Auth::id())->with('providerAccount')->get(),
        ]);
    }

    public function projects()
    {
        return view('projects', [
            'projects' => Project::where('user_id', Auth::id())->withCount('projectRoots')->get(),
        ]);
    }

    public function pricing()
    {
        return view('pricing', [
            'prices' => ModelPrice::with('provider')->orderBy('provider_id')->orderBy('model')->get(),
        ]);
    }
}
