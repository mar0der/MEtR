<?php

namespace App\Http\Controllers;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    private function adminEmail(): string
    {
        return config('app.admin_email', env('ADMIN_EMAIL', ''));
    }

    private function adminPasswordHash(): string
    {
        return config('app.admin_password_hash', env('ADMIN_PASSWORD_HASH', ''));
    }

    private function checkAdmin(): void
    {
        if (session('is_admin')) {
            return;
        }

        if ($this->tryRememberLogin()) {
            return;
        }

        abort(redirect('/admin/login'));
    }

    private function tryRememberLogin(): bool
    {
        $cookie = request()->cookie('admin_remember');
        if (! $cookie) {
            return false;
        }

        $expected = hash('sha256', $this->adminEmail() . $this->adminPasswordHash() . config('app.key'));
        if (! hash_equals($expected, $cookie)) {
            return false;
        }

        session(['is_admin' => true]);

        return true;
    }

    private function setRememberCookie(): void
    {
        $value = hash('sha256', $this->adminEmail() . $this->adminPasswordHash() . config('app.key'));
        cookie()->queue('admin_remember', $value, 60 * 24 * 30); // 30 days
    }

    private function clearRememberCookie(): void
    {
        cookie()->queue(cookie()->forget('admin_remember'));
    }

    public function loginForm()
    {
        if (session('is_admin')) {
            return redirect('/admin');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $email = $this->adminEmail();
        $hash = $this->adminPasswordHash();

        if (! $email || ! $hash) {
            return back()->withErrors(['email' => 'Admin not configured.']);
        }

        if ($data['email'] !== $email || ! Hash::check($data['password'], $hash)) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }

        session(['is_admin' => true]);
        $request->session()->regenerate();

        if ($request->boolean('remember')) {
            $this->setRememberCookie();
        }

        return redirect('/admin');
    }

    public function logout(Request $request)
    {
        session()->forget('is_admin');
        $this->clearRememberCookie();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }

    public function dashboard()
    {
        $this->checkAdmin();

        $stats = DB::select(<<<'SQL'
            SELECT
                COUNT(DISTINCT user_id) as user_count,
                COUNT(*) as event_count,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(cached_input_tokens) as cached_input_tokens,
                SUM(cache_write_tokens) as cache_write_tokens,
                SUM(cache_read_tokens) as cache_read_tokens,
                SUM(reasoning_tokens) as reasoning_tokens,
                SUM(tool_tokens) as tool_tokens,
                SUM(unknown_tokens) as unknown_tokens
            FROM usage_events
        SQL)[0];

        $dbSize = DB::select("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = DATABASE()")[0]->size_mb ?? 0;

        $users = User::orderByDesc('created_at')->get()->map(function ($user) {
            $userStats = DB::select(<<<'SQL'
                SELECT
                    COUNT(*) as event_count,
                    SUM(input_tokens + output_tokens + cached_input_tokens + cache_write_tokens + cache_read_tokens + reasoning_tokens + tool_tokens + unknown_tokens) as total_tokens
                FROM usage_events
                WHERE user_id = ?
            SQL, [$user->id])[0];

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'created_at' => $user->created_at,
                'event_count' => $userStats->event_count ?? 0,
                'total_tokens' => $userStats->total_tokens ?? 0,
            ];
        });

        return view('admin.dashboard', [
            'stats' => $stats,
            'dbSize' => $dbSize,
            'users' => $users,
        ]);
    }
}
