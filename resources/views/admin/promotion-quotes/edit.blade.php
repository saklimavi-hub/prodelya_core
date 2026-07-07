@extends('layouts.prodelya-admin')

@section('title', 'Düzenle: ' . $quote->document_number)
@section('hide_side_summary', '1')
@section('page_title', 'Promosyon Teklifini Düzenle')
@section('page_subtitle', 'Teklif girişini kompakt formda güncelleyin. Siparişe çevirme kararı teklif detay ekranında onay modalı ile verilir.')
@section('page_actions')
    <a href="{{ route('admin.promotion-quotes.show', $quote) }}" class="pd-btn pd-btn-light">Teklifi Gör</a>
    <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
@endsection

@section('content')
    @php
        $initialItems = $quote->items->map(function ($item) {
            return [
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'quantity' => number_format((float) $item->quantity, 2, '.', ''),
                'unit' => $item->unit,
                'list_price' => number_format((float) $item->list_price, 2, '.', ''),
                'discount_rate' => number_format((float) $item->discount_rate, 2, '.', ''),
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'line_total' => number_format((float) $item->line_total, 2, '.', ''),
                'description' => $item->description,
                'has_print' => (bool) $item->has_print,
                'tenant_catalog_product_id' => $item->tenant_catalog_product_id,
                'tenant_catalog_product_variant_id' => $item->tenant_catalog_product_variant_id,
                'standard_product_id' => $item->standard_product_id,
                'standard_product_variant_id' => $item->standard_product_variant_id,
                'supplier_id' => $item->supplier_id,
                'supplier_source_id' => $item->supplier_source_id,
                'catalog_source' => $item->catalog_source ?? 'tenant_catalog',
                'product_snapshot' => $item->product_snapshot,
                'price_snapshot' => $item->price_snapshot,
                'stock_snapshot' => $item->stock_snapshot,
                'selected_catalog_identity' => [
                    'catalog_source' => $item->catalog_source ?? 'tenant_catalog',
                    'tenant_catalog_product_id' => $item->tenant_catalog_product_id,
                    'tenant_catalog_product_variant_id' => $item->tenant_catalog_product_variant_id,
                    'standard_product_id' => $item->standard_product_id,
                    'standard_product_variant_id' => $item->standard_product_variant_id,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'is_warning_sellable' => (bool) data_get($item->product_snapshot, 'is_warning_sellable', false),
                    'warning_tone' => data_get($item->product_snapshot, 'warning_tone'),
                    'warning_summary' => data_get($item->product_snapshot, 'warning_summary'),
                ],
                'prints' => $item->prints->map(fn ($print) => [
                    'tenant_print_setting_id' => $print->tenant_print_setting_id,
                    'standard_print_type_id' => $print->standard_print_type_id,
                    'print_type' => $print->print_type,
                    'print_option' => $print->print_option,
                    'production_type' => $print->production_type,
                    'subcontractor_company_id' => $print->subcontractor_company_id,
                    'cliche_status' => $print->cliche_status,
                    'setup_pricing_enabled' => (bool) $print->setup_pricing_enabled,
                    'setup_type' => $print->setup_type,
                    'setup_status' => $print->setup_status ?: $print->cliche_status,
                    'setup_total_amount' => $print->setup_total_amount !== null ? number_format((float) $print->setup_total_amount, 2, '.', '') : null,
                    'setup_distribution_quantity' => $print->setup_distribution_quantity !== null ? number_format((float) $print->setup_distribution_quantity, 2, '.', '') : null,
                    'setup_unit_amount' => $print->setup_unit_amount !== null ? number_format((float) $print->setup_unit_amount, 4, '.', '') : null,
                    'base_print_unit_price' => $print->base_print_unit_price !== null ? number_format((float) $print->base_print_unit_price, 2, '.', '') : number_format((float) $print->print_unit_price, 2, '.', ''),
                    'print_quantity' => number_format((float) $print->print_quantity, 2, '.', ''),
                    'print_unit_price' => number_format((float) $print->print_unit_price, 2, '.', ''),
                    'print_total' => number_format((float) $print->print_total, 2, '.', ''),
                    'note' => $print->note,
                    'production_note' => $print->production_note,
                ])->values()->all(),
            ];
        })->values()->all();
    @endphp

    <div class="pd-note pd-note-amber mb-4">
        Bu teklifte kullanılan katalog bilgileri teklif oluşturulduktan sonra güncellenmiş olabilir. Local stok, tedarikçi stok ve warning badge’lerini kaydetmeden önce tekrar kontrol etmeniz önerilir.
    </div>

    @include('admin.promotion-quotes._form-workspace', [
        'formAction' => route('admin.promotion-quotes.update', $quote),
        'formMethod' => 'PUT',
        'submitLabel' => 'Değişiklikleri Kaydet',
        'cancelUrl' => route('admin.promotion-quotes.show', $quote),
        'quoteNumberLabel' => $quote->document_number,
        'initialItems' => $initialItems,
    ])
@endsection

@section('bottom_actions')
<div>
    <strong>Güncelleme akışı:</strong>
    <span class="pd-muted">Ürün warning’lerini, stok önceliğini ve baskı operasyonlarını gözden geçirip değişiklikleri kaydedin. PDF, gönderim ve portal adımları teklif detay ekranından yönetilir.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.promotion-quotes.show', $quote) }}" class="pd-btn pd-btn-warning">Vazgeç</a>
    <button type="submit" form="quote-form" class="pd-btn pd-btn-primary">Kaydet</button>
    <a href="{{ route('admin.promotion-quotes.show', $quote) }}" class="pd-btn pd-btn-success" data-testid="edit-review-convert-link">Teklifi Kontrol Et / Siparişe Çevir</a>
</div>
@endsection
