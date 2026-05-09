<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'MEtR Sync')</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --text:#1f2937; --muted:#6b7280; --accent:#2563eb; --border:#e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0b0f19; --card:#111827; --text:#e5e7eb; --muted:#9ca3af; --accent:#60a5fa; --border:#1f2937; } }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin:0; background:var(--bg); color:var(--text); line-height:1.5; }
        .container { max-width:1100px; margin:0 auto; padding:20px; }
        nav { background:var(--card); border-bottom:1px solid var(--border); padding:12px 20px; display:flex; gap:16px; align-items:center; justify-content:space-between; }
        nav a { color:var(--text); text-decoration:none; font-weight:500; }
        nav a:hover { color:var(--accent); }
        nav .left { display:flex; gap:16px; align-items:center; }
        h1 { font-size:22px; margin:0 0 16px; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:18px; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); }
        th { font-weight:600; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .muted { color:var(--muted); }
        .btn { display:inline-block; padding:8px 14px; background:var(--accent); color:#fff; border-radius:6px; text-decoration:none; font-size:14px; border:none; cursor:pointer; }
        .btn.secondary { background:transparent; color:var(--text); border:1px solid var(--border); }
        .btn:hover { opacity:.9; }
        form label { display:block; font-size:13px; font-weight:600; margin-bottom:4px; color:var(--muted); }
        form input, form select { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:6px; background:var(--card); color:var(--text); font-size:14px; margin-bottom:12px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
        .grid.two-col { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .grid.stats-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
        @media (max-width: 768px) {
            .grid.two-col { grid-template-columns:1fr; }
            .grid.stats-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        }
        .stat .value { font-size:22px; font-weight:700; }
        .stat .label { font-size:12px; color:var(--muted); text-transform:uppercase; }
        .flash { padding:10px 14px; border-radius:6px; margin-bottom:12px; background:#dcfce7; color:#14532d; border:1px solid #bbf7d0; }
        .tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:16px; }
        .tab-btn { background:transparent; border:none; border-bottom:2px solid transparent; padding:10px 18px; font-size:14px; font-weight:500; color:var(--muted); cursor:pointer; margin-bottom:-2px; }
        .tab-btn:hover { color:var(--text); }
        .tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); }
        table td:first-child { min-width:140px; word-break:break-word; }
        table td:nth-child(2), table td:nth-child(3), table td:nth-child(4) { white-space:nowrap; }
        .card table { table-layout:auto; }
        .card h3 { margin-top:0; font-size:16px; }
    </style>
</head>
<body>
    @auth
    <nav>
        <div class="left">
            <a href="/dashboard" style="font-weight:700;">MEtR Sync</a>
            <a href="/devices">Devices</a>
            <a href="/provider-accounts">Accounts</a>
            <a href="/subscriptions">Subscriptions</a>
            <a href="/projects">Projects</a>
            <a href="/pricing">Pricing</a>
        </div>
        <form method="POST" action="/logout" style="margin:0;">@csrf<button class="btn secondary" style="padding:6px 12px;font-size:13px;">Logout</button></form>
    </nav>
    @endauth
    <div class="container">
        @if(session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif
        @yield('content')
    </div>
</body>
</html>
