@extends('customer-portal.layouts.app')

@push('styles')
    <style>
        .section,
        .item-card,
        .print-card,
        .work-form-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
        }

        .section,
        .item-card,
        .print-card,
        .work-form-card {
            padding: 18px;
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .meta-grid {
            display: grid;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .meta-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .label,
        .muted,
        .helper-link {
            font-family: Arial, Helvetica, sans-serif;
        }

        .label {
            display: block;
            margin-bottom: 6px;
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
        }

        .value {
            font-size: 16px;
            font-weight: 700;
        }

        .muted {
            color: #6b7280;
            font-size: 14px;
        }

        .badge {
            display: inline-block;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            font-family: Arial, Helvetica, sans-serif;
        }

        .item-list,
        .print-list,
        .work-form-list {
            display: grid;
            gap: 12px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .helper-link {
            display: inline-flex;
            text-decoration: none;
            color: #0f766e;
            font-weight: 700;
            margin-top: 10px;
        }
    </style>
@endpush

@section('content')
    <section class="section">
        <div class="row">
            <div>
                <span class="label">Sipariş</span>
                <h2 style="margin:0;">{{ $orderDetail['header']['document_number'] }}</h2>
            </div>
            <span class="badge">{{ $orderDetail['header']['status_label'] }}</span>
        </div>

        <div class="meta-grid" style="margin-top:16px;">
            <div>
                <span class="label">Sipariş Tarihi</span>
                <div class="value">{{ $orderDetail['header']['order_date'] }}</div>
            </div>
            <div>
                <span class="label">Firma</span>
                <div class="value">{{ $orderDetail['header']['company_name'] }}</div>
            </div>
            <div>
                <span class="label">Para Birimi</span>
                <div class="value">{{ $orderDetail['header']['currency'] }}</div>
            </div>
        </div>

        @if($orderDetail['header']['note'])
            <p class="muted" style="margin:16px 0 0;">{{ $orderDetail['header']['note'] }}</p>
        @endif

        <p class="muted" style="margin:16px 0 0;">{{ $orderDetail['header']['customer_message'] }}</p>
        <p class="muted" style="margin:8px 0 0;">Sipariş fiyatları teklifte seçilen görünüm kuralına göre sunulur. Baskı detayları görünürse ürün ve baskı ayrı, gizliyse baskı dahil birleşik fiyat gösterilir.</p>
    </section>

    <section class="section" style="margin-top:16px;">
        <h3 style="margin:0 0 14px;">Ürün Kalemleri</h3>
        <div class="item-list">
            @foreach($orderDetail['items'] as $item)
                <article class="item-card">
                    <div class="row">
                        <div>
                            <div class="value">{{ $item['product_name'] }}</div>
                            <p class="muted" style="margin:8px 0 0;">
                                {{ $item['product_code'] ?: '-' }} · {{ $item['quantity'] }}
                            </p>
                        </div>
                        <div>
                            <span class="label">{{ $item['unit_price_label'] }}</span>
                            <div class="value">{{ $item['unit_price'] ?: '-' }}</div>
                        </div>
                        <div>
                            <span class="label">{{ $item['line_total_label'] }}</span>
                            <div class="value">{{ $item['line_total'] ?: '-' }}</div>
                        </div>
                    </div>

                    @if($item['show_commercial_total'])
                        <p class="muted" style="margin:12px 0 0;">{{ $item['commercial_total_label'] }}: {{ $item['commercial_total_value'] ?: '-' }}</p>
                    @endif

                    @if(! empty($item['prints']))
                        <div class="print-list" style="margin-top:14px;">
                            @foreach($item['prints'] as $print)
                                <div class="print-card">
                                    <div>
                                        <div class="value">{{ trim($print['label']) !== '' ? $print['label'] : '-' }}</div>
                                        <p class="muted" style="margin:8px 0 0;">
                                            {{ $print['quantity'] }}
                                            @if($print['show_price_details'])
                                                · Baskı Birim Fiyatı: {{ $print['unit_price'] ?: '-' }}
                                                · Baskı Toplamı: {{ $print['line_total'] ?: '-' }}
                                            @endif
                                        </p>
                                    </div>

                                    @if($print['note'])
                                        <p class="muted" style="margin:12px 0 0;">{{ $print['note'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="section" style="margin-top:16px;">
        <h3 style="margin:0 0 14px;">Toplamlar</h3>
        <div class="row">
            <div>
                <span class="label">Ara Toplam</span>
                <div class="value">{{ $orderDetail['totals']['subtotal'] ?: '-' }}</div>
            </div>
            <div>
                <span class="label">KDV</span>
                <div class="value">{{ $orderDetail['totals']['vat_total'] ?: '-' }}</div>
            </div>
            <div>
                <span class="label">Genel Toplam</span>
                <div class="value">{{ $orderDetail['totals']['grand_total'] ?: '-' }}</div>
            </div>
        </div>
    </section>

    <section class="section" style="margin-top:16px;">
        <h3 style="margin:0 0 14px;">İş Formu ve Müşteri Takip Ekranı</h3>
        @if(empty($orderDetail['work_forms']))
            <p class="muted" style="margin:0;">Bu sipariş için henüz görüntülenebilir iş formu yok.</p>
        @else
            <div class="work-form-list">
                @foreach($orderDetail['work_forms'] as $workForm)
                    <article class="work-form-card">
                        <div class="row">
                            <div>
                                <span class="label">İş Formu</span>
                                <div class="value">{{ $workForm['work_form_number'] ?: '-' }}</div>
                                <p class="muted" style="margin:8px 0 0;">{{ $workForm['product_name'] ?: '-' }}</p>
                            </div>
                            @if($workForm['tracking_number'])
                                <div>
                                    <span class="label">Kargo / Takip No</span>
                                    <div class="value">{{ $workForm['tracking_number'] }}</div>
                                </div>
                            @endif
                        </div>

                        <div class="meta-grid" style="margin-top:16px;">
                            <div>
                                <span class="label">Grafik</span>
                                <div class="value">{{ $workForm['graphic_status'] }}</div>
                            </div>
                            <div>
                                <span class="label">Tedarik</span>
                                <div class="value">{{ $workForm['procurement_status'] }}</div>
                            </div>
                            <div>
                                <span class="label">Üretim</span>
                                <div class="value">{{ $workForm['production_status'] }}</div>
                            </div>
                            <div>
                                <span class="label">Teslimat</span>
                                <div class="value">{{ $workForm['delivery_status'] }}</div>
                            </div>
                        </div>

                        <p class="muted" style="margin:14px 0 0;">{{ $workForm['customer_message'] }}</p>

                        @if($workForm['tracking_url'])
                            <a href="{{ $workForm['tracking_url'] }}" class="helper-link">{{ $workForm['tracking_label'] }}</a>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if($filesEnabled)
        <section class="section" style="margin-top:16px;">
            <h3 style="margin:0 0 14px;">Dosyayı Görüntüle</h3>
            @if(empty($visibleAttachments))
                <p class="muted" style="margin:0;">Bu sipariş için henüz görüntülenebilir dosya yok.</p>
            @else
                <div class="work-form-list">
                    @foreach($visibleAttachments as $attachment)
                        <article class="work-form-card">
                            <div class="row">
                                <div>
                                    <span class="label">Dosya</span>
                                    <div class="value">{{ $attachment['file_name'] }}</div>
                                    <p class="muted" style="margin:8px 0 0;">{{ $attachment['attachment_type_label'] }}</p>
                                </div>
                                <div>
                                    <span class="label">Tarih</span>
                                    <div class="value">{{ $attachment['created_at'] }}</div>
                                </div>
                            </div>

                            @if($attachment['show_url'])
                                <a href="{{ $attachment['show_url'] }}" class="helper-link">Dosyayı Görüntüle</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
@endsection
