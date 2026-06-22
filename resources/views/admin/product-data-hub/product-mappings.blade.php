@extends('layouts.prodelya-admin')

@section('title', 'Ürün Eşleme / Conflict')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Ürün Eşleme / Conflict</h1>
        <p class="pd-muted mt-1">Ürün çakışmalarını çözün ve eşlemeleri yönetin.</p>
    </div>
    <div>
        <button class="pd-btn pd-btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Conflict Çöz
        </button>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        <div class="space-y-6">
            @foreach($conflicts as $conflict)
            <div class="border rounded-lg p-4 {{ $conflict['resolution'] === 'pending' ? 'border-yellow-300 bg-yellow-50' : 'border-green-300 bg-green-50' }}">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h4 class="font-medium text-gray-900">{{ $conflict['product_code'] }}</h4>
                        <p class="text-sm text-gray-600">
                            {{ implode(' vs ', $conflict['suppliers']) }} • 
                            {{ $conflict['conflict_type'] }} • 
                            {{ $conflict['detected_at']->diffForHumans() }}
                        </p>
                    </div>
                    <span class="pd-badge pd-badge-{{ $conflict['resolution'] === 'pending' ? 'yellow' : 'green' }}">
                        {{ $conflict['resolution'] === 'pending' ? 'Bekliyor' : 'Çözüldü' }}
                    </span>
                </div>
                
                <div class="space-y-3">
                    @foreach($conflict['products'] as $product)
                    <div class="flex items-center justify-between p-3 bg-white rounded border">
                        <div class="flex items-center space-x-4">
                            <div>
                                <p class="font-medium">{{ $product['supplier'] }}</p>
                                <p class="text-sm text-gray-600">{{ $product['name'] }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium">{{ number_format($product['price'], 2, ',', '.') }} TL</p>
                                <p class="text-sm text-gray-600">{{ number_format($product['stock'], 0, '.', '.') }} Adet</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button class="pd-btn pd-btn-sm pd-btn-light">Detay</button>
                            <button class="pd-btn pd-btn-sm pd-btn-primary">Seç</button>
                        </div>
                    </div>
                    @endforeach
                </div>
                
                @if($conflict['resolution'] === 'pending')
                <div class="mt-4 flex items-center space-x-2">
                    <button class="pd-btn pd-btn-sm pd-btn-primary">Otomatik Çöz</button>
                    <button class="pd-btn pd-btn-sm pd-btn-light">Manuel Çöz</button>
                    <button class="pd-btn pd-btn-sm pd-btn-danger">Görmezden</button>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Conflict Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Toplam Conflict:</span>
                <span class="font-medium">{{ count($conflicts) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Bekleyen:</span>
                <span class="font-medium text-yellow-600">{{ collect($conflicts)->where('resolution', 'pending')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Çözülen:</span>
                <span class="font-medium text-green-600">{{ collect($conflicts)->where('resolution', 'resolved')->count() }}</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Toplu Çözüm
            </button>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                Conflict Kuralları
            </button>
        </div>
    </div>
</div>
@endsection
