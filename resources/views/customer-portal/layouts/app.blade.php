<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'Müşteri Portalı' }}</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }

        .shell {
            max-width: 1120px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }

        .header,
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
        }

        .header {
            padding: 20px;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .header h1 {
            margin: 6px 0 0;
            font-size: 30px;
            line-height: 1.2;
        }

        .eyebrow {
            margin: 0;
            color: #6b7280;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .muted {
            color: #6b7280;
        }

        .nav {
            margin-top: 18px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nav-item {
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .nav-item.disabled {
            color: #9ca3af;
            background: #f9fafb;
            border-color: #e5e7eb;
            cursor: not-allowed;
        }

        .nav-item.active {
            background: #111827;
            border-color: #111827;
            color: #ffffff;
        }

        .logout-button {
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #374151;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .content {
            margin-top: 20px;
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        @media (min-width: 768px) {
            .grid.stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .grid.columns {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .shell {
                padding: 18px 12px 36px;
            }

            .header h1 {
                font-size: 24px;
            }

            .nav {
                gap: 8px;
            }

            .nav-item,
            .logout-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="shell">
        <div class="header">
            <div>
                <p class="eyebrow">Müşteri Portalı</p>
                <h1>{{ $pageHeading ?? 'Portal' }}</h1>
                <p class="muted" style="margin: 10px 0 0;">
                    {{ $portalUser->safeDisplayName() }} · {{ $company?->legal_name ?? '-' }}
                    @if(! empty($tenant?->name))
                        · {{ $tenant->name }}
                    @endif
                </p>

                <div class="nav">
                    @php
                        $nav = $portalNav ?? ['quotes_enabled' => false, 'orders_enabled' => false, 'files_enabled' => false, 'active' => 'dashboard'];
                    @endphp

                    @if(($nav['active'] ?? '') === 'dashboard')
                            <span class="nav-item active">Özet</span>
                        @else
                            <a class="nav-item" href="{{ route('customer.portal.home') }}">Özet</a>
                        @endif

                    @if($nav['quotes_enabled'] ?? false)
                        @if(($nav['active'] ?? '') === 'quotes')
                            <span class="nav-item active">Tekliflerim</span>
                        @else
                            <a class="nav-item" href="{{ route('customer.portal.quotes.index') }}">Tekliflerim</a>
                        @endif
                    @else
                        <span class="nav-item disabled">Tekliflerim</span>
                    @endif

                    @if($nav['orders_enabled'] ?? false)
                        @if(($nav['active'] ?? '') === 'orders')
                            <span class="nav-item active">Siparişlerim</span>
                        @else
                            <a class="nav-item" href="{{ route('customer.portal.orders.index') }}">Siparişlerim</a>
                        @endif
                    @else
                        <span class="nav-item disabled">Siparişlerim</span>
                    @endif

                    @if($nav['files_enabled'] ?? false)
                        @if(($nav['active'] ?? '') === 'files')
                            <span class="nav-item active">Dosyalarım</span>
                        @else
                            <a class="nav-item" href="{{ route('customer.portal.files.index') }}">Dosyalarım</a>
                        @endif
                    @else
                        <span class="nav-item disabled">Dosyalarım</span>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('customer.logout') }}">
                @csrf
                <button type="submit" class="logout-button">Çıkış Yap</button>
            </form>
        </div>

        <div class="content">
            @yield('content')
        </div>
    </div>
</body>
</html>
