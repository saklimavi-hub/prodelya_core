@extends('customer-portal.layouts.app')

@push('styles')
    <style>
        .toolbar,
        .order-card,
        .empty-state {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
        }

        .toolbar,
        .order-card,
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

        .order-list {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }

        .order-head,
        .order-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .order-no {
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
    </style>
@endpush

@section('content')
    <div class="toolbar">
        <p class="muted" style="margin:0 0 14px;">Siparişlerinizin operasyon durumunu takip edebilir ve uygun kayıtlar için müşteri takip ekranına geçebilirsiniz.</p>
        <form method="GET" action="{{ route('customer.portal.orders.index') }}">
            <input
                type="text"
                name="q"
                value="{{ $search }}"
                class="field"
                placeholder="Sipariş no veya ürün adı ile ara"
            >
            <button type="submit" class="button">Ara</button>
        </form>
    </div>

    @if($orders->isEmpty())
        <div class="empty-state" style="margin-top:18px;">
            <p class="muted" style="margin:0;">Henüz görüntülenecek sipariş yok.</p>
        </div>
    @else
        <div class="order-list">
            @foreach($orders as $order)
                <article class="order-card">
                    <div class="order-head">
                        <div>
                            <h2 class="order-no">{{ $order['document_number'] }}</h2>
                            <p class="muted" style="margin:8px 0 0;">{{ $order['product_summary'] }}</p>
                        </div>
                        <span class="badge">{{ $order['status_label'] }}</span>
                    </div>

                    <div class="order-meta" style="margin-top:16px;">
                        <p class="muted" style="margin:0;">Tarih: {{ $order['order_date'] }}</p>
                        <p class="muted" style="margin:0;">Operasyon: {{ $order['operation_status'] }}</p>
                        <p class="muted" style="margin:0;">Teslimat: {{ $order['delivery_status'] }}</p>
                    </div>

                    <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                        <a class="button" href="{{ route('customer.portal.orders.show', $order['id']) }}">Siparişi Görüntüle</a>
                        @if($order['tracking_url'])
                            <a class="button" style="background:#ffffff; color:#111827;" href="{{ $order['tracking_url'] }}">Sipariş Takibi</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <div style="margin-top:18px;">
            {{ $orders->links() }}
        </div>
    @endif
@endsection
