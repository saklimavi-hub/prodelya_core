@extends('layouts.prodelya-admin')

@section('title', 'Baskı Teklifi Düzenle')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Teklif Düzenle</h1>
        <p class="pd-muted mt-1">{{ $quote['quote_number'] }} - {{ $quote['customer'] }}</p>
    </div>
    <div>
        <a href="{{ route('admin.print-service-quotes.show', $quote['id']) }}" class="pd-btn pd-btn-light">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Geri Dön
        </a>
    </div>
</div>

<form action="{{ route('admin.print-service-quotes.update', $quote['id']) }}" method="POST">
    @csrf
    @method('PUT')
    
    <!-- Temel Bilgiler -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Temel Bilgiler</h3>
            
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Teklif No</label>
                    <input type="text" value="{{ $quote['quote_number'] }}" class="pd-input" readonly>
                </div>
                
                <div>
                    <label class="pd-label">Müşteri *</label>
                    <select name="customer_id" class="pd-input" required>
                        <option value="">Müşteri Seçin</option>
                        @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" {{ $customer->id == $quote['customer_id'] ? 'selected' : '' }}>
                            {{ $customer->legal_name ?? $customer->short_name ?? $customer->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Referans Kod</label>
                    <input type="text" name="reference_code" class="pd-input" value="{{ $quote['reference_code'] }}" placeholder="Müşteri referans kodu">
                </div>
                
                <div>
                    <label class="pd-label">Durum</label>
                    <select name="status" class="pd-input">
                        <option value="draft" {{ $quote['status'] === 'draft' ? 'selected' : '' }}>Taslak</option>
                        <option value="sent" {{ $quote['status'] === 'sent' ? 'selected' : '' }}>Gönderildi</option>
                        <option value="approved" {{ $quote['status'] === 'approved' ? 'selected' : '' }}>Onaylandı</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Notlar -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Notlar</h3>
            <textarea name="notes" class="pd-input" rows="3" placeholder="Teklif ile ilgili genel notlar">{{ $quote['notes'] }}</textarea>
        </div>
    </div>

    <!-- Butonlar -->
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.print-service-quotes.show', $quote['id']) }}" class="pd-btn pd-btn-light">İptal</a>
        <div class="flex items-center space-x-2">
            <button type="submit" class="pd-btn pd-btn-primary">Güncelle</button>
            @if($quote['status'] === 'draft')
                <button type="submit" name="save_and_send" value="1" class="pd-btn pd-btn-success">Güncelle ve Gönder</button>
            @endif
        </div>
    </div>
</form>
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
                <span>Toplam Tutar:</span>
                <span class="font-medium text-green-600">{{ number_format($quote['total_amount'], 2, ',', '.') }} TL</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button type="submit" form="mainForm" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Teklifi Güncelle
            </button>
            @if($quote['status'] === 'draft')
                <button type="submit" form="mainForm" name="save_and_send" value="1" class="pd-btn pd-btn-success pd-btn-sm pd-btn-block">
                    Güncelle ve Müşteriye Gönder
                </button>
            @endif
            <a href="{{ route('admin.print-service-quotes.show', $quote['id']) }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block text-center">
                Teklif Detayı
            </a>
        </div>
        
        <div class="pd-note mt-4">
            <div class="font-medium mb-1">Önemli Not</div>
            <div class="text-sm text-gray-600">
                Bu modda müşteri ürünü gönderir, sadece baskı hizmeti sunulur. Ürün satışı yapılmaz.
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
