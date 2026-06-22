<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Prodelya Admin')</title>
    <link rel="stylesheet" href="{{ asset('css/prodelya-admin.css') }}?v={{ filemtime(public_path('css/prodelya-admin.css')) }}">
</head>
<body>
    @php
        $superAdminLinks = [
            ['label' => 'Super Admin Paneli', 'route' => 'admin.super.dashboard'],
            ['label' => 'Tenantler', 'route' => 'admin.super.tenants'],
            ['label' => 'Modüller', 'route' => 'admin.super.modules'],
            ['label' => 'Product Data Hub', 'route' => 'admin.super.product-data-hub.index'],
            ['label' => 'Super Ayarlar', 'route' => 'admin.super.settings'],
        ];
    @endphp
    
    <!-- Mobile Menu Checkbox & Backdrop -->
    <input type="checkbox" id="mobileMenuToggle" class="hidden">
    <label for="mobileMenuToggle" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden"></label>
    
    <div class="pd-page">
        <!-- Mobile Top Bar -->
        <div class="pd-mobilebar">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center space-x-3">
                    <label for="mobileMenuToggle" class="pd-btn pd-btn-light">
                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </label>
                    <div>
                        <h1 class="text-lg font-bold">Prodelya</h1>
                        <p class="text-xs text-gray-500">{{ Auth::user()->tenantAccount->name ?? 'Admin' }}</p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="pd-badge pd-badge-blue">TL</span>
                    <div class="relative">
                        <img class="h-8 w-8 rounded-full pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin' }}&background=3b82f6&color=fff" alt="User">
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-layout">
            <!-- Sidebar -->
            <aside class="pd-sidebar">
                <div class="pd-card">
                    <div class="pd-card-body">
                        <!-- Logo -->
                        <div class="flex items-center justify-center h-16 mb-6">
                            <h1 class="text-xl font-bold">Prodelya</h1>
                        </div>

                        <!-- Navigation -->
                        <nav class="space-y-6">
                            <!-- Ana Menü -->
                            <div>
                                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Ana Menü</h3>
                                <div class="space-y-1">
                                    <a href="{{ Route::has('admin.dashboard') ? route('admin.dashboard') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                        </svg>
                                        Dashboard
                                    </a>

                                    <a href="{{ Route::has('admin.companies.index') ? route('admin.companies.index') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.companies.*') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                        </svg>
                                        Firmalar
                                    </a>

                                    <a href="{{ Route::has('admin.promotion-quotes.index') ? route('admin.promotion-quotes.index') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.promotion-quotes.*') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Promosyon Teklifleri
                                    </a>

                                    <a href="{{ Route::has('admin.print-service-quotes.index') ? route('admin.print-service-quotes.index') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.print-service-quotes.*') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Baskı Teklifleri
                                    </a>

                                    <a href="{{ Route::has('admin.orders.index') ? route('admin.orders.index') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M4 18h16a2 2 0 002-2v-5a2 2 0 00-2-2H4a2 2 0 00-2 2v5a2 2 0 002 2z"></path>
                                        </svg>
                                        Siparişler
                                    </a>

                                    <a href="{{ Route::has('admin.product-data-hub.index') ? route('admin.product-data-hub.index') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.product-data-hub.*') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                        </svg>
                                        Product Data Hub
                                    </a>

                                    <a href="{{ Route::has('admin.settings') ? route('admin.settings') : '#' }}" class="pd-sidebar-item {{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        Ayarlar
                                    </a>
                                </div>
                            </div>

                            <!-- Diğer -->
                            <div>
                                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Diğer</h3>
                                <div class="space-y-1">
                                    <a href="#" class="pd-sidebar-item">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                        </svg>
                                        Grafik
                                    </a>

                                    <a href="#" class="pd-sidebar-item">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                        </svg>
                                        Tedarik
                                    </a>
                                </div>
                            </div>

                            <!-- Super Admin -->
                            @if(Route::has('admin.super.dashboard'))
                            <div>
                                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Super Admin</h3>
                                <div class="space-y-1">
                                    @foreach($superAdminLinks as $superAdminLink)
                                    <a href="{{ route($superAdminLink['route']) }}" class="pd-sidebar-item {{ request()->routeIs('admin.super.*') ? 'active' : '' }}">
                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        {{ $superAdminLink['label'] }}
                                    </a>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </nav>

                        <!-- User Info -->
                        <div class="border-t border-gray-200 pt-4 mt-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <img class="h-8 w-8 rounded-full pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin' }}&background=3b82f6&color=fff" alt="User">
                                </div>
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-gray-900">{{ Auth::user()->name ?? 'Admin' }}</p>
                                    <p class="text-xs text-gray-500">{{ Auth::user()->email ?? 'admin@prodelya.local' }}</p>
                                    <p class="text-xs text-gray-400">{{ Auth::user()->tenantAccount->name ?? 'Demo Tenant' }}</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <form action="{{ route('logout') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="w-full text-left text-sm text-gray-600 hover:text-gray-900">
                                        Çıkış Yap
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="pd-main">
                <!-- Topbar -->
                <header class="pd-topbar">
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h1 class="text-xl font-semibold text-gray-900">
                                        @yield('page_title', 'Prodelya Core')
                                    </h1>
                                    @hasSection('page_subtitle')
                                        <p class="text-sm text-gray-600 mt-1">@yield('page_subtitle')</p>
                                    @endif
                                </div>
                                <div class="flex items-center space-x-3">
                                    @hasSection('page_actions')
                                        @yield('page_actions')
                                    @else
                                        <span class="pd-badge pd-badge-blue">TL</span>
                                        <div class="relative">
                                            <img class="h-8 w-8 rounded-full pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin' }}&background=3b82f6&color=fff" alt="User">
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <!-- Page Content -->
                <div class="pd-content">
                    @yield('content')
                </div>
            </main>

            <!-- Right Sidebar -->
            <aside class="pd-summary">
                @hasSection('side_summary')
                    @yield('side_summary')
                @else
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <h3 class="pd-summary-title">Özet</h3>
                            
                            <div class="pd-summary-section">
                                <h4 class="pd-summary-section-title">Hızlı İşlemler</h4>
                                <div class="pd-summary-list">
                                    <a href="{{ Route::has('admin.dashboard') ? route('admin.dashboard') : '#' }}" class="pd-summary-item">
                                        Ana Sayfa
                                    </a>
                                    <a href="{{ Route::has('admin.companies.index') ? route('admin.companies.create') : '#' }}" class="pd-summary-item">
                                        Yeni Firma
                                    </a>
                                    <a href="{{ Route::has('admin.promotion-quotes.index') ? route('admin.promotion-quotes.create') : '#' }}" class="pd-summary-item">
                                        Yeni Teklif
                                    </a>
                                </div>
                            </div>

                            <div class="pd-summary-section">
                                <h4 class="pd-summary-section-title">Aktif Tenant</h4>
                                <div class="pd-summary-info">
                                    <div class="pd-summary-row">
                                        <span>Adı:</span>
                                        <span class="font-medium">{{ Auth::user()->tenantAccount->name ?? 'Demo' }}</span>
                                    </div>
                                    <div class="pd-summary-row">
                                        <span>Domain:</span>
                                        <span class="font-medium">{{ request()->getHost() }}</span>
                                    </div>
                                    <div class="pd-summary-row">
                                        <span>Durum:</span>
                                        <span class="pd-badge pd-badge-green">Aktif</span>
                                    </div>
                                </div>
                            </div>

                            <div class="pd-summary-section">
                                <h4 class="pd-summary-section-title">Sistem Notu</h4>
                                <div class="pd-note">
                                    <p class="text-sm text-gray-600">
                                        Prodelya Core v1.0 aktif. Tüm modüller çalışır durumda.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
