@extends('layouts.prodelya-admin')

@section('title', 'Grafik Yönetimi')
@section('page_title', 'Grafik Yönetimi')
@section('page_subtitle', 'Sipariş kalemleri için grafik görsellerini, müşteri onay durumunu ve üretime hazırlık sürecini yönetin.')
@section('hide_side_summary', '1')

@section('content')
<style>
    .gm-page {
        display: grid;
        gap: 16px;
        padding-bottom: 24px;
    }

    .gm-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .gm-card-body {
        padding: 16px;
    }

    .gm-summary {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .gm-metric {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fbfcfd;
        padding: 12px;
    }

    .gm-metric-label {
        color: #6b7280;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .gm-metric-value {
        margin-top: 7px;
        font-size: 24px;
        font-weight: 600;
        color: #111827;
    }

    .gm-metric-note {
        margin-top: 4px;
        color: #6b7280;
        font-size: 12px;
    }

    .gm-filters {
        display: grid;
        grid-template-columns: 1.4fr 1fr 1fr 1fr auto;
        gap: 10px;
        align-items: end;
    }

    .gm-field label {
        display: block;
        margin-bottom: 5px;
        color: #6b7280;
        font-size: 12px;
        font-weight: 600;
    }

    .gm-field input,
    .gm-field select {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 5px;
        padding: 9px 10px;
        font: inherit;
        background: #fff;
        color: #1f2937;
    }

    .gm-actions,
    .gm-inline-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .gm-btn,
    .gm-btn:visited {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 34px;
        padding: 8px 11px;
        border-radius: 5px;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #1f2937;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
    }

    .gm-btn-primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    .gm-table-wrap {
        overflow-x: auto;
    }

    .gm-table {
        width: 100%;
        border-collapse: collapse;
    }

    .gm-table th,
    .gm-table td {
        padding: 12px 10px;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: top;
        text-align: left;
    }

    .gm-table th {
        background: #fbfcfe;
        color: #6b7280;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .gm-table tr:last-child td {
        border-bottom: 0;
    }

    .gm-code {
        font-weight: 700;
        color: #111827;
    }

    .gm-muted {
        color: #6b7280;
    }

    .gm-product {
        display: flex;
        gap: 12px;
        min-width: 250px;
    }

    .gm-thumb,
    .graphic-index-product-thumb,
    .gm-thumb-placeholder {
        width: 84px;
        height: 84px;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
        flex-shrink: 0;
        background: #f8fafc;
    }

    .gm-thumb img,
    .graphic-index-product-image {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
        background: #fff;
        padding: 8px;
    }

    .gm-thumb-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6b7280;
        font-size: 11px;
        text-align: center;
        padding: 6px;
    }

    .gm-title {
        font-size: 14px;
        font-weight: 600;
        color: #111827;
    }

    .gm-sub {
        margin-top: 4px;
        color: #6b7280;
        font-size: 12px;
    }

    .gm-print-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 220px;
    }

    .gm-print-pill {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 5px;
        align-items: center;
        padding: 7px 9px;
        border-radius: 4px;
        border: 1px solid #e5e7eb;
        background: #fbfcfe;
        font-size: 12px;
    }

    .gm-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.2;
        white-space: nowrap;
    }

    .gm-badge-amber { background: #fff7ed; color: #b45309; }
    .gm-badge-blue { background: #eff6ff; color: #2563eb; }
    .gm-badge-green { background: #ecfdf3; color: #15803d; }
    .gm-badge-gray { background: #f3f4f6; color: #6b7280; }
    .gm-badge-red { background: #fef2f2; color: #b91c1c; }

    .gm-last-visual {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .gm-last-visual-thumb,
    .graphic-index-preview-thumb,
    .gm-last-visual-empty {
        width: 64px;
        height: 64px;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
        background: #f8fafc;
    }

    .gm-last-visual-thumb img,
    .graphic-index-preview-image {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
        background: #fff;
        padding: 6px;
    }

    .gm-last-visual-empty {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        color: #6b7280;
        text-align: center;
        padding: 6px;
    }

    .gm-status-form {
        margin: 0;
    }

    @media (max-width: 1180px) {
        .gm-summary {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .gm-filters {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 760px) {
        .gm-summary,
        .gm-filters {
            grid-template-columns: 1fr;
        }

        .gm-table-wrap {
            overflow: visible;
        }

        .gm-table,
        .gm-table thead,
        .gm-table tbody,
        .gm-table tr,
        .gm-table th,
        .gm-table td {
            display: block;
        }

        .gm-table thead {
            display: none;
        }

        .gm-table tr {
            border-bottom: 1px solid #e5e7eb;
        }

        .gm-table td::before {
            content: attr(data-label);
            display: block;
            margin-bottom: 5px;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
    }
</style>

<div class="gm-page">
    <div class="gm-card">
        <div class="gm-card-body">
            <div class="gm-summary">
                <div class="gm-metric">
                    <div class="gm-metric-label">Bekleyen Grafik</div>
                    <div class="gm-metric-value">{{ $summary['waiting'] }}</div>
                    <div class="gm-metric-note">İlk işlem bekleyen kalemler</div>
                </div>
                <div class="gm-metric">
                    <div class="gm-metric-label">Görsel Eklenecek</div>
                    <div class="gm-metric-value">{{ $summary['needs_visual'] }}</div>
                    <div class="gm-metric-note">Yükleme alanı bekleyenler</div>
                </div>
                <div class="gm-metric">
                    <div class="gm-metric-label">Onay Bekleyen</div>
                    <div class="gm-metric-value">{{ $summary['approval_waiting'] }}</div>
                    <div class="gm-metric-note">Onay veya revize bekleyenler</div>
                </div>
                <div class="gm-metric">
                    <div class="gm-metric-label">Revize İstenen</div>
                    <div class="gm-metric-value">{{ $summary['revision'] }}</div>
                    <div class="gm-metric-note">Revize işlemi gerekenler</div>
                </div>
                <div class="gm-metric">
                    <div class="gm-metric-label">Üretime Hazır</div>
                    <div class="gm-metric-value">{{ $summary['ready'] }}</div>
                    <div class="gm-metric-note">Grafik tarafı tamamlananlar</div>
                </div>
            </div>
        </div>
    </div>

    <div class="gm-card">
        <div class="gm-card-body">
            <form method="GET" action="{{ route('admin.graphics.index') }}" class="gm-filters">
                <div class="gm-field">
                    <label>Arama</label>
                    <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Sipariş no, İş Formu no, müşteri, ürün">
                </div>
                <div class="gm-field">
                    <label>Grafik Durumu</label>
                    <select name="status">
                        <option value="">Hepsi</option>
                        <option value="waiting_visual" @selected($filters['status'] === 'waiting_visual')>Görsel Bekliyor</option>
                        <option value="visual_uploaded" @selected($filters['status'] === 'visual_uploaded')>Görsel Eklendi</option>
                        <option value="customer_approval_waiting" @selected($filters['status'] === 'customer_approval_waiting')>Onay Bekliyor</option>
                        <option value="revision_requested" @selected($filters['status'] === 'revision_requested')>Revize İstendi</option>
                        <option value="approved" @selected($filters['status'] === 'approved')>Onaylandı</option>
                        <option value="production_ready" @selected($filters['status'] === 'production_ready')>Üretime Hazır</option>
                    </select>
                </div>
                <div class="gm-field">
                    <label>Onay Durumu</label>
                    <select name="approval_status">
                        <option value="">Hepsi</option>
                        <option value="waiting" @selected($filters['approval_status'] === 'waiting')>Onay Bekliyor</option>
                        <option value="revision_requested" @selected($filters['approval_status'] === 'revision_requested')>Revize İstendi</option>
                        <option value="approved" @selected($filters['approval_status'] === 'approved')>Onaylandı</option>
                    </select>
                </div>
                <div class="gm-field">
                    <label>Müşteriye Açık Görsel</label>
                    <select name="customer_visible_visual">
                        <option value="">Hepsi</option>
                        <option value="yes" @selected($filters['customer_visible_visual'] === 'yes')>Var</option>
                        <option value="no" @selected($filters['customer_visible_visual'] === 'no')>Yok</option>
                    </select>
                </div>
                <div class="gm-actions">
                    <button type="submit" class="gm-btn gm-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.graphics.index') }}" class="gm-btn">Temizle</a>
                </div>
            </form>
        </div>
    </div>

    <div class="gm-card">
        <div class="gm-card-body">
            <div class="gm-table-wrap">
                <table class="gm-table">
                    <thead>
                    <tr>
                        <th>Sipariş / İş Formu</th>
                        <th>Müşteri</th>
                        <th>Ürün / Görsel</th>
                        <th>Baskı Operasyonları</th>
                        <th>Grafik / Onay</th>
                        <th>Son Görsel</th>
                        <th>Son Müşteri Hareketi</th>
                        <th>Sıradaki İş</th>
                        <th>Aksiyon</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td data-label="Sipariş / İş Formu">
                                <div class="gm-code">{{ $row['order_number'] }}</div>
                                <div class="gm-muted">{{ $row['work_form_number'] }}</div>
                            </td>
                            <td data-label="Müşteri">
                                <div class="gm-title">{{ $row['customer_name'] }}</div>
                            </td>
                            <td data-label="Ürün / Görsel">
                                <div class="gm-product">
                                    @if($row['image_url'])
                                        <div class="gm-thumb graphic-index-product-thumb"><img class="graphic-index-product-image" src="{{ $row['image_url'] }}" alt="{{ $row['product_name'] }}"></div>
                                    @else
                                        <div class="gm-thumb-placeholder graphic-index-product-thumb">Ürün<br>Görseli</div>
                                    @endif
                                    <div>
                                        <div class="gm-title">{{ $row['product_name'] }}</div>
                                        <div class="gm-sub">SKU: {{ $row['product_code'] }}</div>
                                        @if($row['work_folder'])
                                            <div class="gm-sub">Çalışma Klasörü: {{ $row['work_folder']['display_path'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td data-label="Baskı Operasyonları">
                                <div class="gm-print-list">
                                    @foreach($row['print_lines'] as $printLine)
                                        <span class="gm-print-pill">{{ $printLine }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td data-label="Grafik / Onay">
                                <span class="gm-badge {{ match($row['graphic_status_key']) {
                                    'production_ready', 'approved' => 'gm-badge-green',
                                    'visual_uploaded', 'customer_approval_waiting' => 'gm-badge-blue',
                                    'revision_requested' => 'gm-badge-red',
                                    default => 'gm-badge-amber',
                                } }}">{{ $row['graphic_status_label'] }}</span>
                                <div class="gm-sub" style="margin-top:6px;">{{ $row['approval_status_label'] }}</div>
                                <div class="gm-sub">
                                    {{ $row['approval_waiting_count'] }} bekliyor · {{ $row['approval_revision_count'] }} revize · {{ $row['approval_approved_count'] }} onay
                                </div>
                            </td>
                            <td data-label="Son Görsel">
                                    <div class="gm-last-visual">
                                        @if($row['last_visual_url'])
                                        <div class="gm-last-visual-thumb graphic-index-preview-thumb"><img class="graphic-index-preview-image" src="{{ $row['last_visual_url'] }}" alt="{{ $row['last_visual_name'] }}"></div>
                                        @else
                                        <div class="gm-last-visual-empty graphic-index-preview-thumb">Görsel Yok</div>
                                        @endif
                                    <div class="gm-sub">{{ $row['last_visual_name'] ?: '-' }}</div>
                                </div>
                            </td>
                            <td data-label="Son Müşteri Hareketi">
                                <div class="gm-sub">Son gönderim: {{ $row['latest_approval_sent_at'] ?: '-' }}</div>
                                <div class="gm-sub">Son yanıt: {{ $row['latest_customer_response_at'] ?: '-' }}</div>
                            </td>
                            <td data-label="Sıradaki İş">
                                <div class="gm-sub">{{ $row['next_action'] }}</div>
                                <div class="gm-sub">{{ $row['production_ready_state_label'] }}</div>
                                @if($row['work_folder'])
                                    <div class="gm-sub">
                                        <span class="gm-badge {{ $row['work_folder']['has_error'] ? 'gm-badge-red' : ($row['work_folder']['status'] === 'created' ? 'gm-badge-green' : 'gm-badge-gray') }}">
                                            {{ $row['work_folder']['status_label'] }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td data-label="Aksiyon">
                                <div class="gm-inline-actions">
                                    <a href="{{ $row['detail_url'] }}" class="gm-btn gm-btn-primary">Düzenle</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="gm-muted">Grafik iş listesinde gösterilecek aktif İş Formu bulunamadı.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
