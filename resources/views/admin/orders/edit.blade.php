@extends('layouts.prodelya-admin')

@section('title', 'Sipariş Düzenle')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Sipariş Düzenle</h1>
        <p class="pd-muted mt-1">{{ $order['order_number'] }} - {{ $order['customer'] }}</p>
    </div>
    <div>
        <a href="{{ route('admin.orders.show', $order['id']) }}" class="pd-btn pd-btn-light">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Geri Dön
        </a>
    </div>
</div>

<form action="{{ route('admin.orders.update', $order['id']) }}" method="POST">
    @csrf
    @method('PUT')
    
    <!-- Temel Bilgiler -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Temel Bilgiler</h3>
            
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Sipariş No</label>
                    <input type="text" value="{{ $order['order_number'] }}" class="pd-input" readonly>
                </div>
                
                <div>
                    <label class="pd-label">Müşteri *</label>
                    <select name="customer_id" class="pd-input" required>
                        <option value="">Müşteri Seçin</option>
                        @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" {{ $customer->id == $order['customer_id'] ? 'selected' : '' }}>
                            {{ $customer->legal_name ?? $customer->short_name ?? $customer->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Sipariş Türü</label>
                    <select name="order_family" class="pd-input">
                        <option value="promotion" {{ $order['order_family'] === 'promotion' ? 'selected' : '' }}>Promosyon</option>
                        <option value="print" {{ $order['order_family'] === 'print' ? 'selected' : '' }}>Baskı</option>
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Sipariş Modu</label>
                    <select name="order_mode" class="pd-input">
                        <option value="product_sale_print" {{ $order['order_mode'] === 'product_sale_print' ? 'selected' : '' }}>Ürün Satışı + Baskı</option>
                        <option value="print_service_only" {{ $order['order_mode'] === 'print_service_only' ? 'selected' : '' }}>Sadece Baskı</option>
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Durum</label>
                    <select name="status" class="pd-input">
                        <option value="pending" {{ $order['status'] === 'pending' ? 'selected' : '' }}>Bekleyen</option>
                        <option value="in_production" {{ $order['status'] === 'in_production' ? 'selected' : '' }}>Üretimde</option>
                        <option value="ready_for_delivery" {{ $order['status'] === 'ready_for_delivery' ? 'selected' : '' }}>Teslimata Hazır</option>
                        <option value="completed" {{ $order['status'] === 'completed' ? 'selected' : '' }}>Tamamlanan</option>
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Para Birimi</label>
                    <select name="currency" class="pd-input">
                        <option value="TL" {{ $order['currency'] === 'TL' ? 'selected' : '' }}>TL</option>
                        <option value="USD" {{ $order['currency'] === 'USD' ? 'selected' : '' }}>USD</option>
                        <option value="EUR" {{ $order['currency'] === 'EUR' ? 'selected' : '' }}>EUR</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Notlar -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Notlar</h3>
            <textarea name="notes" class="pd-input" rows="3" placeholder="Sipariş ile ilgili genel notlar">{{ $order['notes'] }}</textarea>
        </div>
    </div>

    <!-- Butonlar -->
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.orders.show', $order['id']) }}" class="pd-btn pd-btn-light">İptal</a>
        <div class="flex items-center space-x-2">
            <button type="submit" class="pd-btn pd-btn-primary">Güncelle</button>
            <button type="submit" name="save_and_confirm" value="1" class="pd-btn pd-btn-success">Güncelle ve Onayla</button>
        </div>
    </div>
</form>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Sipariş Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Sipariş No:</span>
                <span class="font-medium">{{ $order['order_number'] }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Müşteri:</span>
                <span class="font-medium">{{ $order['customer'] }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Tür:</span>
                <span class="pd-badge pd-badge-{{ $order['order_family'] === 'promotion' ? 'green' : 'blue' }}">
                    {{ $order['order_family'] === 'promotion' ? 'Promosyon' : 'Baskı' }}
                </span>
            </div>
            <div class="pd-summary-row">
                <span>Mod:</span>
                <span class="pd-badge pd-badge-sm pd-badge-gray">
                    {{ $order['order_mode'] === 'product_sale_print' ? 'Ürün Satışı + Baskı' : 'Sadece Baskı' }}
                </span>
            </div>
            <div class="pd-summary-row">
                <span>Durum:</span>
                @php
                    $statusBadge = match ($order['status']) {
                        'pending' => 'yellow',
                        'in_production' => 'blue',
                        'ready_for_delivery' => 'orange',
                        default => 'green',
                    };

                    $statusLabel = match ($order['status']) {
                        'pending' => 'Bekleyen',
                        'in_production' => 'Üretimde',
                        'ready_for_delivery' => 'Teslimata Hazır',
                        default => 'Tamamlanan',
                    };
                @endphp
                <span class="pd-badge pd-badge-{{ $statusBadge }}">
                    {{ $statusLabel }}
                </span>
            </div>
            <div class="pd-summary-row">
                <span>Toplam Tutar:</span>
                <span class="font-medium text-green-600">{{ number_format($order['total_amount'], 2, ',', '.') }} TL</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button type="submit" form="mainForm" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Siparişi Güncelle
            </button>
            <button type="submit" form="mainForm" name="save_and_confirm" value="1" class="pd-btn pd-btn-success pd-btn-sm pd-btn-block">
                Güncelle ve Müşteriye Onayla
            </button>
            <a href="{{ route('admin.orders.show', $order['id']) }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block text-center">
                Sipariş Detayı
            </a>
        </div>
        
        <div class="pd-note mt-4">
            <div class="font-medium mb-1">Önemli Not</div>
            <div class="text-sm text-gray-600">
                Sipariş güncellemesi müşteriye bildirim gönderilebilir ve durum değişikliklerine neden olabilir.
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Form ID'sini ekle
document.querySelector('form').id = 'mainForm';
</script>
@endpush
