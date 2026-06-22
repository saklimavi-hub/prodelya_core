@extends('layouts.prodelya-admin')

@section('title', 'Baskı Teklifleri')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Baskı Teklifleri</h1>
        <p class="pd-muted mt-1">Müşteri ürünü baskı tekliflerini yönetin.</p>
    </div>
    <div>
        <a href="{{ route('admin.print-service-quotes.create') }}" class="pd-btn pd-btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Yeni Teklif
        </a>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        <div class="overflow-x-auto">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Teklif No</th>
                        <th>Müşteri</th>
                        <th>Referans Kod</th>
                        <th>Tutar</th>
                        <th>Durum</th>
                        <th>Kalem</th>
                        <th>Tarih</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($quotes as $quote)
                    <tr>
                        <td>
                            <span class="pd-badge pd-badge-blue">{{ $quote['quote_number'] }}</span>
                        </td>
                        <td>
                            <div>
                                <p class="font-medium">{{ $quote['customer'] }}</p>
                                <p class="text-sm text-gray-600">{{ $quote['customer_code'] }}</p>
                            </div>
                        </td>
                        <td>
                            <span class="text-sm">{{ $quote['reference_code'] }}</span>
                        </td>
                        <td>
                            <span class="font-medium">{{ number_format($quote['total_amount'], 2, ',', '.') }} {{ $quote['currency'] }}</span>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-{{ $quote['status'] === 'draft' ? 'gray' : ($quote['status'] === 'sent' ? 'yellow' : 'green') }}">
                                {{ $quote['status'] === 'draft' ? 'Taslak' : ($quote['status'] === 'sent' ? 'Gönderildi' : 'Onaylandı') }}
                            </span>
                        </td>
                        <td>
                            <span class="text-sm">{{ $quote['items_count'] }}</span>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $quote['created_at']->format('d.m.Y') }}</span>
                        </td>
                        <td>
                            <div class="flex items-center space-x-2">
                                <a href="{{ route('admin.print-service-quotes.show', $quote['id']) }}" class="pd-btn pd-btn-sm pd-btn-light">Gör</a>
                                <a href="{{ route('admin.print-service-quotes.edit', $quote['id']) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                @if($quote['status'] === 'approved')
                                    <button class="pd-btn pd-btn-sm pd-btn-primary">Siparişe Çevir</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Baskı Teklif Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Toplam Teklif:</span>
                <span class="font-medium">{{ count($quotes) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Taslak:</span>
                <span class="font-medium text-gray-600">{{ collect($quotes)->where('status', 'draft')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Gönderildi:</span>
                <span class="font-medium text-yellow-600">{{ collect($quotes)->where('status', 'sent')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Onaylandı:</span>
                <span class="font-medium text-green-600">{{ collect($quotes)->where('status', 'approved')->count() }}</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <a href="{{ route('admin.print-service-quotes.create') }}" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block text-center">
                Yeni Teklif Oluştur
            </a>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                Teklifleri İndir
            </button>
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
