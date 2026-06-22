@extends('customer-portal.layouts.app')

@push('styles')
    <style>
        .toolbar,
        .quote-card,
        .empty-state {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
        }

        .toolbar,
        .quote-card,
        .empty-state {
            padding: 18px;
        }

        .toolbar form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .field,
        .button {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            border-radius: 12px;
        }

        .field {
            flex: 1 1 260px;
            border: 1px solid #d1d5db;
            padding: 11px 12px;
        }

        .button {
            border: 1px solid #d1d5db;
            background: #111827;
            color: #ffffff;
            padding: 11px 16px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            cursor: pointer;
        }

        .quote-list {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }

        .quote-head,
        .quote-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .quote-no {
            margin: 0;
            font-size: 20px;
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

        .muted {
            color: #6b7280;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }

        .totals {
            font-weight: 700;
            font-family: Arial, Helvetica, sans-serif;
        }
    </style>
@endpush

@section('content')
    <div class="toolbar">
        <p class="muted" style="margin:0 0 14px;">Tekliflerinizi inceleyebilir, toplam tutarı görebilir ve gerekiyorsa onay bağlantısına geçebilirsiniz.</p>
        <form method="GET" action="{{ route('customer.portal.quotes.index') }}">
            <input
                type="text"
                name="q"
                value="{{ $search }}"
                class="field"
                placeholder="Teklif no veya ürün adı ile ara"
            >
            <button type="submit" class="button">Ara</button>
        </form>
    </div>

    @if($quotes->isEmpty())
        <div class="empty-state" style="margin-top:18px;">
            <p class="muted" style="margin:0;">Henüz görüntülenecek teklif yok.</p>
        </div>
    @else
        <div class="quote-list">
            @foreach($quotes as $quote)
                <article class="quote-card">
                    <div class="quote-head">
                        <div>
                            <h2 class="quote-no">{{ $quote['document_number'] }}</h2>
                            <p class="muted" style="margin:8px 0 0;">{{ $quote['product_summary'] }}</p>
                        </div>
                        <span class="badge">{{ $quote['status_label'] }}</span>
                    </div>

                    <div class="quote-meta" style="margin-top:16px;">
                        <p class="muted" style="margin:0;">Tarih: {{ $quote['quote_date'] }}</p>
                        <p class="muted" style="margin:0;">Geçerlilik: {{ $quote['valid_until'] }}</p>
                        <p class="muted" style="margin:0;">Onay Durumu: {{ $quote['approval_status_label'] }}</p>
                        @if($quote['grand_total'])
                            <p class="totals" style="margin:0;">{{ $quote['grand_total'] }}</p>
                        @endif
                    </div>

                    <div style="margin-top:16px;">
                        <a class="button" href="{{ route('customer.portal.quotes.show', $quote['id']) }}">Teklifi Görüntüle</a>
                    </div>
                </article>
            @endforeach
        </div>

        <div style="margin-top:18px;">
            {{ $quotes->links() }}
        </div>
    @endif
@endsection
