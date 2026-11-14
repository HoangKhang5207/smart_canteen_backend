<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @hasSection('auto_refresh')
        <meta http-equiv="refresh" content="10">
    @endif
    <title>@yield('title', 'Quản trị Smart Canteen')</title>
    <style>
        :root {
            --brand-50: #EEF8EB;
            --brand-100: #D8F0D2;
            --brand-500: #5DBE46;
            --brand-700: #2E7D32;
            --brand-800: #236B27;
            --brand-900: #14532D;
            --bg-page: #F6F8F5;
            --surface: #FFFFFF;
            --border: #E2E8DF;
            --text-main: #111827;
            --text-muted: #4B5563;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
        }
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-main);
        }
        .brand img {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--brand-100);
            object-fit: cover;
        }
        .brand-title { font-weight: 800; font-size: 1.05rem; display: block; }
        .brand-sub { font-size: 0.75rem; color: var(--brand-700); font-weight: 600; }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .nav-link {
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.5rem 0.85rem;
            border-radius: 8px;
        }
        .nav-link.active, .nav-link:hover {
            background: var(--brand-50);
            color: var(--brand-700);
        }
        .container {
            max-width: 1180px;
            margin: 1.5rem auto;
            padding: 0 1.25rem;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }
        .grid-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem 1.25rem;
        }
        .stat-label { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; }
        .stat-value { font-size: 1.55rem; font-weight: 800; margin-top: 0.25rem; color: var(--text-main); }
        .stat-value.brand { color: var(--brand-700); }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.5rem 0.9rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary { background: var(--brand-700); color: #FFFFFF; }
        .btn-primary:hover { background: var(--brand-800); }
        .btn-secondary { background: #F3F4F6; color: #1F2937; border-color: #D1D5DB; }
        .btn-danger { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .badge-PENDING { background: #FEF3C7; color: #92400E; }
        .badge-PREPARING { background: #DBEAFE; color: #1E40AF; }
        .badge-READY { background: #D8F0D2; color: #14532D; }
        .badge-COMPLETED { background: #F3F4F6; color: #374151; }
        .badge-CANCELLED { background: #FEE2E2; color: #991B1B; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            text-align: left;
            padding: 0.75rem 0.65rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        th { font-size: 0.78rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.03em; }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .alert-success { background: var(--brand-50); color: var(--brand-900); border: 1px solid var(--brand-100); }
        .alert-error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"], input[type="url"], select, textarea {
            padding: 0.55rem 0.75rem;
            border: 1px solid #D1D5DB;
            border-radius: 8px;
            font-size: 0.9rem;
            width: 100%;
        }
        .flex-between { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .inline-actions { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    </style>
</head>
<body>
    @auth
    <header class="topbar">
        <a href="{{ route('admin.dashboard') }}" class="brand">
            <img src="/images/canteen-logo.jpg" alt="Smart Canteen Logo">
            <div>
                <span class="brand-title">Smart Canteen Admin</span>
                <span class="brand-sub">Hệ thống điều phối bếp & thực đơn</span>
            </div>
        </a>
        <nav class="nav-links" aria-label="Điều hướng quản trị">
            <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Tổng quan hôm nay</a>
            <a href="{{ route('admin.orders.index') }}" class="nav-link {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">Điều phối đơn hàng</a>
            <a href="{{ route('admin.products.index') }}" class="nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">Món ăn</a>
            <a href="{{ route('admin.categories.index') }}" class="nav-link {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">Danh mục</a>
        </nav>
        <div class="inline-actions">
            <span style="font-size: 0.82rem; color: var(--text-muted);">
                Xin chào, <strong>{{ auth()->user()->name }}</strong> ({{ strtoupper(auth()->user()->role->value ?? auth()->user()->role) }})
            </span>
            <form method="POST" action="{{ route('admin.logout') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-secondary">Đăng xuất</button>
            </form>
        </div>
    </header>
    @endauth

    <main class="container">
        @if (session('status'))
            <div role="status" class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div role="alert" class="alert alert-error">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="alert alert-error">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
