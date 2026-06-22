@extends('layouts.prodelya-admin')

@section('title', 'Loglar')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Loglar</h1>
        <p class="pd-muted mt-1">Senkronizasyon loglarını ve hataları görüntüleyin.</p>
    </div>
    <div>
        <button class="pd-btn pd-btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
            Yenile
        </button>
    </div>
</div>

<!-- Senkronizasyon Logları -->
<div class="pd-card mb-6">
    <div class="pd-card-body">
        <h3 class="pd-section-title mb-4">Senkronizasyon Logları</h3>
        
        <div class="overflow-x-auto">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kaynak</th>
                        <th>Tip</th>
                        <th>Başlangıç</th>
                        <th>Bitiş</th>
                        <th>Süre</th>
                        <th>Durum</th>
                        <th>Toplam</th>
                        <th>İşlenen</th>
                        <th>Hata</th>
                        <th>Yeni</th>
                        <th>Güncellenen</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($syncLogs as $log)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $log['supplier'] }}</div>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-sm pd-badge-gray">{{ $log['source'] }}</span>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-sm pd-badge-blue">{{ $log['sync_type'] === 'incremental' ? 'Artımlı' : 'Tam' }}</span>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $log['started_at']->format('d.m.Y H:i') }}</span>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $log['completed_at'] ? $log['completed_at']->format('H:i') : '-' }}</span>
                        </td>
                        <td>
                            <span class="text-sm">{{ $log['duration'] }}</span>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-{{ $log['status'] === 'success' ? 'green' : ($log['status'] === 'warning' ? 'yellow' : 'red') }}">
                                {{ $log['status'] === 'success' ? 'Başarılı' : ($log['status'] === 'warning' ? 'Uyarı' : 'Hata') }}
                            </span>
                        </td>
                        <td>
                            <span class="font-medium">{{ number_format($log['total_products'], 0, '.', '.') }}</span>
                        </td>
                        <td>
                            <span class="font-medium text-green-600">{{ number_format($log['processed_products'], 0, '.', '.') }}</span>
                        </td>
                        <td>
                            <span class="font-medium text-red-600">{{ number_format($log['failed_products'], 0, '.', '.') }}</span>
                        </td>
                        <td>
                            <span class="font-medium text-blue-600">{{ number_format($log['new_products'], 0, '.', '.') }}</span>
                        </td>
                        <td>
                            <span class="font-medium text-yellow-600">{{ number_format($log['updated_products'], 0, '.', '.') }}</span>
                        </td>
                        <td>
                            <div class="flex items-center space-x-2">
                                <button class="pd-btn pd-btn-sm pd-btn-light">Detay</button>
                                @if($log['status'] === 'warning' || $log['status'] === 'failed')
                                    <button class="pd-btn pd-btn-sm pd-btn-primary">Yeniden Dene</button>
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

<!-- Hata Logları -->
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-section-title mb-4">Hata Logları</h3>
        
        <div class="space-y-4">
            @foreach($errors as $error)
            <div class="border rounded-lg p-4 border-red-200 bg-red-50">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-3">
                        @php
                            $errorTypeLabel = $error['error_type'] === 'validation' ? 'Doğrulama' : ucfirst($error['error_type']);
                        @endphp
                        <span class="pd-badge pd-badge-{{ $error['error_type'] === 'validation' ? 'yellow' : 'red' }}">
                            {{ $errorTypeLabel }}
                        </span>
                        <div>
                            <p class="font-medium">{{ $error['supplier'] }} • {{ $error['source'] }}</p>
                            <p class="text-sm text-gray-600">Ürün ID: {{ $error['product_id'] }}</p>
                        </div>
                    </div>
                    @if(!$error['resolved_at'])
                        <button class="pd-btn pd-btn-sm pd-btn-primary">Çöz</button>
                    @endif
                </div>
                
                <div class="mb-3">
                    <p class="font-medium text-red-900">{{ $error['error_message'] }}</p>
                </div>
                
                @if($error['error_context'])
                <div class="mb-3">
                    <p class="text-sm text-gray-600 mb-1">Context:</p>
                    <div class="bg-white p-2 rounded border text-sm">
                        @foreach($error['error_context'] as $key => $value)
                        <span class="pd-badge pd-badge-sm pd-badge-gray">{{ $key }}: {{ $value }}</span>
                        @endforeach
                    </div>
                </div>
                @endif
                
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        {{ $error['created_at']->diffForHumans() }}
                        @if($error['resolved_at'])
                            • Çözüldü: {{ $error['resolved_at']->diffForHumans() }} • {{ $error['resolved_by'] }}
                        @endif
                    </div>
                    <div class="flex items-center space-x-2">
                        @if(!$error['resolved_at'])
                            <button class="pd-btn pd-btn-sm pd-btn-light">Yoksay</button>
                            <button class="pd-btn pd-btn-sm pd-btn-primary">Manuel Çöz</button>
                        @endif
                        <button class="pd-btn pd-btn-sm pd-btn-light">Detay</button>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Log Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Toplam Senkron:</span>
                <span class="font-medium">{{ count($syncLogs) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Başarılı:</span>
                <span class="font-medium text-green-600">{{ collect($syncLogs)->where('status', 'success')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Uyarı:</span>
                <span class="font-medium text-yellow-600">{{ collect($syncLogs)->where('status', 'warning')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Toplam Hata:</span>
                <span class="font-medium text-red-600">{{ count($errors) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Çözülen Hata:</span>
                <span class="font-medium text-green-600">{{ collect($errors)->whereNotNull('resolved_at')->count() }}</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Logları Temizle
            </button>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                Logları İndir
            </button>
        </div>
    </div>
</div>
@endsection
