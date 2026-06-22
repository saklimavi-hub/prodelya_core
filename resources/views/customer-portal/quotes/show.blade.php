@extends('customer-portal.layouts.app')

@push('styles')
    <style>
        .section,
        .item-card,
        .print-card,
        .helper-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
        }

        .section,
        .item-card,
        .print-card,
        .helper-card {
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
        .print-list {
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
                <span class="label">Teklif</span>
                <h2 style="margin:0;">{{ $quoteDetail['header']['document_number'] }}</h2>
            </div>
            <span class="badge">{{ $quoteDetail['header']['status_label'] }}</span>
        </div>

        <div class="meta-grid" style="margin-top:16px;">
            <div>
                <span class="label">Teklif Tarihi</span>
                <div class="value">{{ $quoteDetail['header']['quote_date'] }}</div>
            </div>
            <div>
                <span class="label">Geçerlilik</span>
                <div class="value">{{ $quoteDetail['header']['valid_until'] }}</div>
            </div>
            <div>
                <span class="label">Firma</span>
                <div class="value">{{ $quoteDetail['header']['company_name'] }}</div>
            </div>
            <div>
                <span class="label">Para Birimi</span>
                <div class="value">{{ $quoteDetail['header']['currency'] }}</div>
            </div>
        </div>

        @if($quoteDetail['header']['note'])
            <p class="muted" style="margin:16px 0 0;">{{ $quoteDetail['header']['note'] }}</p>
        @endif
    </section>

    @if($quoteDetail['approval_helper'])
        <section class="helper-card" style="margin-top:16px;">
            <span class="label">Teklif Onayı</span>
            <div class="value">{{ $quoteDetail['approval_helper']['status_label'] }}</div>
            <p class="muted" style="margin:10px 0 0;">{{ $quoteDetail['approval_helper']['title'] }}</p>
            <p class="muted" style="margin:8px 0 0;">{{ $quoteDetail['approval_helper']['description'] }}</p>
            <a href="{{ $quoteDetail['approval_helper']['url'] }}" class="helper-link">
                {{ $quoteDetail['approval_helper']['label'] }}
            </a>
        </section>
    @endif

    <section class="section" style="margin-top:16px;">
        <h3 style="margin:0 0 8px;">Teklif Özeti</h3>
        <p class="muted" style="margin:0 0 14px;">Ürünleriniz, baskı detayları ve teklif toplamı aşağıda özetlenir.</p>
        <div class="item-list">
            @foreach($quoteDetail['items'] as $item)
                <article class="item-card">
                    <div class="row">
                        <div>
                            <div class="value">{{ $item['product_name'] }}</div>
                            <p class="muted" style="margin:8px 0 0;">
                                {{ $item['product_code'] ?: '-' }} · {{ $item['quantity'] }}
                            </p>
                        </div>
                        <div>
                            <span class="label">Birim Satış Fiyatı</span>
                            <div class="value">{{ $item['unit_price'] ?: '-' }}</div>
                        </div>
                        <div>
                            <span class="label">Satır Toplamı</span>
                            <div class="value">{{ $item['line_total'] ?: '-' }}</div>
                        </div>
                    </div>

                    @if(! empty($item['prints']))
                        <div class="print-list" style="margin-top:14px;">
                            @foreach($item['prints'] as $print)
                                <div class="print-card">
                                    <div class="row">
                                        <div>
                                            <div class="value">{{ trim($print['label']) !== '' ? $print['label'] : '-' }}</div>
                                            <p class="muted" style="margin:8px 0 0;">{{ $print['quantity'] }}</p>
                                        </div>
                                        <div>
                                            <span class="label">Birim Baskı Fiyatı</span>
                                            <div class="value">{{ $print['unit_price'] ?: '-' }}</div>
                                        </div>
                                        <div>
                                            <span class="label">Baskı Toplamı</span>
                                            <div class="value">{{ $print['line_total'] ?: '-' }}</div>
                                        </div>
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
                <div class="value">{{ $quoteDetail['totals']['subtotal'] ?: '-' }}</div>
            </div>
            <div>
                <span class="label">KDV</span>
                <div class="value">{{ $quoteDetail['totals']['vat_total'] ?: '-' }}</div>
            </div>
            <div>
                <span class="label">Genel Toplam</span>
                <div class="value">{{ $quoteDetail['totals']['grand_total'] ?: '-' }}</div>
            </div>
        </div>
    </section>
@endsection
