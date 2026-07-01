@extends('layouts.prodelya-admin')

@section('title', 'Yönetim Paneli | Prodelya Admin')
@section('hide_side_summary', '1')
@section('page_topbar_hidden', '1')

@php
    $cards = collect($dashboard['cards'] ?? []);
    $queueItems = collect($dashboard['queue_items'] ?? []);
    $quickLinks = collect($dashboard['quick_links'] ?? []);
    $todayActions = collect($dashboard['today_actions'] ?? []);
    $quickStartSteps = collect($dashboard['quick_start'] ?? []);
    $readinessChecklist = collect($dashboard['readiness_checklist'] ?? []);
    $packageSummary = $dashboard['package_summary'] ?? [];
    $catalogSummary = $dashboard['catalog_summary'] ?? [];
    $summary = $dashboard['queue_summary'] ?? [];
    $quickStartCompleted = $quickStartSteps->isNotEmpty() && $quickStartSteps->every(fn (array $step) => ($step['status'] ?? null) === 'Hazır');

    $cardMap = $cards->keyBy('title');

    $metricCards = [
        [
            'title' => 'Açık Sipariş',
            'count' => ($summary['active_orders'] ?? 0),
            'hint' => 'Operasyonda takip edilen aktif siparişler',
            'class' => 'info',
        ],
        [
            'title' => 'Aksiyon Bekleyen',
            'count' => ($summary['pending_actions_total'] ?? 0),
            'hint' => 'Gerçek sipariş/iş akışına bağlı bekleyen işlemler',
            'class' => 'warn',
        ],
        [
            'title' => 'Bloklu Operasyon',
            'count' => ($summary['blocked_items'] ?? 0),
            'hint' => 'Grafik / tedarik / setup engeli',
            'class' => 'danger',
        ],
        [
            'title' => 'Müşteri Yanıtı',
            'count' => ($summary['awaiting_customer'] ?? 0),
            'hint' => 'Teklif veya grafik onayı',
            'class' => 'ok',
        ],
        [
            'title' => 'Bildirim Hatası',
            'count' => ($cardMap['Başarısız Bildirimler']['count'] ?? 0),
            'hint' => 'Kontrol edilmesi gereken log',
            'class' => (($cardMap['Başarısız Bildirimler']['count'] ?? 0) > 0) ? 'danger' : 'info',
        ],
    ];

    $processSteps = [
        [
            'title' => 'Teklif',
            'count' => ($cardMap['Onay Bekleyen Teklifler']['count'] ?? 0),
            'badge' => (($cardMap['Onay Bekleyen Teklifler']['count'] ?? 0) > 0) ? ['text' => 'Bekliyor', 'class' => 'blue'] : ['text' => 'Temiz', 'class' => 'green'],
            'note' => 'Müşteri onayı bekleyen teklifler.',
        ],
        [
            'title' => 'Sipariş',
            'count' => ($cardMap['Siparişe Çevrilebilir Teklifler']['count'] ?? 0),
            'badge' => (($cardMap['Siparişe Çevrilebilir Teklifler']['count'] ?? 0) > 0) ? ['text' => 'Hazır', 'class' => 'green'] : ['text' => 'Temiz', 'class' => 'green'],
            'note' => 'Siparişe çevrilebilir teklifler.',
        ],
        [
            'title' => 'Grafik',
            'count' => ($cardMap['Grafik Bekleyen İşler']['count'] ?? 0),
            'badge' => (($cardMap['Grafik Bekleyen İşler']['count'] ?? 0) > 0) ? ['text' => 'Bekliyor', 'class' => 'amber'] : ['text' => 'Temiz', 'class' => 'green'],
            'note' => 'Görsel veya revize bekleyen işler.',
        ],
        [
            'title' => 'Müşteri Onayı',
            'count' => ($cardMap['Müşteri Grafik Onayı Bekleyenler']['count'] ?? 0),
            'badge' => (($cardMap['Müşteri Grafik Onayı Bekleyenler']['count'] ?? 0) > 0) ? ['text' => 'Bekliyor', 'class' => 'purple'] : ['text' => 'Temiz', 'class' => 'green'],
            'note' => 'Müşteri grafik onayı bekleyen işler.',
        ],
        [
            'title' => 'Tedarik',
            'count' => ($cardMap['Tedarik Bekleyen İşler']['count'] ?? 0),
            'badge' => (($cardMap['Tedarik Bekleyen İşler']['count'] ?? 0) > 0) ? ['text' => 'Bekliyor', 'class' => 'amber'] : ['text' => 'Temiz', 'class' => 'green'],
            'note' => 'Talep veya ürün gelişi bekleyen kalemler.',
        ],
        [
            'title' => 'Üretim',
            'count' => ($cardMap['Üretime Hazır / Bloklu Üretimler']['count'] ?? 0),
            'badge' => (($cardMap['Üretime Hazır / Bloklu Üretimler']['count'] ?? 0) > 0) ? ['text' => 'Bloklu', 'class' => 'red'] : ['text' => 'Hazır', 'class' => 'green'],
            'note' => 'Hazır veya bloklu üretim işleri.',
        ],
        [
            'title' => 'Teslimat',
            'count' => ($cardMap['Teslimat Bekleyen İşler']['count'] ?? 0),
            'badge' => (($cardMap['Teslimat Bekleyen İşler']['count'] ?? 0) > 0) ? ['text' => 'Bekliyor', 'class' => 'amber'] : ['text' => 'Temiz', 'class' => 'green'],
            'note' => 'Hazırlanacak veya tamamlanacak işler.',
        ],
        [
            'title' => 'Finans',
            'count' => '—',
            'badge' => ['text' => 'Gizli', 'class' => ''],
            'note' => 'Detay bu ekranda gösterilmez.',
        ],
    ];

    $priorityCards = collect([
        $cardMap['Tedarik Bekleyen İşler'] ?? null,
        [
            'title' => 'Bloklu Üretimler',
            'count' => ($summary['blocked_items'] ?? 0),
            'description' => 'Grafik, tedarik veya setup nedeniyle üretime başlayamayan işler.',
            'tone' => ($summary['blocked_items'] ?? 0) > 0 ? 'red' : 'green',
            'cta_label' => 'Üretim Aç',
            'cta_url' => route('admin.productions.index', ['pool' => 'preparation']),
        ],
        $cardMap['Teslimat Bekleyen İşler'] ?? null,
    ])->filter()->values();

    $controlCards = collect([
        $cardMap['Onay Bekleyen Teklifler'] ?? null,
        $cardMap['Grafik Bekleyen İşler'] ?? null,
        [
            'title' => 'Siparişe Çevrilebilir Teklifler',
            'count' => ($cardMap['Siparişe Çevrilebilir Teklifler']['count'] ?? 0),
            'description' => $cardMap['Siparişe Çevrilebilir Teklifler']['description'] ?? 'Onay almış ve siparişe hazır teklifler.',
            'tone' => $cardMap['Siparişe Çevrilebilir Teklifler']['tone'] ?? 'green',
            'cta_label' => $cardMap['Siparişe Çevrilebilir Teklifler']['cta_label'] ?? 'Siparişe Çevirilecekler',
            'cta_url' => $cardMap['Siparişe Çevrilebilir Teklifler']['cta_url'] ?? route('admin.promotion-quotes.index', ['status' => 'approved']),
        ],
        $cardMap['Başarısız Bildirimler'] ?? null,
    ])->filter()->values();

    $moduleShortcutMap = [
        'Teklifler' => route('admin.promotion-quotes.index'),
        'Siparişler' => route('admin.orders.index'),
        'Grafik' => route('admin.graphics.index'),
        'Tedarik' => route('admin.procurements.index'),
        'Üretim' => route('admin.productions.index'),
        'Teslimat' => route('admin.deliveries.index'),
        'Bildirimler' => route('admin.notifications.logs.index'),
        'Portal' => route('admin.settings'),
    ];

    $toneBadgeMap = [
        'blue' => 'blue',
        'green' => 'green',
        'amber' => 'amber',
        'red' => 'red',
        'purple' => 'purple',
        'gray' => '',
    ];

    $taskToneMap = [
        'today' => 'green',
        'urgent' => 'red',
        'blocked' => 'amber',
        'customer' => 'blue',
    ];

    $showSuperAdminShortcut = (bool) auth()->user()?->isPlatformAdmin() && \Illuminate\Support\Facades\Route::has('admin.super.dashboard');
    $superAdminDashboardUrl = $showSuperAdminShortcut ? route('admin.super.dashboard') : null;
@endphp

@section('content')
    <style>
        .pd-dashboard {
            --line: #e4e8ef;
            --line-soft: #eef2f7;
            --muted: #657184;
            --muted-2: #8b96a8;
            --text: #172033;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --blue: #2563eb;
            --blue-soft: #eef5ff;
            --green: #16a34a;
            --green-soft: #eaf8ef;
            --amber: #d97706;
            --amber-soft: #fff7e6;
            --red: #dc2626;
            --red-soft: #fff1f2;
            --purple: #7c3aed;
            --purple-soft: #f3efff;
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .pd-dashboard .section-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 0 0 8px;
        }

        .pd-dashboard .section-label h2 {
            margin: 0;
            font-size: 14px;
            letter-spacing: -.01em;
        }

        .pd-dashboard .section-label p {
            margin: 2px 0 0;
            color: var(--muted);
            font-size: 11px;
        }

        .pd-dashboard .link-mini {
            color: var(--blue);
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .pd-dashboard .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 16px 18px;
            margin-bottom: 12px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .pd-dashboard .hero h1 {
            margin: 0 0 4px;
            font-size: 22px;
            letter-spacing: -.02em;
            font-weight: 700;
        }

        .pd-dashboard .hero p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
            max-width: 760px;
        }

        .pd-dashboard .hero-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pd-dashboard .hero-actions .pd-btn {
            white-space: nowrap;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 10px;
            font-weight: 600;
            box-shadow: none;
        }

        .pd-dashboard .btn-soft {
            background: var(--surface-soft);
        }

        .pd-dashboard .top-summary {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .pd-dashboard .setup-grid {
            display: grid;
            gap: 12px;
            margin-bottom: 12px;
        }

        .pd-dashboard .setup-grid {
            grid-template-columns: minmax(0, 1.2fr) minmax(0, .9fr) minmax(0, .9fr) minmax(0, 1fr);
        }

        .pd-dashboard .kpi {
            min-height: 84px;
            padding: 12px;
            background: var(--surface);
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .035);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .pd-dashboard .kpi .name {
            font-size: 11px;
            font-weight: 600;
            color: #475569;
        }

        .pd-dashboard .kpi .num {
            margin: 7px 0 5px;
            font-size: 24px;
            line-height: 1;
            font-weight: 700;
            letter-spacing: -.03em;
        }

        .pd-dashboard .kpi .hint {
            font-size: 11px;
            color: var(--muted);
        }

        .pd-dashboard .panel-list,
        .pd-dashboard .checklist,
        .pd-dashboard .mini-stats,
        .pd-dashboard .step-list {
            display: grid;
            gap: 10px;
        }

        .pd-dashboard .panel-list {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .pd-dashboard .checklist {
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .pd-dashboard .mini-stats {
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }

        .pd-dashboard .list-card,
        .pd-dashboard .check-card,
        .pd-dashboard .stat-card,
        .pd-dashboard .step-card {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            background: var(--surface-soft);
            padding: 12px;
        }

        .pd-dashboard .list-card h3,
        .pd-dashboard .check-card h3,
        .pd-dashboard .stat-card h3,
        .pd-dashboard .step-card h3 {
            margin: 0 0 4px;
            font-size: 13px;
            font-weight: 700;
        }

        .pd-dashboard .list-card p,
        .pd-dashboard .check-card p,
        .pd-dashboard .stat-card p,
        .pd-dashboard .step-card p {
            margin: 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.45;
        }

        .pd-dashboard .list-card .count,
        .pd-dashboard .stat-card .count {
            margin: 8px 0 6px;
            font-size: 23px;
            font-weight: 700;
        }

        .pd-dashboard .check-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }

        .pd-dashboard .step-card {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }

        .pd-dashboard .step-no {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--blue-soft);
            color: var(--blue);
            font-weight: 700;
            font-size: 12px;
        }

        .pd-dashboard .micro-list {
            display: grid;
            gap: 6px;
            margin-top: 10px;
        }

        .pd-dashboard .micro-list span {
            font-size: 11px;
            color: var(--muted);
        }

        .pd-dashboard .kpi.warn {
            border-left: 3px solid var(--amber);
        }

        .pd-dashboard .kpi.danger {
            border-left: 3px solid var(--red);
        }

        .pd-dashboard .kpi.ok {
            border-left: 3px solid var(--green);
        }

        .pd-dashboard .kpi.info {
            border-left: 3px solid var(--blue);
        }

        .pd-dashboard .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 10.5px;
            line-height: 1.1;
            font-weight: 600;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .pd-dashboard .badge.green {
            background: var(--green-soft);
            color: #166534;
            border-color: #ccefd7;
        }

        .pd-dashboard .badge.amber {
            background: var(--amber-soft);
            color: #92400e;
            border-color: #fde2b8;
        }

        .pd-dashboard .badge.red {
            background: var(--red-soft);
            color: #991b1b;
            border-color: #fecdd3;
        }

        .pd-dashboard .badge.blue {
            background: var(--blue-soft);
            color: #1e40af;
            border-color: #dbeafe;
        }

        .pd-dashboard .badge.purple {
            background: var(--purple-soft);
            color: #5b21b6;
            border-color: #e9ddff;
        }

        .pd-dashboard .flow {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }

        .pd-dashboard .flow-step {
            position: relative;
            min-height: 100px;
            padding: 11px 10px;
            background: var(--surface);
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .035);
        }

        .pd-dashboard .flow-step::after {
            content: "";
            position: absolute;
            top: 42px;
            right: -7px;
            width: 8px;
            height: 1px;
            background: #cbd5e1;
        }

        .pd-dashboard .flow-step:last-child::after {
            display: none;
        }

        .pd-dashboard .flow-title {
            margin-bottom: 7px;
            color: #334155;
            font-size: 11px;
            font-weight: 700;
        }

        .pd-dashboard .flow-num {
            margin-bottom: 7px;
            font-size: 21px;
            line-height: 1;
            font-weight: 700;
        }

        .pd-dashboard .flow-note {
            margin-top: 7px;
            color: var(--muted);
            font-size: 10.5px;
            line-height: 1.35;
        }

        .pd-dashboard .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) 360px;
            gap: 12px;
            align-items: start;
        }

        .pd-dashboard .left-stack,
        .pd-dashboard .right-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .pd-dashboard .right-stack {
            position: sticky;
            top: 10px;
            align-self: start;
        }

        .pd-dashboard .panel {
            padding: 14px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .pd-dashboard .action-board {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 9px;
        }

        .pd-dashboard .action-card {
            min-height: 120px;
            padding: 12px;
            background: var(--surface-soft);
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .pd-dashboard .card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }

        .pd-dashboard .action-card h3 {
            margin: 0 0 7px;
            font-size: 12px;
            font-weight: 700;
        }

        .pd-dashboard .action-card .count {
            margin: 4px 0 6px;
            font-size: 23px;
            line-height: 1;
            font-weight: 700;
        }

        .pd-dashboard .action-card p {
            margin: 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .pd-dashboard .card-bottom {
            margin-top: 10px;
        }

        .pd-dashboard .task-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 7px;
        }

        .pd-dashboard .task-table th {
            padding: 0 8px 2px;
            color: var(--muted-2);
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .pd-dashboard .task-table td {
            padding: 9px 8px;
            vertical-align: middle;
            background: var(--surface);
            border-top: 1px solid var(--line-soft);
            border-bottom: 1px solid var(--line-soft);
        }

        .pd-dashboard .task-table td:first-child {
            border-left: 1px solid var(--line-soft);
            border-radius: 10px 0 0 10px;
        }

        .pd-dashboard .task-table td:last-child {
            border-right: 1px solid var(--line-soft);
            border-radius: 0 10px 10px 0;
            text-align: right;
        }

        .pd-dashboard .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .pd-dashboard .doc {
            display: block;
            font-size: 12px;
            font-weight: 700;
        }

        .pd-dashboard .client {
            display: block;
            margin-top: 2px;
            color: #64748b;
            font-size: 11px;
        }

        .pd-dashboard .task-desc {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }

        .pd-dashboard .task-sub {
            margin-top: 3px;
            color: #64748b;
            font-size: 11px;
        }

        .pd-dashboard .side-card {
            padding: 12px;
            margin-bottom: 8px;
            background: var(--surface-soft);
            border: 1px solid var(--line-soft);
            border-radius: 12px;
        }

        .pd-dashboard .side-card:last-child {
            margin-bottom: 0;
        }

        .pd-dashboard .side-line {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .pd-dashboard .side-card h3 {
            margin: 0 0 5px;
            font-size: 12px;
            font-weight: 700;
        }

        .pd-dashboard .side-card p {
            margin: 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .pd-dashboard .side-num {
            margin-top: 6px;
            font-size: 22px;
            line-height: 1;
            font-weight: 700;
            text-align: right;
        }

        .pd-dashboard .portal-note {
            padding: 12px;
            background: #f7faff;
            border: 1px dashed #d7e4f6;
            border-radius: 12px;
            color: #5f6f84;
            font-size: 11px;
            line-height: 1.45;
        }

        .pd-dashboard .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .pd-dashboard .quick-grid a {
            padding: 9px 8px;
            background: var(--surface-soft);
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            color: var(--text);
            text-align: center;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }

        .pd-dashboard .extra-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .pd-dashboard .extra-links a {
            color: var(--blue);
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 1250px) {
            .pd-dashboard .top-summary {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .pd-dashboard .setup-grid {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .flow {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .pd-dashboard .flow-step::after {
                display: none;
            }

            .pd-dashboard .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .right-stack {
                position: static;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
        }

        @media (max-width: 900px) {
            .pd-dashboard .hero {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .hero-actions {
                justify-content: flex-start;
                width: 100%;
            }

            .pd-dashboard .top-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .pd-dashboard .panel-list,
            .pd-dashboard .checklist,
            .pd-dashboard .mini-stats {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .flow {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .pd-dashboard .action-board {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .right-stack {
                position: static;
                display: flex;
            }

            .pd-dashboard .task-table thead {
                display: none;
            }

            .pd-dashboard .task-table,
            .pd-dashboard .task-table tbody,
            .pd-dashboard .task-table tr,
            .pd-dashboard .task-table td {
                display: block;
                width: 100%;
            }

            .pd-dashboard .task-table tr {
                margin-bottom: 8px;
                background: #fff;
                border: 1px solid var(--line);
                border-radius: 10px;
            }

            .pd-dashboard .task-table td {
                padding: 8px 10px;
                text-align: left !important;
                border: 0 !important;
                border-radius: 0 !important;
            }
        }

        @media (max-width: 640px) {
            .pd-dashboard .top-summary {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .flow {
                grid-template-columns: 1fr;
            }

            .pd-dashboard .hero-actions .pd-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <div class="pd-dashboard">
        <section class="hero">
            <div>
                <h1>Yönetim Paneli</h1>
                <p>Tekliften teslimata kadar tüm süreci tek ekrandan izleyin. Öncelik: müşteri yanıtı, bloklu operasyonlar ve teslimat bekleyen işler.</p>
            </div>
            <div class="hero-actions">
                <a class="pd-btn pd-btn-light btn-soft" href="{{ route('admin.dashboard') }}">İş Kuyruğu</a>
                <a class="pd-btn pd-btn-primary" href="{{ route('admin.promotion-quotes.create') }}">Yeni Teklif</a>
                <a class="pd-btn pd-btn-light" href="{{ route('admin.orders.index') }}">Tüm Siparişler</a>
                @if($showSuperAdminShortcut && $superAdminDashboardUrl)
                    <a class="pd-btn pd-btn-light" href="{{ $superAdminDashboardUrl }}">Super Admin Operasyon Paneline Geç</a>
                @endif
            </div>
        </section>

        <div class="dashboard-grid">
            <div class="left-stack">
                <section class="panel" aria-labelledby="dashboard-today-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-today-title">Bugün Ne Yapmalıyım?</h2>
                            <p>Günlük operasyonu başlatmak için önce bu kuyruğa bakın.</p>
                        </div>
                    </div>

                    <div class="panel-list">
                        @foreach($todayActions as $action)
                            <article class="list-card">
                                <div class="check-top">
                                    <h3>{{ $action['title'] }}</h3>
                                    <span class="badge {{ $toneBadgeMap[$action['tone'] ?? 'gray'] ?? '' }}">
                                        @if(is_numeric($action['count'] ?? null))
                                            {{ number_format((int) $action['count'], 0, ',', '.') }}
                                        @else
                                            Aç
                                        @endif
                                    </span>
                                </div>
                                <div class="count">{{ is_numeric($action['count'] ?? null) ? number_format((int) $action['count'], 0, ',', '.') : '—' }}</div>
                                <p>{{ $action['note'] }}</p>
                                <div class="card-bottom" style="margin-top:10px;">
                                    <a class="pd-btn pd-btn-light small" href="{{ $action['url'] }}">Aç</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section aria-labelledby="dashboard-summary-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-summary-title">Genel Özet</h2>
                            <p>Açık operasyonlar, bloklu alanlar ve müşteri yanıtları tek sırada özetlenir.</p>
                        </div>
                    </div>

                    <div class="top-summary">
                        @foreach($metricCards as $card)
                            <div class="kpi {{ $card['class'] }}">
                                <div>
                                    <div class="name">{{ $card['title'] }}</div>
                                    <div class="num">{{ number_format((int) $card['count'], 0, ',', '.') }}</div>
                                    <div class="hint">{{ $card['hint'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section aria-labelledby="dashboard-flow-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-flow-title">Süreç Akış Şeridi</h2>
                            <p>Tekliften teslimata kadar her adımın bekleyen iş sayısı kompakt olarak izlenir.</p>
                        </div>
                    </div>

                    <div class="flow">
                        @foreach($processSteps as $step)
                            <div class="flow-step">
                                <div class="flow-title">{{ $step['title'] }}</div>
                                <div class="flow-num">{{ $step['count'] }}</div>
                                <span class="badge {{ $step['badge']['class'] }}">{{ $step['badge']['text'] }}</span>
                                <div class="flow-note">{{ $step['note'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="panel" aria-labelledby="dashboard-priority-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-priority-title">Öncelikli Aksiyonlar</h2>
                            <p>İlk kapatılması gereken tedarik, üretim ve teslimat kuyrukları.</p>
                        </div>
                        <a class="link-mini" href="{{ route('admin.dashboard') }}">İş Kuyruğu</a>
                    </div>

                    <div class="action-board">
                        @foreach($priorityCards as $card)
                            @php
                                $badgeTone = $toneBadgeMap[$card['tone'] ?? 'gray'] ?? '';
                            @endphp
                            <article class="action-card">
                                <div>
                                    <div class="card-top">
                                        <h3>{{ $card['title'] }}</h3>
                                        <span class="badge {{ $badgeTone }}">{{ (int) ($card['count'] ?? 0) > 0 ? (($badgeTone === 'red') ? 'Bloklu' : 'Bekliyor') : 'Temiz' }}</span>
                                    </div>
                                    <div class="count">{{ number_format((int) ($card['count'] ?? 0), 0, ',', '.') }}</div>
                                    <p>{{ $card['description'] }}</p>
                                </div>
                                <div class="card-bottom">
                                    <a class="pd-btn pd-btn-light small" href="{{ $card['cta_url'] }}">{{ $card['cta_label'] }}</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="panel" aria-labelledby="dashboard-queue-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-queue-title">Sıradaki İşler</h2>
                            <p>Belge, müşteri, görev ve tek sonraki aksiyon aynı satırda gösterilir.</p>
                        </div>
                        <div class="task-meta">
                            <span class="badge green">Bugün</span>
                            <span class="badge amber">Bloklu</span>
                            <span class="badge blue">Müşteri Yanıtı</span>
                        </div>
                    </div>

                    @if($queueItems->isEmpty())
                        <div class="portal-note">Şu an bekleyen kritik iş yok.</div>
                    @else
                        <table class="task-table" aria-label="Sıradaki İşler">
                            <thead>
                                <tr>
                                    <th>Tip</th>
                                    <th>Belge / Müşteri</th>
                                    <th>Görev</th>
                                    <th>Durum</th>
                                    <th>Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($queueItems as $item)
                                    @php
                                        $taskTone = $taskToneMap[$item['bucket'] ?? 'today'] ?? '';
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="task-meta">
                                                <span class="badge {{ $taskTone }}">{{ $item['bucket_label'] ?? 'Bugün' }}</span>
                                                <span class="badge">{{ $item['kind'] }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="doc">{{ $item['document_number'] }}</span>
                                            <span class="client">{{ $item['customer_name'] }}</span>
                                        </td>
                                        <td>
                                            <div class="task-desc">{{ \Illuminate\Support\Str::limit((string) $item['summary'], 62) }}</div>
                                            <div class="task-sub">{{ \Illuminate\Support\Str::limit((string) $item['status'], 54) }}</div>
                                        </td>
                                        <td>
                                            <span class="badge">{{ $item['status'] }}</span>
                                        </td>
                                        <td>
                                            <a class="pd-btn pd-btn-light small" href="{{ $item['cta_url'] }}">{{ $item['cta_label'] }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>

            <aside class="right-stack">
                <section class="panel" aria-labelledby="dashboard-control-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-control-title">Kontrol Paneli</h2>
                            <p>İkincil ama gözden kaçmaması gereken kontrol kartları.</p>
                        </div>
                    </div>

                    @foreach($controlCards as $card)
                        @php
                            $badgeTone = $toneBadgeMap[$card['tone'] ?? 'gray'] ?? '';
                        @endphp
                        <article class="side-card">
                            <div class="side-line">
                                <div>
                                    <h3>{{ $card['title'] }}</h3>
                                    <p>{{ $card['description'] }}</p>
                                </div>
                                <div>
                                    <span class="badge {{ $badgeTone }}">{{ (int) ($card['count'] ?? 0) > 0 ? 'Aksiyon' : 'Temiz' }}</span>
                                    <div class="side-num">{{ number_format((int) ($card['count'] ?? 0), 0, ',', '.') }}</div>
                                </div>
                            </div>
                            <div class="card-bottom">
                                <a class="pd-btn pd-btn-light small" href="{{ $card['cta_url'] }}">{{ $card['cta_label'] }}</a>
                            </div>
                        </article>
                    @endforeach
                </section>

                <section class="panel" aria-labelledby="dashboard-portal-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-portal-title">Portal ve Paylaşım Linkleri</h2>
                            <p>Müşteri tarafında aktif çalışan görünür yüzeyler.</p>
                        </div>
                    </div>

                    <div class="portal-note">
                        Genel takip bağlantısı, teklif onayı ve grafik onayı aktif. Müşteri portalında teklif, sipariş ve dosya yüzeyleri kullanılabilir. Sipariş ve dosya ekranlarında fiyat, cari ve maliyet bilgileri gösterilmez.
                    </div>
                </section>

                <section class="panel" aria-labelledby="dashboard-shortcuts-title">
                    <div class="section-label">
                        <div>
                            <h2 id="dashboard-shortcuts-title">Modül Kısayolları</h2>
                            <p>Sık açılan operasyon ekranlarına doğrudan geçiş.</p>
                        </div>
                    </div>

                    <div class="quick-grid">
                        @foreach($moduleShortcutMap as $label => $url)
                            <a href="{{ $url }}">{{ $label }}</a>
                        @endforeach
                    </div>

                    <div class="extra-links">
                        @foreach($quickLinks as $link)
                            @if($link['label'] === 'Baskı Ayarları')
                                <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                            @endif
                        @endforeach
                    </div>
                </section>
            </aside>
        </div>

        <div class="setup-grid">
            <section class="panel" aria-labelledby="dashboard-readiness-title">
                <div class="section-label">
                    <div>
                        <h2 id="dashboard-readiness-title">Canlıya Hazırlık</h2>
                        <p>Eksik ayarları ve kontrol gerektiren alanları burada görün.</p>
                    </div>
                </div>

                <div class="checklist">
                    @foreach($readinessChecklist as $item)
                        @php
                            $checkTone = match($item['status']) {
                                'Hazır' => 'green',
                                'Eksik' => 'red',
                                'Pakette Yok' => '',
                                default => 'amber',
                            };
                        @endphp
                        <article class="check-card">
                            <div class="check-top">
                                <h3>{{ $item['label'] }}</h3>
                                <span class="badge {{ $checkTone }}">{{ $item['status'] }}</span>
                            </div>
                            <a class="link-mini" href="{{ $item['url'] }}">İlgili ekrana git</a>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="panel" aria-labelledby="dashboard-package-title">
                <div class="section-label">
                    <div>
                        <h2 id="dashboard-package-title">Paket ve Limit Özeti</h2>
                        <p>Paket durumu, limit uyarıları ve talep merkezi tek blokta özetlenir.</p>
                    </div>
                </div>

                <div class="mini-stats">
                    <article class="stat-card">
                        <h3>Aktif Paket</h3>
                        <div class="count">{{ $packageSummary['package_label'] ?? '-' }}</div>
                        <p>{{ $packageSummary['subscription_label'] ?? 'Bilinmiyor' }}</p>
                    </article>
                    <article class="stat-card">
                        <h3>Limit Uyarısı</h3>
                        <div class="count">{{ number_format((int) ($packageSummary['usage_warning_count'] ?? 0), 0, ',', '.') }}</div>
                        <p>{{ $packageSummary['warning_label'] ?? 'Kritik paket uyarısı yok.' }}</p>
                    </article>
                </div>

                @if(!empty($packageSummary['usage_items']))
                    <div class="micro-list">
                        @foreach($packageSummary['usage_items'] as $item)
                            <span>{{ $item['label'] }}: {{ $item['status'] }}</span>
                        @endforeach
                    </div>
                @endif

                <div class="extra-links">
                    @if(!empty($packageSummary['package_url']))
                        <a href="{{ $packageSummary['package_url'] }}">Paketim ve Kullanımım</a>
                    @endif
                    @if(!empty($packageSummary['request_url']))
                        <a href="{{ $packageSummary['request_url'] }}">Talep Merkezi</a>
                    @endif
                </div>
            </section>

            <section class="panel" aria-labelledby="dashboard-quick-start-title">
                <div class="section-label">
                    <div>
                        <h2 id="dashboard-quick-start-title">Hızlı Başla</h2>
                        <p>İlk kurulumdan ilk siparişe kadar önerilen sade akış.</p>
                    </div>
                </div>

                @if($quickStartCompleted)
                    <div class="portal-note">
                        Kurulum tamamlandı. Abone Firma günlük operasyon akışına hazır görünüyor; dilerseniz müşteri, teklif ve sipariş operasyonuna doğrudan devam edebilirsiniz.
                    </div>
                @else
                    <div class="step-list">
                        @foreach($quickStartSteps as $index => $step)
                            @php
                                $stepTone = match($step['status']) {
                                    'Hazır' => 'green',
                                    'Eksik' => 'red',
                                    'Pakette Yok' => '',
                                    default => 'amber',
                                };
                            @endphp
                            <article class="step-card">
                                <span class="step-no">{{ $index + 1 }}</span>
                                <div>
                                    <h3>{{ $step['title'] }}</h3>
                                    <p>{{ $step['description'] }}</p>
                                </div>
                                <div class="text-right">
                                    <span class="badge {{ $stepTone }}">{{ $step['status'] }}</span>
                                    <div class="mt-2">
                                        <a class="link-mini" href="{{ $step['url'] }}">Aç</a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="panel" aria-labelledby="dashboard-catalog-title">
                <div class="section-label">
                    <div>
                        <h2 id="dashboard-catalog-title">Katalog / Product Hub Özeti</h2>
                        <p>Teknik detaya girmeden teklifte kullanılabilir ürün durumunu özetler.</p>
                    </div>
                </div>

                <div class="mini-stats">
                    <article class="stat-card">
                        <h3>Katalogdaki Ürünler</h3>
                        <div class="count">{{ number_format((int) ($catalogSummary['total_products'] ?? 0), 0, ',', '.') }}</div>
                        <p>Katalogda görünen aktif ürün ve varyant satırları.</p>
                    </article>
                    <article class="stat-card">
                        <h3>Teklifte Seçilebilir</h3>
                        <div class="count">{{ number_format((int) ($catalogSummary['quote_ready_products'] ?? 0), 0, ',', '.') }}</div>
                        <p>Teklif ekranında seçilebilir ürün ve varyant satırları.</p>
                    </article>
                    <article class="stat-card">
                        <h3>Kontrol Gereken Ürünler</h3>
                        <div class="count">{{ number_format((int) ($catalogSummary['needs_review_count'] ?? 0), 0, ',', '.') }}</div>
                        <p>Kategori, fiyat veya stok uyarısı olan ürün ve varyant satırları.</p>
                    </article>
                </div>

                <div class="extra-links">
                    @if(!empty($catalogSummary['catalog_url']))
                        <a href="{{ $catalogSummary['catalog_url'] }}">Kataloğu Aç</a>
                    @endif
                    @if(!empty($catalogSummary['quote_url']))
                        <a href="{{ $catalogSummary['quote_url'] }}">Ürün Seç ve Teklif Oluştur</a>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
