@php
    $requestHubTabs = [
        [
            'label' => 'Public Başvurular',
            'route' => route('admin.super.signup-requests.index'),
            'active' => request()->routeIs('admin.super.signup-requests.*'),
        ],
        [
            'label' => 'Paket Talepleri',
            'route' => route('admin.super.package-requests.index'),
            'active' => request()->routeIs('admin.super.package-requests.*'),
        ],
        [
            'label' => 'Abone Firma Talepleri',
            'route' => route('admin.super.upgrade-requests.index'),
            'active' => request()->routeIs('admin.super.upgrade-requests.*'),
        ],
    ];
@endphp

<section class="pd-request-hub-nav">
    <div class="pd-request-hub-nav-copy">
        <div class="pd-request-hub-nav-label">Başvuru Merkezi</div>
        <h2 class="pd-request-hub-nav-title">Public Başvurular ve Abone Firma Talepleri</h2>
        <p class="pd-request-hub-nav-note">Satış hunisi başvuruları ile Abone Firma paket, modül, limit ve servis taleplerini aynı operasyon ritminde takip edin.</p>
    </div>
    <div class="pd-request-hub-tabbar" role="tablist" aria-label="Başvuru merkezi geçişleri">
        @foreach($requestHubTabs as $tab)
            <a href="{{ $tab['route'] }}" class="pd-request-hub-tab {{ $tab['active'] ? 'is-active' : '' }}" aria-current="{{ $tab['active'] ? 'page' : 'false' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</section>
