@extends('layouts.prodelya-admin')

@section('title', 'Teslimat / Paket / Koli Takibi')
@section('page_title', 'Teslimat / Paket / Koli Takibi')
@section('page_subtitle', 'Siparişe dönüşmüş işlerde ürün bazlı teslimat, koli/paket ve belge takibini yönetin.')

@section('content')
@php
    $statusTone = static function (?string $status): string {
        return match ($status) {
            'teslimata_hazir', 'teslim_edildi' => 'green',
            'kismi_teslim_edildi' => 'amber',
            'teslimat_sorunu', 'iptal' => 'red',
            default => 'gray',
        };
    };

    $nextAction = static function ($delivery): string {
        if ((int) ($delivery->package_count ?? 0) <= 0) {
            return 'Koli bilgisi gir';
        }

        return match ($delivery->delivery_status) {
            'teslimat_bekliyor', 'teslimata_hazirlaniyor', 'teslimata_hazir' => 'Teslimat bilgisi gir',
            'kismi_teslim_edildi' => 'Kalan teslimatı planla',
            'teslimat_sorunu' => 'Belge / not kontrol et',
            'teslim_edildi' => 'Belgeyi arşivle',
            default => 'Teslimatı kontrol et',
        };
    };

    $packageSummary = static function ($delivery, array $snapshot, array $packageTypeLabels): string {
        $count = $delivery->package_count ?? data_get($snapshot, 'package_count');
        $units = $delivery->units_per_package ?? data_get($snapshot, 'units_per_package');
        $type = $delivery->package_type ?? data_get($snapshot, 'package_type');
        $typeLabel = $packageTypeLabels[$type] ?? null;

        if (!$count && !$units && !$typeLabel) {
            return '-';
        }

        $parts = [];
        if ($count) {
            $parts[] = $count . ' ' . ($typeLabel ? mb_strtolower($typeLabel) : 'paket');
        }
        if ($units) {
            $parts[] = 'koli içi ' . $units;
        }

        return implode(' · ', $parts) ?: ($typeLabel ?: '-');
    };
@endphp

<style>
    .dlv-v1-page { display: grid; gap: 16px; }
    .dlv-v1-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .dlv-v1-body { padding: 16px; }
    .dlv-v1-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .dlv-v1-metric { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .dlv-v1-metric-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .dlv-v1-metric-value { margin-top: 6px; font-size: 24px; font-weight: 700; color: #111827; }
    .dlv-v1-filters { display: grid; grid-template-columns: 1.5fr repeat(4, minmax(0, 1fr)) auto; gap: 10px; align-items: end; }
    .dlv-v1-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 700; }
    .dlv-v1-table-wrap { overflow: auto; }
    .dlv-v1-table { width: 100%; border-collapse: collapse; }
    .dlv-v1-table th, .dlv-v1-table td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .dlv-v1-table th { background: #fbfcfe; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .dlv-v1-order-cell { min-width: 230px; background: #fcfdff; border-left: 4px solid #d7e3ff; }
    .dlv-v1-order-code { font-size: 15px; font-weight: 700; color: #111827; }
    .dlv-v1-muted { color: #6b7280; font-size: 12px; }
    .dlv-v1-product-title { font-size: 13px; font-weight: 700; color: #111827; }
    .dlv-v1-stack { display: grid; gap: 6px; }
    .dlv-v1-chipline { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
    .dlv-v1-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .dlv-v1-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #6b7280; font-size: 12px; line-height: 1.45; }
    @media (max-width: 1400px) { .dlv-v1-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .dlv-v1-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 760px) { .dlv-v1-metrics, .dlv-v1-filters { grid-template-columns: 1fr; } }
</style>

<div class="dlv-v1-page">
    <section class="dlv-v1-metrics">
        @foreach($summaryCards as $card)
            <div class="dlv-v1-metric">
                <div class="dlv-v1-metric-label">{{ $card['label'] }}</div>
                <div class="dlv-v1-metric-value">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </section>

    <section class="dlv-v1-card">
        <div class="dlv-v1-body">
            <form method="GET" action="{{ route('admin.deliveries.index') }}" class="dlv-v1-filters">
                <div class="dlv-v1-field">
                    <label>Arama</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Sipariş no, iş formu, ürün">
                </div>
                <div class="dlv-v1-field">
                    <label>Teslimat Durumu</label>
                    <select name="status">
                        <option value="">Tümü</option>
                        @foreach($statusLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dlv-v1-field">
                    <label>Teslimat Tipi</label>
                    <select name="method">
                        <option value="">Tümü</option>
                        @foreach($methodLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['method'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dlv-v1-field">
                    <label>Müşteri</label>
                    <input type="text" name="customer" value="{{ $filters['customer'] ?? '' }}" placeholder="Müşteri adı">
                </div>
                <div class="dlv-v1-field">
                    <label>Limit</label>
                    <select name="limit">
                        @foreach([25, 50, 100, 250] as $limitOption)
                            <option value="{{ $limitOption }}" @selected((int) ($filters['limit'] ?? 50) === $limitOption)>{{ $limitOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dlv-v1-field">
                    <label>&nbsp;</label>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.deliveries.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <section class="dlv-v1-card">
        <div class="dlv-v1-body">
            <div class="dlv-v1-note">Teslimat truth kaynağı work form / order item delivery kaydıdır. Teslim edilecek adet, koli/paket ve belge durumunu hızlıca izleyin. Finans tarafında yalnız güvenli uyarı etiketi görünür.</div>
        </div>
        <div class="dlv-v1-table-wrap">
            <table class="dlv-v1-table">
                <thead>
                    <tr>
                        <th>Sipariş / Müşteri</th>
                        <th>Ürünler</th>
                        <th>Paket / Koli</th>
                        <th>Teslimat Durumu</th>
                        <th>Finans Uyarısı</th>
                        <th>Sıradaki İş</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($groupedRows as $orderId => $deliveries)
                        @foreach($deliveries as $index => $delivery)
                            @php
                                $snapshot = is_array($delivery->delivery_snapshot) ? $delivery->delivery_snapshot : [];
                                $customerName = $delivery->order?->customer?->legal_name ?: '-';
                                $warnings = array_values(array_filter((array) data_get($snapshot, 'readiness_warnings', [])));
                            @endphp
                            <tr>
                                @if($index === 0)
                                    <td class="dlv-v1-order-cell" rowspan="{{ $deliveries->count() }}">
                                        <div class="dlv-v1-order-code">{{ $delivery->order?->document_number ?: '-' }}</div>
                                        <div class="dlv-v1-muted" style="margin-top:4px;">{{ $customerName }}</div>
                                        <div class="dlv-v1-muted" style="margin-top:8px;">Takım: {{ $deliveries->count() }} ürün / teslimat satırı</div>
                                    </td>
                                @endif
                                <td>
                                    <div class="dlv-v1-product-title">{{ data_get($snapshot, 'product_name', $delivery->orderItem?->product_name ?: '-') }}</div>
                                    <div class="dlv-v1-muted">{{ data_get($snapshot, 'product_code', $delivery->orderItem?->product_code ?: '-') }}</div>
                                    <div class="dlv-v1-chipline">
                                        <span class="pd-badge pd-badge-gray">{{ rtrim(rtrim(number_format((float) $delivery->planned_quantity, 4, ',', '.'), '0'), ',') }} {{ data_get($snapshot, 'unit', $delivery->orderItem?->unit) }}</span>
                                        <span class="pd-badge pd-badge-gray">Teslim edilen: {{ rtrim(rtrim(number_format((float) $delivery->delivered_quantity, 4, ',', '.'), '0'), ',') }}</span>
                                        <span class="pd-badge pd-badge-gray">Kalan: {{ rtrim(rtrim(number_format((float) $delivery->remaining_quantity, 4, ',', '.'), '0'), ',') }}</span>
                                    </div>
                                    <div class="dlv-v1-muted" style="margin-top:6px;">İş Formu: {{ $delivery->workForm?->work_form_number ?: '-' }}</div>
                                    @if($warnings !== [])
                                        <div class="dlv-v1-stack" style="margin-top:8px;">
                                            @foreach($warnings as $warning)
                                                <span class="dlv-v1-muted">{{ $warning }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="dlv-v1-stack">
                                        <span>{{ $packageSummary($delivery, $snapshot, $packageTypeLabels) }}</span>
                                        @if(filled($delivery->package_note))
                                            <span class="dlv-v1-muted">{{ $delivery->package_note }}</span>
                                        @endif
                                        <div class="dlv-v1-chipline">
                                            <span class="pd-badge pd-badge-gray">Koli: {{ $delivery->package_count ?: '-' }}</span>
                                            <span class="pd-badge pd-badge-gray">Paket içi: {{ $delivery->units_per_package ?: '-' }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="dlv-v1-stack">
                                        <span class="pd-badge pd-badge-{{ $statusTone($delivery->delivery_status) }}">{{ $statusLabels[$delivery->delivery_status] ?? $delivery->delivery_status }}</span>
                                        <span class="dlv-v1-muted">{{ $methodLabels[$delivery->delivery_method] ?? 'Teslimat tipi girilmedi' }}</span>
                                    </div>
                                </td>
                                <td><span class="pd-badge pd-badge-gray">{{ $financialWarningLabels[$delivery->financial_warning ?: 'yok'] ?? $delivery->safeFinancialWarningLabel() }}</span></td>
                                <td>{{ $nextAction($delivery) }}</td>
                                <td>
                                    <div class="dlv-v1-actions">
                                        <a href="{{ route('admin.deliveries.show', $delivery) }}" class="pd-btn pd-btn-sm pd-btn-primary">Teslimatı Aç</a>
                                        <a href="{{ route('admin.deliveries.show', $delivery) }}#delivery-partial" class="pd-btn pd-btn-sm pd-btn-light">Kısmi Teslim</a>
                                        <a href="{{ route('admin.deliveries.show', $delivery) }}#delivery-complete" class="pd-btn pd-btn-sm pd-btn-light">Tamamı Teslim</a>
                                        <a href="{{ route('admin.deliveries.show', $delivery) }}#delivery-files" class="pd-btn pd-btn-sm pd-btn-light">Belge / Fotoğraf</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="7" class="dlv-v1-muted">Filtreye uygun teslimat kaydı bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Teslimat Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Listelenen kayıt</span><strong>{{ $rows->count() }}</strong></div>
            <div class="pd-status-row"><span>Sipariş grubu</span><strong>{{ $groupedRows->count() }}</strong></div>
            <div class="pd-status-row"><span>Durum filtresi</span><strong>{{ ($filters['status'] ?? '') !== '' ? ($statusLabels[$filters['status']] ?? $filters['status']) : 'Tümü' }}</strong></div>
            <div class="pd-status-row"><span>Teslimat tipi</span><strong>{{ ($filters['method'] ?? '') !== '' ? ($methodLabels[$filters['method']] ?? $filters['method']) : 'Tümü' }}</strong></div>
            <div class="pd-status-row"><span>Müşteri filtresi</span><strong>{{ ($filters['customer'] ?? '') !== '' ? $filters['customer'] : 'Tümü' }}</strong></div>
        </div>
        <div class="pd-side-note">
            Finans alanında yalnız güvenli uyarı etiketi görünür. Fiyat, bakiye, tahsilat ve teknik path bilgileri bu ekranın dışında tutulur.
        </div>
        <div style="display:none;">Teslimat Bekleyen</div>
    </div>
</div>
@endsection
