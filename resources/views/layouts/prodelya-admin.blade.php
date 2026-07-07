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
        $layoutContextLabel = 'Platform Yönetimi';
        $hasSideSummary = View::hasSection('side_summary') || View::hasSection('summary');
        $hideSideSummary = View::hasSection('hide_side_summary') || !$hasSideSummary;

        if ($currentTenantForLayout) {
            $layoutContextLabel = $isSuperAdminContext
                ? 'Super Admin / ' . $currentTenantForLayout->name
                : 'Abone Firma Paneli: ' . $currentTenantForLayout->name;
        } elseif ($isSuperAdminContext) {
            $layoutContextLabel = 'Super Admin';
        }
    @endphp
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
                        <p class="text-xs text-gray-500">{{ $layoutContextLabel }}</p>
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

        <div class="pd-layout {{ $hideSideSummary ? 'pd-layout-no-summary' : '' }}">
            <aside class="pd-sidebar">
                <div class="pd-sidebar-shell">
                    <div class="pd-sidebar-brand">
                        <div class="pd-sidebar-brand-main">
                            <span class="pd-sidebar-brand-mark">P</span>
                            <span>Prodelya</span>
                        </div>
                        <div class="pd-sidebar-brand-sub">{{ $layoutContextLabel }}</div>
                    </div>

                    @if(($adminMenu['context'] ?? 'tenant') === 'tenant')
                        <div class="pd-sidebar-search">
                            <input type="text" value="" placeholder="Menüde ara..." aria-label="Menüde ara">
                        </div>
                    @endif

                    <nav class="pd-sidebar-nav" aria-label="Admin menüsü">
                        @if(($adminMenu['context'] ?? 'tenant') === 'tenant')
                            @foreach(($adminMenu['items'] ?? []) as $group)
                                @php
                                    $children = $group['children'] ?? [];
                                    $isSingleLinkGroup = ($group['presentation'] ?? null) === 'single-link' && count($children) === 1;
                                @endphp
                                @if($isSingleLinkGroup)
                                    @php
                                        $link = $children[0];
                                        $linkHref = $link['href'] ?? '#';
                                        $isActive = !empty($link['active']);
                                    @endphp
                                    <div class="pd-sidebar-section pd-sidebar-section-single">
                                        <a href="{{ $linkHref }}" class="pd-sidebar-section-link {{ $isActive ? 'active' : '' }} {{ $linkHref === '#' ? 'pd-sidebar-item-muted' : '' }}">
                                            <span class="pd-sidebar-section-title">
                                                <span class="pd-sidebar-dot"></span>
                                                {{ $link['label'] }}
                                            </span>
                                        </a>
                                    </div>
                                @elseif(!empty($children))
                                    <details class="pd-sidebar-section {{ !empty($group['active']) ? 'is-open' : '' }}" @if(!empty($group['active'])) open @endif>
                                        <summary class="pd-sidebar-section-toggle">
                                            <span class="pd-sidebar-section-title">
                                                <span class="pd-sidebar-dot"></span>
                                                {{ $group['label'] }}
                                            </span>
                                            <svg class="pd-sidebar-section-chevron" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 6l6 6-6 6"></path>
                                            </svg>
                                        </summary>
                                        <div class="pd-sidebar-section-items">
                                            @foreach($children as $link)
                                                @php
                                                    $linkHref = $link['href'] ?? '#';
                                                    $isActive = !empty($link['active']);
                                                @endphp
                                                <a href="{{ $linkHref }}" class="pd-sidebar-submenu-item {{ $isActive ? 'active' : '' }} {{ $linkHref === '#' ? 'pd-sidebar-item-muted' : '' }}">
                                                    {{ $link['label'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            @endforeach
                        @else
                            <div class="space-y-6">
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
                            </div>
                        @endif
                    </nav>

                    <div class="pd-sidebar-footer">
                        <div class="pd-sidebar-user">
                            <img class="pd-sidebar-user-avatar pd-allow-large" src="https://ui-avatars.com/api/?name={{ Auth::user()->name ?? 'Admin Kullanıcı' }}&background=3b82f6&color=fff" alt="Kullanıcı">
                            <div class="pd-sidebar-user-copy">
                                <p class="pd-sidebar-user-name">{{ Auth::user()->name ?? 'Admin Kullanıcı' }}</p>
                                <p class="pd-sidebar-user-mail">{{ Auth::user()->email ?? 'admin@prodelya.local' }}</p>
                            </div>
                        </div>
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button type="submit" class="pd-sidebar-logout">
                                Çıkış Yap
                            </button>
                        </form>
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
                                        <p class="pd-topbar-context">{{ $layoutContextLabel }}</p>
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

            @unless($hideSideSummary)
                <aside class="pd-summary">
                    @hasSection('side_summary')
                        @yield('side_summary')
                    @else
                        @yield('summary')
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
