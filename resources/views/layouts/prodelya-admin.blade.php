<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Prodelya Admin')</title>
    <link rel="stylesheet" href="{{ asset('css/prodelya-admin.css') }}?v={{ filemtime(public_path('css/prodelya-admin.css')) }}">
</head>
<body>
    <input type="checkbox" id="mobileMenuToggle" class="hidden">
    <label for="mobileMenuToggle" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden"></label>

    <div class="pd-page">
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
                        <p class="text-xs text-gray-500">{{ $currentTenantForLayout?->name ?? ($isSuperAdminContext ? 'Super Admin' : 'Admin Kullanici') }}</p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="pd-badge pd-badge-blue">{{ $currentTenantForLayout?->default_currency ?? 'TL' }}</span>
                    <div class="relative">
                        <img class="h-8 w-8 rounded-full pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin Kullanıcı' }}&background=3b82f6&color=fff" alt="Kullanıcı">
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-layout {{ View::hasSection('hide_side_summary') ? 'pd-layout-no-summary' : '' }}">
            <aside class="pd-sidebar">
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="flex items-center justify-center h-16 mb-6">
                            <h1 class="text-xl font-bold">Prodelya</h1>
                        </div>

                        <nav class="space-y-6">
                            @foreach(($adminMenu['items'] ?? []) as $group)
                                @if(!empty($group['children']))
                                    <div>
                                        <h3 class="pd-sidebar-heading">{{ $group['label'] }}</h3>
                                        <div class="space-y-1">
                                            @foreach($group['children'] as $link)
                                                @php
                                                    $isAccordion = ($link['type'] ?? null) === 'accordion' && !empty($link['children']);
                                                @endphp
                                                @if($isAccordion)
                                                    @php
                                                        $children = $link['children'];
                                                    @endphp
                                                    @if(!empty($children))
                                                        @php
                                                            $accordionClass = 'pd-sidebar-group'.(!empty($link['active']) ? ' is-open' : '');
                                                            $accordionGroupSlug = $link['group_slug'] ?? null;
                                                            $accordionIsOpen = !empty($link['active']);
                                                        @endphp
                                                        <details class="{{ $accordionClass }}" @if($accordionGroupSlug) data-sidebar-group="{{ $accordionGroupSlug }}" @endif @if($accordionIsOpen) open @endif>
                                                            <summary class="pd-sidebar-group-toggle">
                                                                <span class="pd-sidebar-group-title">
                                                                    <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                                    </svg>
                                                                    {{ $link['label'] }}
                                                                </span>
                                                                <svg class="pd-sidebar-group-chevron" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 6l6 6-6 6"></path>
                                                                </svg>
                                                            </summary>
                                                            <div class="pd-sidebar-submenu">
                                                                @foreach($children as $child)
                                                                    @php
                                                                        $isHeading = ($child['type'] ?? null) === 'heading';
                                                                        $childHref = $child['href'] ?? '#';
                                                                        $childIsActive = !empty($child['active']);
                                                                    @endphp
                                                                    @if($isHeading)
                                                                        <div class="pd-sidebar-submenu-heading">{{ $child['label'] }}</div>
                                                                    @else
                                                                        <a href="{{ $childHref }}" class="pd-sidebar-submenu-item {{ $childIsActive ? 'active' : '' }} {{ $childHref === '#' ? 'pd-sidebar-item-muted' : '' }}">
                                                                            {{ $child['label'] }}
                                                                        </a>
                                                                    @endif
                                                                @endforeach
                                                            </div>
                                                        </details>
                                                    @endif
                                                @else
                                                    @php
                                                        $linkHref = $link['href'] ?? '#';
                                                        $isActive = !empty($link['active']);
                                                    @endphp
                                                    <a href="{{ $linkHref }}" class="pd-sidebar-item {{ $isActive ? 'active' : '' }} {{ $linkHref === '#' ? 'pd-sidebar-item-muted' : '' }}">
                                                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                        {{ $link['label'] }}
                                                    </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </nav>

                        <div class="border-t border-gray-200 pt-4 mt-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <img class="h-8 w-8 rounded-full pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin Kullanıcı' }}&background=3b82f6&color=fff" alt="Kullanıcı">
                                </div>
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-gray-900">{{ Auth::user()->name ?? 'Admin Kullanıcı' }}</p>
                                    <p class="text-xs text-gray-500">{{ Auth::user()->email ?? 'admin@prodelya.local' }}</p>
                                    <p class="text-xs text-gray-400">{{ $currentTenantForLayout?->name ?? ($isSuperAdminContext ? 'Super Admin' : 'Demo Tenant') }}</p>
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

            <main class="pd-main">
                @unless(View::hasSection('page_topbar_hidden'))
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
                                            <span class="pd-badge pd-badge-blue">{{ $currentTenantForLayout?->default_currency ?? 'TL' }}</span>
                                            <div class="relative">
                                                <img class="h-8 w-8 rounded-full pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin Kullanıcı' }}&background=3b82f6&color=fff" alt="Kullanıcı">
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </header>
                @endunless

                <div class="pd-content">
                    @yield('content')
                </div>
            </main>

            @unless(View::hasSection('hide_side_summary'))
                <aside class="pd-summary">
                    @hasSection('side_summary')
                        @yield('side_summary')
                    @else
                        @hasSection('summary')
                            @yield('summary')
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
                                        <a href="{{ Route::has('admin.companies.create') ? route('admin.companies.create') : '#' }}" class="pd-summary-item">
                                            Yeni Cari Kart
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
                                            <span class="font-medium">{{ $currentTenantForLayout?->name ?? ($isSuperAdminContext ? 'Super Admin' : 'Demo') }}</span>
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
                    @endif
                </aside>
            @endunless
        </div>
    </div>

    @hasSection('bottom_actions')
        <div class="pd-bottom-action-bar">
            @yield('bottom_actions')
        </div>
    @endif

    @stack('scripts')
</body>
</html>
