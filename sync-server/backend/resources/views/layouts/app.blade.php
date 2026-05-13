<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'MEtR Sync')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f6f7f9;
            --card: #fff;
            --text: #111827;
            --muted: #6b7280;
            --accent: #2563eb;
            --accent-soft: #dbeafe;
            --border: #e5e7eb;
            --success: #10b981;
            --success-soft: #d1fae5;
            --warning: #f59e0b;
            --warning-soft: #fef3c7;
            --danger: #ef4444;
            --danger-soft: #fee2e2;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0f19;
                --card: #111827;
                --text: #e5e7eb;
                --muted: #9ca3af;
                --accent: #60a5fa;
                --accent-soft: #1e3a5f;
                --border: #1f2937;
                --success: #34d399;
                --success-soft: #064e3b;
                --warning: #fbbf24;
                --warning-soft: #451a03;
                --danger: #f87171;
                --danger-soft: #450a0a;
            }
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .container { max-width: 1140px; margin: 0 auto; padding: 24px 20px; }
        nav {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 0 20px;
            display: flex;
            gap: 16px;
            align-items: center;
            justify-content: space-between;
            height: 56px;
        }
        nav a { color: var(--text); text-decoration: none; font-weight: 500; font-size: 14px; transition: color .15s; }
        nav a:hover { color: var(--accent); }
        nav .left { display: flex; gap: 20px; align-items: center; }
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 18px;
            letter-spacing: -0.02em;
        }
        .logo-mark {
            width: 28px; height: 28px;
            background: linear-gradient(135deg, var(--accent), #7c3aed);
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
        }
        h1 { font-size: 24px; font-weight: 700; margin: 0 0 20px; letter-spacing: -0.02em; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            transition: box-shadow .2s, transform .15s;
        }
        .card.hover:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            transform: translateY(-1px);
        }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); }
        th { font-weight: 600; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
        .muted { color: var(--muted); }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--accent);
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: opacity .15s, transform .1s;
        }
        .btn:hover { opacity: .9; }
        .btn:active { transform: scale(0.98); }
        .btn.secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn.secondary:hover { background: rgba(0,0,0,0.03); }
        @media (prefers-color-scheme: dark) { .btn.secondary:hover { background: rgba(255,255,255,0.05); } }
        form label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; color: var(--muted); }
        form input, form select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--card);
            color: var(--text);
            font-size: 14px;
            margin-bottom: 14px;
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        form input:focus, form select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .grid.two-col { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid.stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        @media (max-width: 768px) {
            .grid.two-col { grid-template-columns: 1fr; }
            .grid.stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .stat { position: relative; overflow: hidden; }
        .stat .value { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; }
        .stat .label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-top: 4px; }
        .stat .hint { font-size: 11px; color: var(--muted); margin-top: 6px; line-height: 1.4; }
        .flash {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            background: var(--success-soft);
            color: #064e3b;
            border: 1px solid #bbf7d0;
            font-size: 14px;
            font-weight: 500;
        }
        @media (prefers-color-scheme: dark) { .flash { color: #d1fae5; border-color: #059669; } }
        .tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--border); margin-bottom: 16px; }
        .tab-btn {
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 500;
            color: var(--muted);
            cursor: pointer;
            margin-bottom: -2px;
            font-family: inherit;
            transition: color .15s;
        }
        .tab-btn:hover { color: var(--text); }
        .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
        table td:first-child { min-width: 140px; word-break: break-word; }
        table td:nth-child(2), table td:nth-child(3), table td:nth-child(4) { white-space: nowrap; }
        .card table { table-layout: auto; }
        .card h3 { margin-top: 0; font-size: 16px; font-weight: 600; }
        .login-bg {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(ellipse at 30% 20%, rgba(37,99,235,0.08) 0%, transparent 50%),
                        radial-gradient(ellipse at 70% 80%, rgba(124,58,237,0.06) 0%, transparent 50%),
                        var(--bg);
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 8px 24px rgba(0,0,0,0.06);
        }
        .login-card .logo { justify-content: center; margin-bottom: 8px; }
        .login-card h1 { text-align: center; font-size: 20px; margin-bottom: 4px; }
        .login-card .subtitle { text-align: center; margin-bottom: 24px; }
        .login-card .btn { width: 100%; margin-top: 4px; padding: 10px; font-size: 15px; }
        .stat-accent { border-left: 3px solid var(--accent); }
        .stat-success { border-left: 3px solid var(--success); }
        .stat-warning { border-left: 3px solid var(--warning); }
        .stat-danger { border-left: 3px solid var(--danger); }
    </style>
</head>
<body>
    <nav>
        <div class="left">
            <a href="{{ auth()->check() ? '/dashboard' : '/download' }}" class="logo">
                <span class="logo-mark">M</span>
                MEtR
            </a>
            @auth
                <a href="/devices">Devices</a>
                <a href="/provider-accounts">Accounts</a>
                <a href="/subscriptions">Subscriptions</a>
                <a href="/projects">Projects</a>
                <a href="/pricing">Pricing</a>
                <a href="/settings">Settings</a>
            @else
                <a href="/download">Download</a>
            @endauth
        </div>
        @auth
            <form method="POST" action="/logout" style="margin:0;">@csrf<button class="btn secondary" style="padding:6px 14px;font-size:13px;">Logout</button></form>
        @else
            <a href="/login" class="btn secondary" style="padding:6px 14px;font-size:13px;">Login</a>
        @endauth
    </nav>
    <div class="container">
        @if(session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif
        @yield('content')
    </div>
</body>
</html>
