<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Prodelya')</title>
    <meta name="description" content="@yield('meta_description', 'Promosyon, baskı ve sipariş operasyonlarını tekliften teslimata tek panelde yönetin. Product Data Hub, müşteri portalı, grafik onayı ve operasyon takibi Prodelya’da.')">
    <style>
        :root { --bg:#f4f7fb; --card:#fff; --text:#162033; --muted:#64748b; --border:#dde5ef; --strong:#c8d4e4; --primary:#2563eb; --primary-dark:#1d4ed8; --primary-soft:#e9f1ff; --success:#15803d; --success-soft:#ecfdf3; --amber:#d97706; --amber-soft:#fff7ed; --shadow:0 16px 40px rgba(15,23,42,.08); }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { margin:0; font-family:Arial, Helvetica, sans-serif; background:linear-gradient(180deg,#ffffff 0%,#f7f9fc 48%,#eef3f8 100%); color:var(--text); }
        a { color:inherit; text-decoration:none; }
        .container { max-width:1180px; margin:0 auto; padding:0 20px; }
        .page { min-height:100vh; padding-bottom:12px; }
        .topbar-wrap { position:sticky; top:0; z-index:30; border-bottom:1px solid rgba(221,229,239,.95); background:rgba(255,255,255,.9); backdrop-filter:blur(14px); }
        .topbar { min-height:74px; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:10px 0; }
        .brand { display:flex; align-items:center; gap:12px; font-weight:700; }
        .brand-badge { width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; background:linear-gradient(135deg,#60a5fa,#2563eb); color:#fff; box-shadow:0 10px 24px rgba(37,99,235,.22); }
        .brand-copy { font-size:19px; letter-spacing:-.02em; color:#101827; }
        .brand-copy small { display:block; color:var(--muted); font-weight:400; margin-top:2px; font-size:12px; }
        .nav { display:flex; flex-wrap:nowrap; gap:8px; }
        .nav a { padding:8px 10px; border-radius:8px; color:#42536a; font-size:13px; font-weight:700; }
        .nav a:hover { background:var(--primary-soft); color:var(--primary); }
        .actions { display:flex; flex-wrap:nowrap; gap:10px; align-items:center; }
        .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:10px 16px; border-radius:8px; border:1px solid var(--strong); background:#fff; font-size:14px; font-weight:600; cursor:pointer; }
        .btn-primary { background:var(--success); border-color:var(--success); color:#fff; box-shadow:0 10px 22px rgba(21,128,61,.18); }
        .btn-success { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:0 10px 22px rgba(37,99,235,.18); }
        .btn-light { background:#fff; color:var(--text); }
        .btn-amber { background:var(--amber); border-color:var(--amber); color:#fff; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow); }
        .hero { padding:44px 0 24px; background:radial-gradient(circle at top left, rgba(37,99,235,.12), transparent 32%); }
        .eyebrow { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; background:var(--primary-soft); color:var(--primary); font-size:12px; font-weight:700; margin-bottom:14px; border:1px solid #c9dafd; }
        h1 { margin:0 0 14px; font-size:46px; line-height:1.08; letter-spacing:-.04em; font-weight:400; }
        h2 { margin:0 0 10px; font-size:30px; line-height:1.15; letter-spacing:-.03em; font-weight:400; }
        h3 { font-weight:400; }
        p.lead { margin:0 0 22px; color:var(--muted); font-size:17px; line-height:1.7; max-width:760px; }
        .grid { display:grid; gap:18px; }
        .grid-4 { grid-template-columns:repeat(4,minmax(0,1fr)); }
        .grid-3 { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .grid-2 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .feature, .package-card, .side-card { padding:20px; }
        .feature h3, .package-card h3, .side-card h3 { margin:0 0 10px; font-size:18px; }
        .feature p, .package-card p, .side-card p { margin:0; color:var(--muted); line-height:1.6; font-size:14px; }
        .section-title { margin:0 0 8px; font-size:24px; }
        .section-subtitle { margin:0 0 18px; color:var(--muted); line-height:1.6; }
        .section { padding:22px 0; scroll-margin-top:104px; }
        .section-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:16px; }
        .section-kicker { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border:1px solid #d5e5f7; border-radius:8px; background:#f7fbff; color:var(--primary); font-size:12px; font-weight:700; margin-bottom:8px; }
        .stat-row { display:grid; gap:12px; grid-template-columns:repeat(4,minmax(0,1fr)); margin-top:18px; }
        .stat-box { padding:16px; border:1px solid var(--border); border-radius:12px; background:#fbfdff; box-shadow:0 8px 24px rgba(15,23,42,.04); }
        .stat-box b { display:block; font-size:18px; color:var(--primary); margin-bottom:4px; }
        .stat-box span { color:var(--muted); font-size:13px; line-height:1.5; }
        .timeline { display:grid; gap:12px; grid-template-columns:repeat(5,minmax(0,1fr)); }
        .timeline-step { position:relative; padding:18px; background:#fbfdff; }
        .timeline-step strong { display:inline-flex; width:32px; height:32px; align-items:center; justify-content:center; border-radius:10px; background:var(--primary); color:#fff; margin-bottom:10px; }
        .timeline-step h3 { margin:0 0 8px; font-size:16px; }
        .timeline-step p { margin:0; color:var(--muted); font-size:14px; line-height:1.55; }
        .soft-note { padding:14px 16px; border-radius:10px; border:1px solid #d9e4f3; background:#f7fbff; color:#415163; font-size:14px; line-height:1.6; }
        .soft-note.amber { border-color:#f3d7b0; background:var(--amber-soft); color:#7b4b0a; }
        .soft-note.success { border-color:#bbf7d0; background:var(--success-soft); color:#166534; }
        .pill-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
        .pill { display:inline-flex; padding:6px 10px; border-radius:8px; border:1px solid #d8e4f2; background:#fbfdff; color:#415163; font-size:12px; font-weight:700; }
        .form-shell { display:grid; gap:22px; grid-template-columns:minmax(0,2fr) minmax(280px,1fr); }
        .form-card { padding:28px; }
        .side-stack { display:grid; gap:18px; }
        .form-grid { display:grid; gap:16px; grid-template-columns:repeat(2,minmax(0,1fr)); }
        .form-grid .full { grid-column:1 / -1; }
        label { display:block; margin-bottom:6px; font-size:14px; font-weight:600; }
        input, select, textarea { width:100%; border:1px solid var(--border); border-radius:5px; padding:10px 12px; font:inherit; background:#fff; color:var(--text); }
        textarea { min-height:120px; resize:vertical; }
        .help, .small-muted { color:var(--muted); font-size:13px; line-height:1.5; }
        .module-list { display:grid; gap:10px; grid-template-columns:repeat(2,minmax(0,1fr)); }
        .module-item { border:1px solid var(--border); border-radius:8px; padding:12px; background:#fff; }
        .module-item strong { display:block; margin-bottom:4px; font-size:14px; }
        .alert { border-radius:6px; padding:12px 14px; margin-bottom:16px; border:1px solid transparent; font-size:14px; }
        .alert-success { background:var(--success-soft); border-color:#cdebd8; color:#215d3a; }
        .alert-danger { background:#fff1f2; border-color:#fecdd3; color:#9f1239; }
        .package-pill { display:inline-flex; padding:4px 8px; border-radius:4px; background:var(--primary-soft); color:var(--primary); font-size:12px; font-weight:700; margin-bottom:10px; }
        .info-note { padding:14px; border-radius:6px; border:1px solid #d8e6d8; background:#f6fbf6; color:#2f5e43; font-size:14px; line-height:1.6; }
        .anchor-offset { scroll-margin-top:104px; }
        .hero-grid { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr); gap:32px; align-items:center; }
        .hero-panel { padding:20px; }
        .panel-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; padding-bottom:14px; border-bottom:1px solid var(--border); }
        .panel-title { font-size:17px; color:#162033; margin-bottom:4px; }
        .panel-sub { margin:0; color:var(--muted); font-size:12.5px; }
        .flow { display:grid; gap:10px; margin-top:14px; }
        .flow-row { display:grid; grid-template-columns:34px minmax(0,1fr) auto; gap:10px; align-items:center; border:1px solid var(--border); background:#fbfdff; border-radius:12px; padding:10px; }
        .flow-no { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:var(--primary-soft); color:var(--primary); font-size:13px; font-weight:700; }
        .flow-title { color:#1f2d45; font-size:14px; font-weight:700; }
        .flow-desc { color:var(--muted); font-size:12px; }
        .hero-actions { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
        .hero-support { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
        .hero-support a { color:#42536a; font-size:13px; font-weight:700; }
        .hero-support a:hover { color:var(--primary); }
        .feature-list { display:grid; gap:8px; margin-top:14px; }
        .feature-item { display:flex; gap:8px; align-items:flex-start; color:#44536a; font-size:13px; }
        .tick { width:18px; height:18px; border-radius:6px; background:var(--success-soft); color:var(--success); display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; font-size:11px; margin-top:1px; }
        .module-grid { display:grid; gap:14px; grid-template-columns:repeat(2,minmax(0,1fr)); }
        .price-meta { color:var(--muted); font-size:13px; line-height:1.55; }
        .footer { margin-top:28px; padding:24px 0 34px; border-top:1px solid var(--border); background:#fff; }
        .footer-row { display:flex; justify-content:space-between; gap:18px; align-items:center; flex-wrap:wrap; color:var(--muted); font-size:13px; }
        .footer-links { display:flex; flex-wrap:wrap; gap:12px; }
        .footer-links span, .footer-links a { color:#556477; }
        @media (max-width:1180px) { .topbar { flex-wrap:wrap; } .nav { order:3; width:100%; flex-wrap:wrap; } .actions { margin-left:auto; } }
        @media (max-width:960px) { .grid-4, .grid-3, .grid-2, .form-shell, .form-grid, .module-list, .stat-row, .timeline, .module-grid, .hero-grid { grid-template-columns:1fr; } h1 { font-size:34px; } h2 { font-size:25px; } .topbar { min-height:auto; padding:14px 0; flex-direction:column; align-items:flex-start; } .nav { width:100%; flex-wrap:wrap; } .actions { width:100%; flex-wrap:wrap; margin-left:0; } .hero { padding-top:42px; } }
    </style>
</head>
<body>
    <div class="topbar-wrap">
        <div class="container">
            <div class="topbar">
                <a href="{{ route('marketing.home') }}" class="brand">
                    <span class="brand-badge">PR</span>
                    <span class="brand-copy">Prodelya<small>Promosyon ve baskı operasyon platformu</small></span>
                </a>
                <nav class="nav">
                    <a href="{{ route('marketing.home') }}#ozellikler">Özellikler</a>
                    <a href="{{ route('marketing.home') }}#is-akisi">İş Akışı</a>
                    <a href="{{ route('marketing.home') }}#product-hub">Product Data Hub</a>
                    <a href="{{ route('marketing.home') }}#paketler">Paketler</a>
                    <a href="{{ route('marketing.home') }}#basvuru">Demo / Ücretsiz Dene</a>
                </nav>
                <div class="actions">
                    <a href="{{ route('login') }}" class="btn btn-light">Abone Firma Girişi</a>
                    <a href="{{ route('marketing.register-interest') }}" class="btn btn-primary">1 Ay Ücretsiz Dene</a>
                    <a href="{{ route('marketing.demo-request') }}" class="btn btn-success">Demo Talep Et</a>
                </div>
            </div>
        </div>
    </div>
    <div class="page">
        <div class="container">
            @yield('content')
        </div>
    </div>
    <footer class="footer">
        <div class="container footer-row">
            <div>Prodelya; promosyon, baskı, katalog ve sipariş operasyonlarını tek panelde yöneten merkezi SaaS altyapısıdır.</div>
            <div class="footer-links">
                <span>Yasal sayfalar yakında yayımlanacaktır.</span>
                <a href="{{ route('marketing.demo-request') }}">Demo Talep Et</a>
            </div>
        </div>
    </footer>
</body>
</html>
