<div class="pd-local-product-subnav-shell">
    <div class="pd-local-product-subnav-note">
        <strong>Local Ürün Yönetimi</strong>
        <span>Kendi ürünleriniz, stoğa alınan tedarikçi ürünleri ve içe aktarma yüzeyleri bu alanda bir araya gelir.</span>
    </div>
    <nav class="pd-local-product-tabs" aria-label="Local ürün sekmeleri">
        <a href="{{ route('admin.catalog.local-products') }}" class="pd-local-product-tab {{ request()->routeIs('admin.catalog.local-products') ? 'is-active' : '' }}">Ürün Listem</a>
        <a href="{{ route('admin.catalog.local-products.supplier-stock') }}" class="pd-local-product-tab {{ request()->routeIs('admin.catalog.local-products.supplier-stock') ? 'is-active' : '' }}">Tedarikçiden Stoğa Alınanlar</a>
        <a href="{{ route('admin.catalog.local-products.create') }}" class="pd-local-product-tab {{ request()->routeIs('admin.catalog.local-products.create') ? 'is-active' : '' }}">Yeni Ürün Ekle</a>
        <a href="{{ route('admin.catalog.local-products.import') }}" class="pd-local-product-tab {{ request()->routeIs('admin.catalog.local-products.import') || request()->routeIs('admin.catalog.local-products.import.*') ? 'is-active' : '' }}">Dosyadan Ürün Aktar</a>
    </nav>
</div>
