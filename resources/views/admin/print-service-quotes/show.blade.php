@extends('layouts.prodelya-admin')

@section('title', 'Baskı Teklif Detayı')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Teklif Detayı</h1>
        <p class="pd-muted mt-1">{{ $quote['quote_number'] }} - {{ $quote['customer'] }}</p>
    </div>
    <div>
        <a href="{{ route('admin.print-service-quotes.index') }}" class="pd-btn pd-btn-light">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Geri Dön
        </a>
    </div>
</div>

<!-- Teklif Bilgileri -->
<div class="pd-card mb-6">
    <div class="pd-card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="pd-section-title">Teklif Bilgileri</h3>
            <div class="flex items-center space-x-2">
                <span class="pd-badge pd-badge-{{ $quote['status'] === 'draft' ? 'gray' : ($quote['status'] === 'sent' ? 'yellow' : 'green') }}">
                    {{ $quote['status'] === 'draft' ? 'Taslak' : ($quote['status'] === 'sent' ? 'Gönderildi' : 'Onaylandı') }}
                </span>
                <a href="{{ route('admin.print-service-quotes.edit', $quote['id']) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
            </div>
        </div>
        
        <div class="pd-form-grid-2">
            <div>
                <label class="pd-label">Teklif No</label>
                <input type="text" value="{{ $quote['quote_number'] }}" class="pd-input" readonly>
            </div>
            
            <div>
                <label class="pd-label">Müşteri</label>
                <input type="text" value="{{ $quote['customer'] }}" class="pd-input" readonly>
            </div>
            
            <div>
                <label class="pd-label">Referans Kod</label>
                <input type="text" value="{{ $quote['reference_code'] }}" class="pd-input" readonly>
            </div>
            
            <div>
                <label class="pd-label">Oluşturulma Tarihi</label>
                <input type="text" value="{{ $quote['created_at']->format('d.m.Y H:i') }}" class="pd-input" readonly>
            </div>
        </div>
        
        @if($quote['notes'])
        <div class="mt-4">
            <label class="pd-label">Notlar</label>
            <textarea class="pd-input" rows="2" readonly>{{ $quote['notes'] }}</textarea>
        </div>
        @endif
    </div>
</div>

<!-- Baskı Kalemleri -->
<div class="pd-card mb-6">
    <div class="pd-card-body">
        <h3 class="pd-section-title mb-4">Baskı Kalemleri</h3>
        
        <div class="space-y-4">
            @foreach($quote['items'] as $index => $item)
            <div class="pd-card">
                <div class="pd-card-body">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-medium">Kalem {{ $index + 1 }}</h4>
                        <span class="pd-badge pd-badge-blue">{{ number_format($item['total_price'], 2, ',', '.') }} TL</span>
                    </div>
                    
                    <div class="pd-form-grid-2">
                        <div>
                            <label class="pd-label">Müşteri Ürün Açıklaması</label>
                            <textarea class="pd-input" rows="2" readonly>{{ $item['customer_product_description'] }}</textarea>
                        </div>
                        
                        <div>
                            <label class="pd-label">Notlar</label>
                            <textarea class="pd-input" rows="2" readonly>{{ $item['notes'] ?: 'Not yok' }}</textarea>
                        </div>
                    </div>
                    
                    <div class="pd-form-grid-3">
                        <div>
                            <label class="pd-label">Adet</label>
                            <input type="text" value="{{ number_format($item['quantity'], 0, '.', '.') }}" class="pd-input" readonly>
                        </div>
                        
                        <div>
                            <label class="pd-label">Baskı Türü</label>
                            <input type="text" value="{{ $item['print_type'] }}" class="pd-input" readonly>
                        </div>
                        
                        <div>
                            <label class="pd-label">Baskı Seçeneği</label>
                            <input type="text" value="{{ $item['print_option'] }}" class="pd-input" readonly>
                        </div>
                    </div>
                    
                    <div class="pd-form-grid-3">
                        <div>
                            <label class="pd-label">Baskı Yeri</label>
                            <input type="text" value="{{ $item['print_location'] }}" class="pd-input" readonly>
                        </div>
                        
                        <div>
                            <label class="pd-label">Baskı Rengi</label>
                            <input type="text" value="{{ $item['print_color'] }}" class="pd-input" readonly>
                        </div>
                        
                        <div>
                            <label class="pd-label">Klişe / Kalıp</label>
                            <input type="text" value="{{ $item['plate'] }}" class="pd-input" readonly>
                        </div>
                    </div>
                    
                    <div class="pd-form-grid-2">
                        <div>
                            <label class="pd-label">Baskı Miktarı</label>
                            <input type="text" value="{{ number_format($item['print_quantity'], 0, '.', '.') }}" class="pd-input" readonly>
                        </div>
                        
                        <div>
                            <label class="pd-label">Birim Fiyat</label>
                            <input type="text" value="{{ number_format($item['unit_price'], 2, ',', '.') }} TL" class="pd-input" readonly>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

<!-- Toplam Özet -->
<div class="pd-card mb-6">
    <div class="pd-card-body">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="pd-section-title">Toplam</h3>
                <p class="text-sm text-gray-600">{{ count($quote['items']) }} kalem</p>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-green-600">{{ number_format($quote['total_amount'], 2, ',', '.') }} TL</p>
            </div>
        </div>
    </div>
</div>

<!-- Aksiyon Butonları -->
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-2">
        @if($quote['status'] === 'draft')
            <button class="pd-btn pd-btn-success">Müşteriye Gönder</button>
        @endif
        @if($quote['status'] === 'approved')
            <button class="pd-btn pd-btn-primary">Siparişe Çevir</button>
        @endif
        <button class="pd-btn pd-btn-light">PDF İndir</button>
    </div>
    
    <div class="flex items-center space-x-2">
        <a href="{{ route('admin.print-service-quotes.edit', $quote['id']) }}" class="pd-btn pd-btn-light">Düzenle</a>
        <button class="pd-btn pd-btn-danger">Sil</button>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Teklif Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Teklif No:</span>
                <span class="font-medium">{{ $quote['quote_number'] }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Müşteri:</span>
                <span class="font-medium">{{ $quote['customer'] }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Durum:</span>
                <span class="pd-badge pd-badge-{{ $quote['status'] === 'draft' ? 'gray' : ($quote['status'] === 'sent' ? 'yellow' : 'green') }}">
                    {{ $quote['status'] === 'draft' ? 'Taslak' : ($quote['status'] === 'sent' ? 'Gönderildi' : 'Onaylandı') }}
                </span>
            </div>
            <div class="pd-summary-row">
                <span>Kalem Sayısı:</span>
                <span class="font-medium">{{ count($quote['items']) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Toplam Tutar:</span>
                <span class="font-medium text-green-600">{{ number_format($quote['total_amount'], 2, ',', '.') }} TL</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            @if($quote['status'] === 'draft')
                <button class="pd-btn pd-btn-success pd-btn-sm pd-btn-block">
                    Müşteriye Gönder
                </button>
            @endif
            @if($quote['status'] === 'approved')
                <button class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                    Siparişe Çevir
                </button>
            @endif
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                PDF İndir
            </button>
            <a href="{{ route('admin.print-service-quotes.edit', $quote['id']) }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block text-center">
                Teklifi Düzenle
            </a>
        </div>
        
        <div class="pd-note mt-4">
            <div class="font-medium mb-1">Baskı Modu</div>
            <div class="text-sm text-gray-600">
                Müşteri ürünü gönderir, sadece baskı hizmeti sunulur. Ürün satışı yapılmaz.
            </div>
        </div>
    </div>
</div>
@endsection
