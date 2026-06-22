@extends('layouts.prodelya-admin')

@section('title', 'Yeni Promosyon Teklifi')
@section('hide_side_summary', '1')
@section('page_title', 'Promosyon Teklifi Oluştur')
@section('page_subtitle', 'Müşteri, ürün ve baskı bilgilerini kompakt teklif giriş ekranında hazırlayın. Siparişe çevirme kararı kayıt sonrası teklif detayında verilir.')
@section('page_actions', '')

@section('content')
    @php
        $initialItems = collect(old('items', [[]]))->map(function ($item) {
            $item['product_snapshot'] = is_string($item['product_snapshot'] ?? null) ? json_decode($item['product_snapshot'], true) : ($item['product_snapshot'] ?? null);
            $item['price_snapshot'] = is_string($item['price_snapshot'] ?? null) ? json_decode($item['price_snapshot'], true) : ($item['price_snapshot'] ?? null);
            $item['stock_snapshot'] = is_string($item['stock_snapshot'] ?? null) ? json_decode($item['stock_snapshot'], true) : ($item['stock_snapshot'] ?? null);
            $item['prints'] = collect($item['prints'] ?? [])->map(function ($print) {
                return [
                    'tenant_print_setting_id' => $print['tenant_print_setting_id'] ?? null,
                    'standard_print_type_id' => $print['standard_print_type_id'] ?? null,
                    'print_type' => $print['print_type'] ?? null,
                    'print_option' => $print['print_option'] ?? null,
                    'production_type' => $print['production_type'] ?? null,
                    'subcontractor_company_id' => $print['subcontractor_company_id'] ?? null,
                    'cliche_status' => $print['cliche_status'] ?? null,
                    'print_quantity' => $print['print_quantity'] ?? null,
                    'print_unit_price' => $print['print_unit_price'] ?? null,
                    'print_total' => $print['print_total'] ?? null,
                    'note' => $print['note'] ?? null,
                    'production_note' => $print['production_note'] ?? null,
                ];
            })->values()->all();
            return $item;
        })->values()->all();
    @endphp

    <div class="pd-note pd-note-slate mb-4">
        Bu ekran teklif hazırlama alanıdır. Kaydetme sonrası teklif detayından kontrol edip siparişe çevirme akışına geçebilirsiniz.
    </div>

    @include('admin.promotion-quotes._form-workspace', [
        'formAction' => route('admin.promotion-quotes.store'),
        'formMethod' => 'POST',
        'submitLabel' => 'Teklifi Kaydet',
        'cancelUrl' => route('admin.promotion-quotes.index'),
        'quoteNumberLabel' => $nextQuoteNumber,
        'initialItems' => $initialItems,
    ])
@endsection

@section('bottom_actions')
<div>
    <strong>Hızlı akış:</strong>
    <span class="pd-muted">Ürünleri tenant katalogdan seçin, local stok önceliğini kontrol edin ve baskı operasyonlarını kaydederek teklifinizi tamamlayın. PDF, gönderim ve portal adımları kayıt sonrası teklif detay ekranında yönetilir.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-warning">Vazgeç</a>
    <button type="submit" form="quote-form" class="pd-btn pd-btn-primary">Kaydet</button>
</div>
@endsection
