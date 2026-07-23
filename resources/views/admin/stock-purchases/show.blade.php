@extends('layouts.prodelya-admin')

@section('title', 'Stok Girişi Detayı')
@section('page_title', 'Stok Girişi Detayı')
@section('page_subtitle', 'Kaydın stok hareketi ve cari etkisini tek ekranda izleyin.')

@section('content')
@php
    $isPurchase = $entry->entry_type === 'supplier_purchase';
    $movementReasonLabels = [
        'purchase' => 'Satın Alma',
        'adjustment' => $isPurchase ? 'İptal / Ters Kayıt' : 'Eldeki Stok Girişi',
        'stock_purchase' => 'Satın Alma',
        'stock_in' => 'Giriş',
        'stock_out' => 'Çıkış',
    ];
    $transactionTypeLabels = [
        'supplier_debit' => 'Tedarikçi Borcu',
        'supplier_payment' => 'Ters Cari Kapatma',
    ];
    $transactionStatusLabels = [
        'open' => 'Açık',
        'closed' => 'Kapalı',
        'partially_paid' => 'Kısmi Ödendi',
        'paid' => 'Ödendi',
        'cancelled' => 'İptal',
    ];
    $warehouseLabel = static function (?string $code): string {
        if (! filled($code)) {
            return '-';
        }

        return $code === 'LOCAL-MAIN' ? 'Ana Depo · LOCAL-MAIN' : $code;
    };
@endphp
<div class="pd-hub-family-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="pd-alert-warning">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">{{ $isPurchase ? 'Satın Alma Kaydı' : 'Eldeki Mevcut Stok Kaydı' }}</h1>
                    <p class="pd-hero-subtitle">{{ $entry->product_name }} · {{ $entry->product_code }}</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge {{ $entry->entry_status === 'cancelled' ? 'pd-badge-amber' : 'pd-badge-green' }}">{{ $entry->entry_status === 'cancelled' ? 'İptal Edildi' : 'Tamamlandı' }}</span>
                        <span class="pd-badge pd-badge-light">{{ optional($entry->entry_date)->format('d.m.Y') ?: '-' }}</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.stock-purchases.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Katalog</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-2col-grid">
        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kayıt Özeti</h3>
                    <p class="pd-section-subtitle">Bu kayıt edit edilmez; gerekirse iptal edilip ters kayıt oluşturulur.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-status-list">
                    <div class="pd-status-row"><span>Giriş tipi</span><strong>{{ $isPurchase ? 'Satın Alma' : 'Eldeki Mevcut Stok' }}</strong></div>
                    @if($isPurchase)
                        <div class="pd-status-row"><span>Tedarikçi</span><strong>{{ $entry->supplier_name ?: '-' }}</strong></div>
                    @endif
                    <div class="pd-status-row"><span>Adet</span><strong>{{ number_format((float) $entry->quantity, 0, ',', '.') }}</strong></div>
                    @if($isPurchase)
                        <div class="pd-status-row"><span>Orijinal liste fiyatı</span><strong>{{ number_format((float) $entry->original_list_price, 2, ',', '.') . ' ' . ($entry->original_currency === 'TRY' ? 'TL' : $entry->original_currency) }}</strong></div>
                        <div class="pd-status-row"><span>Alış iskontosu</span><strong>{{ '%' . number_format((float) $entry->discount_rate, 2, ',', '.') }}</strong></div>
                        <div class="pd-status-row"><span>Alış birim fiyatı</span><strong>{{ number_format((float) $entry->final_unit_price_original, 2, ',', '.') . ' ' . ($entry->original_currency === 'TRY' ? 'TL' : $entry->original_currency) }}</strong></div>
                        <div class="pd-status-row"><span>Toplam tutar</span><strong>{{ number_format((float) $entry->purchase_total_try, 2, ',', '.') . ' TL' }}</strong></div>
                        <div class="pd-status-row"><span>Kur</span><strong>{{ number_format((float) $entry->exchange_rate, 4, ',', '.') }}</strong></div>
                        <div class="pd-status-row"><span>Belge no</span><strong>{{ $entry->document_no ?: '-' }}</strong></div>
                    @endif
                    <div class="pd-status-row"><span>Not</span><strong>{{ $entry->notes ?: '-' }}</strong></div>
                </div>
            </div>
        </section>

        <aside class="pd-side-summary">
            <div class="pd-card-body">
                <div class="pd-summary-title">Etkiler</div>
                <div class="pd-status-list">
                    <div class="pd-status-row"><span>Stok hareketi</span><strong>{{ $movement->count() }} kayıt</strong></div>
                    @if($isPurchase)
                        <div class="pd-status-row"><span>Cari hareket</span><strong>{{ $debit->count() }} kayıt</strong></div>
                    @endif
                    <div class="pd-status-row"><span>Durum</span><strong>{{ $entry->entry_status === 'cancelled' ? 'Ters kayıt tamamlandı' : 'Aktif kayıt' }}</strong></div>
                </div>
                @if($isPurchase && $entry->entry_status !== 'cancelled' && $debit->isEmpty())
                    <div class="pd-alert-warning pd-gap-top-sm">Cari kaydı eksik</div>
                @endif
                @if($entry->entry_status !== 'cancelled')
                    <form action="{{ route('admin.stock-purchases.cancel', $entry) }}" method="POST" class="pd-gap-top-sm">
                        @csrf
                        <label class="pd-label" for="cancellation_reason">İptal nedeni</label>
                        <textarea name="cancellation_reason" id="cancellation_reason" rows="3" class="pd-input" placeholder="Bu kayıt neden iptal ediliyor?">{{ old('cancellation_reason') }}</textarea>
                        <button type="submit" class="pd-btn pd-btn-warning pd-gap-top-sm">İptal Et</button>
                    </form>
                @endif
            </div>
        </aside>
    </div>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Stok Hareketleri</h3>
                <p class="pd-section-subtitle">Bu kayda bağlı oluşan hareketler.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-hub-table-wrap">
                <table class="pd-table pd-package-table">
                    <thead>
                        <tr>
                            <th>Yön</th>
                            <th>Neden</th>
                            <th>Adet</th>
                            <th>Birim Maliyet</th>
                            <th>Depo</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($movement as $item)
                            <tr>
                                <td>{{ $item->movement_type === 'in' ? 'Giriş' : 'Çıkış' }}</td>
                                <td>{{ $movementReasonLabels[$item->reason] ?? $item->reason }}</td>
                                <td>{{ number_format((float) $item->quantity, 0, ',', '.') }}</td>
                                <td>{{ $item->unit_cost !== null ? number_format((float) $item->unit_cost, 2, ',', '.') . ' TL' : '-' }}</td>
                                <td>{{ $warehouseLabel($item->warehouse_code) }}</td>
                                <td>{{ optional($item->moved_at ?: $item->created_at)->format('d.m.Y H:i') ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Kayıt bulunamadı.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    @if($isPurchase)
    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Cari Hareketleri</h3>
                <p class="pd-section-subtitle">Satın alma kaydı ise tedarikçi cari etkileri burada source bağıyla görünür.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-hub-table-wrap">
                <table class="pd-table pd-package-table">
                    <thead>
                        <tr>
                            <th>Tür</th>
                            <th>Yön</th>
                            <th>Tutar</th>
                            <th>Açıklama</th>
                            <th>Durum</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($debit as $item)
                            <tr>
                                <td>{{ $transactionTypeLabels[$item->transaction_type] ?? $item->transaction_type }}</td>
                                <td>{{ $item->direction === 'debit' ? 'Borç' : 'Alacak' }}</td>
                                <td>{{ number_format((float) $item->amount, 2, ',', '.') }} TL</td>
                                <td>{{ $item->description }}</td>
                                <td>{{ $transactionStatusLabels[$item->status] ?? $item->status }}</td>
                                <td>
                                    <a href="{{ route('admin.current-accounts.show', $item->current_account_id) }}" class="pd-btn pd-btn-sm pd-btn-light">Cari Kaydını Aç</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Cari hareket yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    @endif
</div>
@endsection
