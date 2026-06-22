@extends('layouts.prodelya-admin')

@section('title', 'Local Ürün Import')
@section('page_title', 'Local Ürün Import')
@section('page_subtitle', 'CSV dosyasıyla tenant’a özel ürünleri önizleyin ve içe aktarın.')

@section('content')
<div class="pd-hub-family-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Local Ürün Import</h1>
                    <p class="pd-hero-subtitle">Bu import yalnız aktif tenant’ın kendi ürünlerini oluşturur veya günceller. Global Product Data Hub’a yazmaz.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-green">CSV önizleme</span>
                        <span class="pd-badge pd-badge-blue">Tenant özel ürün</span>
                        <span class="pd-badge pd-badge-amber">Excel/XML sonraki parser fazı</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Local Ürünlere Dön</a>
                    <a href="{{ route('admin.catalog.local-products.import.template') }}" class="pd-btn pd-btn-primary">Örnek CSV Şablonu İndir</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-grid pd-grid-2">
        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">1. Dosya Yükle</h3>
                    <p class="pd-section-subtitle">İlk fazda CSV desteklenir. Zorunlu alanlar: ürün kodu ve ürün adı.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form action="{{ route('admin.catalog.local-products.import.preview') }}" method="POST" enctype="multipart/form-data" class="pd-form-grid-2">
                    @csrf
                    <div style="grid-column:1 / -1;">
                        <label class="pd-label">CSV dosyası</label>
                        <input type="file" name="file" class="pd-input" accept=".csv,.txt" required>
                    </div>
                    <div class="pd-hero-actions" style="grid-column:1 / -1;">
                        <button class="pd-btn pd-btn-primary">Önizleme Al</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kolon Standardı</h3>
                    <p class="pd-section-subtitle">Şablondaki kolonlar otomatik tanınır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-common-field-box">
urun_kodu
urun_adi
kategori
stok
liste_fiyati
para_birimi
kdv_var
renk
olcu
gorsel_url
aciklama
katalogda_gorunsun
teklifte_kullanilsin
                </div>
            </div>
        </section>
    </div>

    @if($preview)
        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">2. Önizleme</h3>
                    <p class="pd-section-subtitle">Toplam {{ $preview['total'] }} satır bulundu. İlk 20 satır gösteriliyor.</p>
                </div>
            </div>
            <div class="pd-section-body">
                @if(!empty($preview['errors']))
                    <div class="pd-alert-warning" style="margin-bottom:16px;">
                        <strong>Doğrulama uyarıları:</strong>
                        <ul style="margin-top:8px;">
                            @foreach(array_slice($preview['errors'], 0, 12) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-package-table">
                        <thead>
                            <tr>
                                @foreach($preview['headers'] as $header)
                                    <th>{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview['preview_rows'] as $row)
                                <tr>
                                    @foreach($preview['headers'] as $header)
                                        <td>{{ $row[$header] ?? '-' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form action="{{ route('admin.catalog.local-products.import.store') }}" method="POST" class="pd-hero-actions" style="margin-top:16px;">
                    @csrf
                    <label class="pd-label" style="margin:0;">Duplicate ürün kodu</label>
                    <select name="duplicate_policy" class="pd-select" style="max-width:220px;">
                        <option value="update">Var olanı güncelle</option>
                        <option value="skip">Hatalı/duplicate satırı atla</option>
                    </select>
                    <button class="pd-btn pd-btn-primary">Import Et</button>
                    <a href="{{ route('admin.catalog.local-products.import') }}" class="pd-btn pd-btn-light">İptal</a>
                </form>
            </div>
        </section>
    @endif
</div>
@endsection
