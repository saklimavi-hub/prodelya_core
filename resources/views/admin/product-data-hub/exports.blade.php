@extends('layouts.prodelya-admin')

@section('title', 'Export / Web Feed')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Export / Web Feed</h1>
        <p class="pd-muted mt-1">Yayın ve dışa aktarım seçenekleri tenant panelinde bilgilendirme amaçlı gösterilir; açılış ve kurulum Super Admin tarafından yapılır.</p>
    </div>
    <div>
        <button class="pd-btn pd-btn-light" disabled title="Yayın modülü bu tenantta kullanıma açık değil">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Yeni Yayın Kurulumu
        </button>
    </div>
</div>

<div class="pd-alert pd-alert-info mb-6">
    Bu sayfadaki kartlar yalnız modül özeti gösterir. Tenant panelinden doğrudan export başlatma, açma veya planlama yapılmaz.
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    @foreach($exports as $export)
    <div class="pd-card">
        <div class="pd-card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-medium text-gray-900">{{ $export['name'] }}</h3>
                @if($export['is_premium'])
                    <span class="pd-badge pd-badge-purple">Premium</span>
                @endif
            </div>
            
            <p class="text-sm text-gray-600 mb-4">{{ $export['description'] }}</p>
            
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Durum:</span>
                    <span class="pd-badge pd-badge-{{ $export['is_premium'] ? 'purple' : 'gray' }}">
                        {{ $export['is_premium'] ? 'Ek Modül' : 'Kurulum Gerekli' }}
                    </span>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Son Export:</span>
                    <span class="text-sm text-gray-600">
                        {{ $export['last_export'] ? $export['last_export']->diffForHumans() : '-' }}
                    </span>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Ürün Sayısı:</span>
                    <span class="font-medium">{{ number_format($export['export_count'], 0, '.', '.') }}</span>
                </div>
            </div>
            
            <div class="mt-4 space-y-2">
                <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block" disabled title="Kurulum yalnız Super Admin tarafından açılır">Kurulum Bekliyor</button>
                <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block" disabled title="Tenant panelinde ayar akışı açık değil">Tenanttan Yönetilemez</button>
            </div>
        </div>
    </div>
    @endforeach
</div>

<!-- Premium Modül Bilgisi -->
<div class="pd-card mt-6">
    <div class="pd-card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="pd-section-title">Premium Modüller</h3>
            <span class="pd-badge pd-badge-purple">Add-on</span>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="p-4 bg-purple-50 rounded-lg border border-purple-200">
                <div class="flex items-center mb-3">
                    <svg class="w-6 h-6 text-purple-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    <h4 class="font-medium text-purple-900">Real-time Sync</h4>
                </div>
                <p class="text-sm text-purple-700 mb-3">
                    Gerçek zamanlı ürün senkronizasyonu ile anlık veri güncellemeleri.
                </p>
                <ul class="text-sm text-purple-700 space-y-1">
                    <li>• Anlık stok güncellemeleri</li>
                    <li>• Fiyat değişim bildirimleri</li>
                    <li>• WebSocket tabanlı</li>
                </ul>
                <button class="pd-btn pd-btn-light pd-btn-sm mt-3" disabled title="Detay akışı satış ve kurulum sonrası açılır">Detay Bekliyor</button>
            </div>
            
            <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                <div class="flex items-center mb-3">
                    <svg class="w-6 h-6 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 3H4a2 2 0 00-2 2v14a2 2 0 002 2h6m-6 0l6-6m6 6v6a2 2 0 01-2 2H4"></path>
                    </svg>
                    <h4 class="font-medium text-blue-900">API Feed</h4>
                </div>
                <p class="text-sm text-blue-700 mb-3">
                    REST API üzerinden ürün feed'i ile harici sistem entegrasyonu.
                </p>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li>• RESTful API</li>
                    <li>• OAuth 2.0 desteği</li>
                    <li>• Rate limiting</li>
                </ul>
                <button class="pd-btn pd-btn-light pd-btn-sm mt-3" disabled title="Detay akışı satış ve kurulum sonrası açılır">Detay Bekliyor</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Export Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Aktif Export:</span>
                <span class="font-medium text-green-600">0</span>
            </div>
            <div class="pd-summary-row">
                <span>Premium:</span>
                <span class="font-medium text-purple-600">{{ collect($exports)->where('is_premium', true)->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Pasif:</span>
                <span class="font-medium text-gray-600">{{ count($exports) }}</span>
            </div>
        </div>
        
        <div class="pd-alert pd-alert-info mt-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm">
                        Export ve Web Feed özellikleri kurulum kontrollü modüllerdir. Tenant panelindeki kartlar bilgilendirme içindir.
                    </p>
                </div>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block" disabled title="Toplu yayın akışı tenant panelinde açık değil">
                Toplu Yayın Yok
            </button>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block" disabled title="Planlama akışı tenant panelinde açık değil">
                Planlama Kapalı
            </button>
        </div>
    </div>
</div>
@endsection
