@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Kaynakları')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Tedarikçi Kaynakları</h1>
        <p class="pd-muted mt-1">Tedarikçi veri kaynaklarını yönetin ve yapılandırın.</p>
    </div>
    <div>
        <button class="pd-btn pd-btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Yeni Kaynak Ekle
        </button>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        <div class="overflow-x-auto">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kaynak Tipi</th>
                        <th>Kaynak URL / Endpoint</th>
                        <th>Format</th>
                        <th>Ürün Node</th>
                        <th>Son Senkron</th>
                        <th>Ürün Sayısı</th>
                        <th>Durum</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sources as $source)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $source['supplier'] }}</div>
                        </td>
                        <td>
                            @php
                                $sourceTypeBadge = match ($source['source_type']) {
                                    'XML' => 'blue',
                                    'API' => 'green',
                                    'CSV' => 'yellow',
                                    default => 'purple',
                                };
                            @endphp
                            <span class="pd-badge pd-badge-{{ $sourceTypeBadge }}">
                                {{ $source['source_type'] }}
                            </span>
                        </td>
                        <td>
                            <div class="text-sm text-gray-600 max-w-xs truncate">{{ $source['url'] }}</div>
                        </td>
                        <td>
                            <span class="text-sm">{{ $source['format'] }}</span>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $source['product_node'] ?: '-' }}</span>
                        </td>
                        <td>
                            <span class="text-sm">{{ $source['last_sync']->diffForHumans() }}</span>
                        </td>
                        <td>
                            <span class="font-medium">{{ number_format($source['product_count'], 0, '.', '.') }}</span>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-{{ $source['status'] === 'active' ? 'green' : ($source['status'] === 'warning' ? 'yellow' : 'gray') }}">
                                {{ $source['status'] === 'active' ? 'Aktif' : ($source['status'] === 'warning' ? 'Uyarı' : 'Pasif') }}
                            </span>
                        </td>
                        <td>
                            <div class="flex items-center space-x-2">
                                <button class="pd-btn pd-btn-sm pd-btn-light">Düzenle</button>
                                <button class="pd-btn pd-btn-sm pd-btn-primary">Senkron Et</button>
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
        <div class="pd-summary-title">Kaynak Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Toplam Kaynak:</span>
                <span class="font-medium">{{ count($sources) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Aktif Kaynak:</span>
                <span class="font-medium text-green-600">{{ collect($sources)->where('status', 'active')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Toplam Ürün:</span>
                <span class="font-medium">{{ number_format(collect($sources)->sum('product_count'), 0, '.', '.') }}</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Tümünü Senkron Et
            </button>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                Kaynak Test Et
            </button>
        </div>
    </div>
</div>
@endsection
