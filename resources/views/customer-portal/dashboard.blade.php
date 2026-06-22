@extends('customer-portal.layouts.app')

@push('styles')
    <style>
        .card {
            padding: 18px;
        }

        .stat-value {
            margin: 8px 0 0;
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }

        .section-title {
            margin: 0 0 14px;
            font-size: 18px;
            font-weight: 700;
        }

        .item-list {
            display: grid;
            gap: 12px;
        }

        .item {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px;
            background: #f9fafb;
        }

        .item h3 {
            margin: 0 0 6px;
            font-size: 15px;
        }

        .item p {
            margin: 4px 0;
            font-size: 14px;
            color: #4b5563;
        }

        .badge {
            display: inline-block;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
        }

        .empty-note {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }

        .track-link {
            display: inline-block;
            margin-top: 10px;
            color: #0f766e;
            font-weight: 700;
            text-decoration: none;
        }

        .hero-copy {
            margin: 0 0 18px;
            color: #4b5563;
            font-size: 15px;
            line-height: 1.6;
        }
    </style>
@endpush

@section('content')
    <div class="card" style="padding:20px; margin-bottom:18px;">
        <p class="hero-copy">
            Tekliflerinizi, siparişlerinizi, paylaşılmış dosyalarınızı ve sipariş takibini tek ekranda görüntüleyebilirsiniz.
        </p>
    </div>

    <div class="grid stats">
        <div class="card">
            <p class="stat-label">Tekliflerim</p>
            <p class="stat-value">{{ $sections['quotes_enabled'] ? $dashboard['counts']['open_quotes'] : '-' }}</p>
            <p class="muted">Firmanıza ait aktif teklif kayıtları burada görünür.</p>
        </div>
        <div class="card">
            <p class="stat-label">Onay Bekleyen Teklifler</p>
            <p class="stat-value">{{ $sections['quotes_enabled'] ? $dashboard['counts']['pending_quotes'] : '-' }}</p>
            <p class="muted">İncelemeniz beklenen teklifleriniz hızlıca öne çıkarılır.</p>
        </div>
        <div class="card">
            <p class="stat-label">Siparişlerim</p>
            <p class="stat-value">{{ $sections['orders_enabled'] ? $dashboard['counts']['active_orders'] : '-' }}</p>
            <p class="muted">Hazırlanan veya teslimat bekleyen siparişleriniz burada listelenir.</p>
        </div>
        <div class="card">
            <p class="stat-label">Dosyalarım</p>
            <p class="stat-value">{{ $dashboard['counts']['customer_visible_files'] }}</p>
            <p class="muted">Sizinle paylaşılan dosyalar ve sipariş takibi burada bulunur.</p>
        </div>
    </div>

    <div class="grid columns" style="margin-top: 18px;">
        <section class="card">
            <h2 class="section-title">Tekliflerimden Son Kayıtlar</h2>
            @if (! $sections['quotes_enabled'])
                <p class="empty-note">Teklif görünümü bu tenant için aktif değil.</p>
            @elseif ($dashboard['recent_quotes']->isEmpty())
                <p class="empty-note">Henüz müşterinize ait teklif kaydı görünmüyor.</p>
            @else
                <div class="item-list">
                    @foreach ($dashboard['recent_quotes'] as $quote)
                        <article class="item">
                            <h3>{{ $quote['document_number'] }}</h3>
                            <p>{{ $quote['date'] }} · <span class="badge">{{ $quote['status_label'] }}</span></p>
                            <p>{{ $quote['valid_until'] }} tarihine kadar geçerli · {{ $quote['approval_status_label'] }}</p>
                            <p>{{ $quote['product_summary'] }}</p>
                            <p>{{ $quote['grand_total'] }}</p>
                            <a href="{{ route('customer.portal.quotes.show', $quote['id']) }}" class="track-link">Teklifi Görüntüle</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card">
            <h2 class="section-title">Siparişlerimden Son Kayıtlar</h2>
            @if (! $sections['orders_enabled'])
                <p class="empty-note">Sipariş görünümü bu tenant için aktif değil.</p>
            @elseif ($dashboard['recent_orders']->isEmpty())
                <p class="empty-note">Henüz müşterinize ait sipariş kaydı görünmüyor.</p>
            @else
                <div class="item-list">
                    @foreach ($dashboard['recent_orders'] as $order)
                        <article class="item">
                            <h3>{{ $order['document_number'] }}</h3>
                            <p>{{ $order['date'] }} · <span class="badge">{{ $order['status_label'] }}</span></p>
                            <p>{{ $order['product_summary'] }}</p>
                            <p>{{ $order['delivery_status_label'] }}</p>
                            <a href="{{ route('customer.portal.orders.show', $order['id']) }}" class="track-link">Siparişi Görüntüle</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card">
            <h2 class="section-title">Sipariş Takibi</h2>
            @if ($dashboard['recent_tracking_links']->isEmpty())
                <p class="empty-note">Takip edilebilir bir iş formu henüz görünmüyor.</p>
            @else
                <div class="item-list">
                    @foreach ($dashboard['recent_tracking_links'] as $tracking)
                        <article class="item">
                            <h3>{{ $tracking['work_form_number'] }}</h3>
                            <p>{{ $tracking['order_number'] }} · <span class="badge">{{ $tracking['status_label'] }}</span></p>
                            <p>{{ $tracking['product_name'] }}</p>
                            @if($tracking['tracking_url'])
                                <a href="{{ $tracking['tracking_url'] }}" class="track-link">Müşteri Takip Ekranı</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    @if(($sections['files_enabled'] ?? false) && $dashboard['recent_files']->isNotEmpty())
        <section class="card" style="margin-top:18px;">
            <h2 class="section-title">Dosyalarım</h2>
            <div class="item-list">
                @foreach($dashboard['recent_files'] as $file)
                    <article class="item">
                        <h3>{{ $file['file_name'] }}</h3>
                        <p>{{ $file['order_number'] }} · {{ $file['work_form_number'] }}</p>
                        <p>{{ $file['created_at'] }}</p>
                        <a href="{{ $file['show_url'] }}" class="track-link">Dosyayı Aç</a>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
