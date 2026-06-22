<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>{{ $workForm->work_form_number }} PDF</title>
    <style>
        @page {
            size: A4;
            margin: 10mm 11mm;
        }

        body {
            margin: 0;
            color: #1f2937;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            background: #fff;
        }

        .wf-paper {
            border: 1px solid #d7dce3;
        }

        .wf-header {
            width: 100%;
            border-bottom: 1px solid #e8ebef;
            padding: 10px 12px 8px;
        }

        .wf-header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .wf-header-table td {
            vertical-align: top;
        }

        .wf-tenant-name {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .wf-center {
            text-align: center;
        }

        .wf-title {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .wf-note {
            color: #6b7280;
            font-size: 9px;
        }

        .wf-price-note {
            display: inline-block;
            margin-top: 4px;
            padding: 3px 8px;
            border: 1px solid #d7dce3;
            border-radius: 999px;
            font-size: 9px;
            color: #4b5563;
            background: #fafbfc;
        }

        .wf-qr-box {
            width: 68px;
            height: 68px;
            border: 1px solid #ced4dd;
            border-radius: 8px;
            padding: 4px;
            background: #fff;
            text-align: center;
        }

        .wf-qr-box img {
            width: 58px;
            height: 58px;
        }

        .wf-meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-left: 8px;
        }

        .wf-meta-table td {
            padding: 2px 0;
            font-size: 10px;
            vertical-align: top;
        }

        .wf-meta-label {
            width: 82px;
            color: #6b7280;
        }

        .wf-meta-value {
            font-weight: bold;
            word-break: break-word;
        }

        .wf-section {
            padding: 9px 12px;
            border-bottom: 1px solid #e8ebef;
            page-break-inside: avoid;
        }

        .wf-section-title {
            margin: 0 0 8px;
            padding: 5px 8px;
            background: #f1f3f5;
            border: 1px solid #e8ebef;
            border-radius: 7px;
            font-size: 10px;
            font-weight: bold;
        }

        .wf-two-col {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px 0;
            margin: 0 -10px;
        }

        .wf-box {
            border: 1px solid #e8ebef;
            border-radius: 8px;
            background: #fcfcfd;
            padding: 8px;
        }

        .wf-mini-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 6px 6px;
        }

        .wf-field-label {
            display: block;
            color: #6b7280;
            font-size: 9px;
            margin-bottom: 2px;
        }

        .wf-field-value {
            min-height: 24px;
            border: 1px solid #e8ebef;
            border-radius: 6px;
            background: #fff;
            padding: 5px 7px;
            font-size: 10px;
        }

        .wf-product-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px 0;
        }

        .wf-product-image,
        .wf-product-placeholder {
            width: 84px;
            height: 84px;
            border: 1px solid #e8ebef;
            border-radius: 8px;
            background: #fafafa;
            text-align: center;
            vertical-align: middle;
        }

        .wf-product-image img {
            width: 84px;
            height: 84px;
            object-fit: cover;
        }

        .wf-product-name {
            font-size: 12px;
            font-weight: bold;
            margin: 0 0 6px;
        }

        .wf-badge {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: 9px;
            border: 1px solid transparent;
            margin-right: 4px;
            margin-top: 4px;
        }

        .wf-badge-blue { background: #e8f0ff; color: #2563eb; border-color: #cfe0ff; }
        .wf-badge-green { background: #eaf8ef; color: #15803d; border-color: #cdecd6; }
        .wf-badge-amber { background: #fff4df; color: #b45309; border-color: #f6dfb3; }
        .wf-badge-purple { background: #f3ebff; color: #7c3aed; border-color: #e0d0ff; }
        .wf-badge-gray { background: #f3f4f6; color: #6b7280; border-color: #e2e5ea; }

        .wf-table {
            width: 100%;
            border-collapse: collapse;
        }

        .wf-table th,
        .wf-table td {
            border: 1px solid #e8ebef;
            padding: 6px 7px;
            font-size: 9px;
            text-align: left;
            vertical-align: top;
        }

        .wf-table th {
            background: #f8fafc;
            font-weight: bold;
        }

        .wf-graphic-table,
        .wf-ops-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px 0;
        }

        .wf-visual-box,
        .wf-photo-box,
        .wf-doc-box {
            min-height: 90px;
            border: 1px dashed #cad1db;
            border-radius: 8px;
            background: #fafafa;
            text-align: center;
            vertical-align: middle;
            color: #6b7280;
            font-size: 9px;
            padding: 8px;
        }

        .wf-visual-box img,
        .wf-thumb img {
            max-width: 100%;
            max-height: 110px;
        }

        .wf-thumb-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            margin: 6px -8px 0;
        }

        .wf-thumb {
            border: 1px solid #e8ebef;
            border-radius: 8px;
            background: #fff;
            padding: 7px;
            width: 110px;
            vertical-align: top;
        }

        .wf-thumb-type {
            font-weight: bold;
            font-size: 9px;
            margin-top: 4px;
        }

        .wf-thumb-meta {
            color: #6b7280;
            font-size: 8.5px;
            margin-top: 2px;
            word-break: break-word;
        }

        .wf-summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin: 0 -8px;
        }

        .wf-summary-pill {
            border: 1px solid #e8ebef;
            border-radius: 8px;
            background: #fff;
            padding: 7px 8px;
            width: 20%;
            vertical-align: top;
        }

        .wf-summary-pill strong {
            display: block;
            font-size: 10px;
            margin-bottom: 2px;
        }

        .wf-history-row {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .wf-history-row td {
            border: 1px solid #e8ebef;
            border-radius: 8px;
            background: #fff;
            padding: 6px 8px;
            font-size: 9px;
            vertical-align: top;
        }

        .wf-footer {
            padding: 9px 12px 11px;
            font-size: 9px;
            color: #6b7280;
        }
    </style>
</head>
<body>
@php
    $renderTimestamp = $renderedAt->format('d.m.Y H:i');
    $updatedTimestamp = optional($workForm->updated_at)->format('d.m.Y H:i') ?: '-';
    $createdTimestamp = optional($workForm->created_at)->format('d.m.Y H:i') ?: '-';
    $productImage = data_get($productSnapshot, 'image_url');
    $graphicStatus = data_get($graphicSnapshot, 'status', 'bekliyor');
    $approvalStatus = data_get($graphicSnapshot, 'approval_status', 'bekliyor');
    $productionStatus = data_get($productionSnapshot, 'status', 'bekliyor');
    $qualityControlStatus = data_get($productionSnapshot, 'qc_status', 'bekliyor');
    $deliveryStatus = data_get($deliverySnapshot, 'status', 'bekliyor');
@endphp

<div class="wf-paper">
    <div class="wf-header">
        <table class="wf-header-table">
            <tr>
                <td style="width:28%;">
                    <div class="wf-tenant-name">{{ $tenantName }}</div>
                    <div class="wf-note">Firma içi operasyon takip formu</div>
                </td>
                <td style="width:36%;" class="wf-center">
                    <h1 class="wf-title">İŞ FORMU</h1>
                    <div class="wf-note">Ürün bazlı operasyon renderı</div>
                    <div class="wf-price-note">Fiyat bilgisi içermez</div>
                </td>
                <td style="width:36%;">
                    <table>
                        <tr>
                            <td class="wf-qr-box"><img src="{{ $pdfQrDataUri }}" alt="İş Formu Takip QR"></td>
                            <td>
                                <table class="wf-meta-table">
                                    <tr><td class="wf-meta-label">İş Formu No</td><td class="wf-meta-value">{{ $workForm->work_form_number }}</td></tr>
                                    <tr><td class="wf-meta-label">Sipariş No</td><td class="wf-meta-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</td></tr>
                                    <tr><td class="wf-meta-label">Kalem</td><td class="wf-meta-value">{{ str_pad((string) $workForm->item_sequence, 2, '0', STR_PAD_LEFT) }}</td></tr>
                                    <tr><td class="wf-meta-label">Versiyon</td><td class="wf-meta-value">v{{ $workForm->version }}</td></tr>
                                    <tr><td class="wf-meta-label">Public Link</td><td class="wf-note">{{ $trackingUrl }}</td></tr>
                                    <tr><td class="wf-meta-label">QR Notu</td><td class="wf-note">QR ile müşteri takip ekranı açılır.</td></tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="wf-section">
        <div class="wf-section-title">Tedarik</div>
        <div class="wf-box">
            <table class="wf-mini-table">
                <tr>
                    <td><span class="wf-field-label">Tedarik Durumu</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'procurement_status_label', '-') }}</div></td>
                    <td><span class="wf-field-label">Kaynak Tipi</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'fulfillment_source_label', '-') }}</div></td>
                </tr>
                <tr>
                    <td><span class="wf-field-label">Gelen Miktar</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'received_quantity', 0) }}</div></td>
                    <td><span class="wf-field-label">Kalan Miktar</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'remaining_quantity', 0) }}</div></td>
                </tr>
                <tr>
                    <td><span class="wf-field-label">Müşteriye Görünen Durum</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'public_status_label', '-') }}</div></td>
                    <td><span class="wf-field-label">Stok İşleme Modu</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'stock_handling_mode_label', '-') }}</div></td>
                </tr>
                <tr>
                    <td colspan="2"><span class="wf-field-label">Operasyonel Not</span><div class="wf-field-value">{{ data_get($procurementSnapshot, 'note', 'Tedarik notu bulunmuyor.') ?: 'Tedarik notu bulunmuyor.' }}</div></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="wf-section">
        <table class="wf-two-col">
            <tr>
                <td style="width:50%;">
                    <div class="wf-section-title">1. Sipariş ve Müşteri Bilgileri</div>
                    <div class="wf-box">
                        <table class="wf-mini-table">
                            <tr>
                                <td><span class="wf-field-label">Sipariş No</span><div class="wf-field-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</div></td>
                                <td><span class="wf-field-label">Kaynak Teklif No</span><div class="wf-field-value">{{ data_get($orderSnapshot, 'source_quote_number', '-') }}</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Sipariş Tarihi</span><div class="wf-field-value">{{ data_get($orderSnapshot, 'order_date', '-') }}</div></td>
                                <td><span class="wf-field-label">Teslim Tarihi</span><div class="wf-field-value">-</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Müşteri</span><div class="wf-field-value">{{ data_get($customerSnapshot, 'company_name', '-') }}</div></td>
                                <td><span class="wf-field-label">Yetkili / Telefon</span><div class="wf-field-value">{{ data_get($customerSnapshot, 'contact_name', '-') }} @if(filled(data_get($customerSnapshot, 'phone'))) / {{ data_get($customerSnapshot, 'phone') }} @endif</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Teslimat Tipi</span><div class="wf-field-value">{{ data_get($orderSnapshot, 'delivery_type', '-') }}</div></td>
                                <td><span class="wf-field-label">Belge Türü</span><div class="wf-field-value">{{ ucfirst((string) data_get($orderSnapshot, 'invoice_status', '-')) }}</div></td>
                            </tr>
                            <tr>
                                <td colspan="2"><span class="wf-field-label">Kısa Not</span><div class="wf-field-value">{{ data_get($orderSnapshot, 'notes', '-') ?: '-' }}</div></td>
                            </tr>
                        </table>
                    </div>
                </td>
                <td style="width:50%;">
                    <div class="wf-section-title">2. Ürün Bilgileri</div>
                    <div class="wf-box">
                        <table class="wf-product-table">
                            <tr>
                                <td style="width:94px;">
                                    @if(filled($productImage))
                                        <div class="wf-product-image"><img src="{{ $productImage }}" alt="Ürün görseli"></div>
                                    @else
                                        <div class="wf-product-placeholder">Görsel yok</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="wf-product-name">{{ data_get($productSnapshot, 'product_name', '-') }}</div>
                                    <table class="wf-mini-table">
                                        <tr>
                                            <td><span class="wf-field-label">Ürün Kodu / SKU</span><div class="wf-field-value">{{ data_get($productSnapshot, 'product_code', '-') }}</div></td>
                                            <td><span class="wf-field-label">Varyant</span><div class="wf-field-value">{{ data_get($productSnapshot, 'variant_name', '-') }}</div></td>
                                        </tr>
                                        <tr>
                                            <td><span class="wf-field-label">Miktar</span><div class="wf-field-value">{{ data_get($productSnapshot, 'quantity', '-') }}</div></td>
                                            <td><span class="wf-field-label">Birim</span><div class="wf-field-value">{{ data_get($productSnapshot, 'unit', '-') }}</div></td>
                                        </tr>
                                        <tr>
                                            <td><span class="wf-field-label">Tedarikçi / Kaynak</span><div class="wf-field-value">{{ data_get($productSnapshot, 'supplier_name', '-') ?: '-' }}</div></td>
                                            <td><span class="wf-field-label">Ürün Açıklaması</span><div class="wf-field-value">{{ data_get($productSnapshot, 'warning_labels.0', '-') ?: '-' }}</div></td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="wf-section">
        <div class="wf-section-title">3. Baskı Bilgileri</div>
        <table class="wf-table">
            <thead>
            <tr>
                <th>No</th>
                <th>Baskı Türü</th>
                <th>Seçenek</th>
                <th>Üretim Tipi</th>
                <th>Firma / Birim</th>
                <th>Miktar</th>
                <th>Baskı Adı / Not</th>
            </tr>
            </thead>
            <tbody>
            @forelse($printSnapshot as $printRow)
                <tr>
                    <td>{{ data_get($printRow, 'sequence', '-') }}</td>
                    <td>{{ data_get($printRow, 'print_type', '-') }}</td>
                    <td>{{ data_get($printRow, 'print_option', '-') }}</td>
                    <td>{{ data_get($printRow, 'production_type', '-') }}</td>
                    <td>{{ data_get($printRow, 'subcontractor_company_name', 'Kendi üretim') }}</td>
                    <td>{{ data_get($printRow, 'print_quantity', '-') }} {{ data_get($productSnapshot, 'unit', '') }}</td>
                    <td>{{ data_get($printRow, 'note', '-') ?: data_get($printRow, 'production_note', '-') }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Bu kalem için baskı operasyonu bulunmuyor.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="wf-section">
        <div class="wf-section-title">4. Grafik</div>
        <table class="wf-graphic-table">
            <tr>
                <td style="width:130px;">
                    @if(!empty($primaryGraphicAttachment['inline_src']))
                        <div class="wf-visual-box"><img src="{{ $primaryGraphicAttachment['inline_src'] }}" alt="{{ $primaryGraphicAttachment['file_name'] }}"></div>
                    @else
                        <div class="wf-visual-box">Grafik görseli henüz eklenmedi</div>
                    @endif
                </td>
                <td>
                    <table class="wf-mini-table">
                        <tr>
                            <td><span class="wf-field-label">Grafik Durumu</span><div class="wf-field-value">{{ data_get($graphicSnapshot, 'status_label', ucfirst(str_replace('_', ' ', $graphicStatus))) }}</div></td>
                            <td><span class="wf-field-label">Onay Türü</span><div class="wf-field-value">{{ data_get($graphicSnapshot, 'approval_type', '-') ?: '-' }}</div></td>
                        </tr>
                        <tr>
                            <td><span class="wf-field-label">Onay Durumu</span><div class="wf-field-value">{{ data_get($graphicSnapshot, 'approval_status_label', ucfirst(str_replace('_', ' ', $approvalStatus))) }}</div></td>
                            <td><span class="wf-field-label">Grafiker</span><div class="wf-field-value">{{ data_get($graphicSnapshot, 'designer_name', '-') ?: '-' }}</div></td>
                        </tr>
                        <tr>
                            <td><span class="wf-field-label">Görsel Dosyası</span><div class="wf-field-value">{{ $primaryGraphicAttachment['file_name'] ?? '-' }}</div></td>
                            <td><span class="wf-field-label">Onaylayan</span><div class="wf-field-value">{{ data_get($graphicSnapshot, 'approved_by_name', '-') ?: '-' }}</div></td>
                        </tr>
                        <tr>
                            <td colspan="2"><span class="wf-field-label">Onay / Revize Notu</span><div class="wf-field-value">{{ data_get($graphicSnapshot, 'revision_note', data_get($graphicSnapshot, 'short_note', '-')) ?: '-' }}</div></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="wf-section">
        <table class="wf-two-col">
            <tr>
                <td style="width:50%;">
                    <div class="wf-section-title">5. Üretim</div>
                    <div class="wf-box">
                        <table class="wf-mini-table">
                            <tr>
                                <td><span class="wf-field-label">Üretim Durumu</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'production_status_label', ucfirst(str_replace('_', ' ', $productionStatus))) }}</div></td>
                                <td><span class="wf-field-label">Kalite Kontrol</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'qc_status_label', ucfirst(str_replace('_', ' ', $qualityControlStatus))) }}</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Üretim Tipi</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'production_type_label', collect($printSnapshot)->pluck('production_type')->filter()->unique()->implode(', ') ?: '-') }}</div></td>
                                <td><span class="wf-field-label">Üretim / Fason Firma</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'production_company_name', collect($printSnapshot)->pluck('subcontractor_company_name')->filter()->unique()->implode(', ') ?: 'Kendi üretim') }}</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Planlanan / Tamamlanan</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'planned_quantity', 0) }} / {{ data_get($productionSnapshot, 'completed_quantity', 0) }}</div></td>
                                <td><span class="wf-field-label">Kalan / Klişe Durumu</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'remaining_quantity', 0) }} / {{ data_get($productionSnapshot, 'cliche_status_label', '-') }}</div></td>
                            </tr>
                            <tr>
                                <td colspan="2"><span class="wf-field-label">Üretim Notu</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'note', '-') ?: '-' }}</div></td>
                            </tr>
                            <tr>
                                <td colspan="2"><span class="wf-field-label">Sorun Notu</span><div class="wf-field-value">{{ data_get($productionSnapshot, 'issue_note', '-') ?: '-' }}</div></td>
                            </tr>
                        </table>

                        @if(empty($productionPhotos))
                            <div class="wf-photo-box">Üretim fotoğrafı henüz eklenmedi</div>
                        @else
                            <table class="wf-thumb-grid">
                                <tr>
                                    @foreach($productionPhotos as $photo)
                                        <td class="wf-thumb">
                                            @if(!empty($photo['inline_src']))
                                                <img src="{{ $photo['inline_src'] }}" alt="{{ $photo['file_name'] }}">
                                            @endif
                                            <div class="wf-thumb-type">{{ $photo['file_name'] }}</div>
                                            <div class="wf-thumb-meta">{{ $photo['visibility_label'] }}</div>
                                            @if(filled($photo['note']))<div class="wf-thumb-meta">{{ $photo['note'] }}</div>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        @endif
                    </div>
                </td>
                <td style="width:50%;">
                    <div class="wf-section-title">6. Teslimat</div>
                    <div class="wf-box">
                        <table class="wf-mini-table">
                            <tr>
                                <td><span class="wf-field-label">Teslimat Durumu</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivery_status_label', ucfirst(str_replace('_', ' ', $deliveryStatus))) }}</div></td>
                                <td><span class="wf-field-label">Teslimat Yöntemi</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivery_method_label', data_get($deliverySnapshot, 'delivery_type', '-')) }}</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Kargo / Kurye / Elden / Ambar</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'carrier_name', data_get($deliverySnapshot, 'carrier_type', '-')) ?: '-' }}</div></td>
                                <td><span class="wf-field-label">Alıcı</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'recipient_name', '-') ?: '-' }}</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Teslim Tarihi</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivered_at', '-') ?: '-' }}</div></td>
                                <td><span class="wf-field-label">Finans Uyarısı</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'financial_warning_label', 'Finans uyarısı yok') }}</div></td>
                            </tr>
                            <tr>
                                <td><span class="wf-field-label">Teslim Edilen Miktar</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivered_quantity', 0) }}</div></td>
                                <td><span class="wf-field-label">Kalan Miktar</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'remaining_quantity', 0) }}</div></td>
                            </tr>
                            <tr>
                                <td colspan="2"><span class="wf-field-label">Teslimat Notu</span><div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivery_note', '-') ?: '-' }}</div></td>
                            </tr>
                        </table>

                        @if(empty($deliveryAttachments))
                            <div class="wf-doc-box">Teslimat belgesi/fotoğrafı henüz eklenmedi</div>
                        @else
                            <table class="wf-thumb-grid">
                                <tr>
                                    @foreach($deliveryAttachments as $attachment)
                                        <td class="wf-thumb">
                                            @if(!empty($attachment['inline_src']))
                                                <img src="{{ $attachment['inline_src'] }}" alt="{{ $attachment['file_name'] }}">
                                            @endif
                                            <div class="wf-thumb-type">{{ $attachment['file_name'] }}</div>
                                            <div class="wf-thumb-meta">{{ $attachment['attachment_type'] }}</div>
                                            <div class="wf-thumb-meta">{{ $attachment['visibility_label'] }}</div>
                                            @if(filled($attachment['note']))<div class="wf-thumb-meta">{{ $attachment['note'] }}</div>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="wf-section">
        <div class="wf-section-title">7. Workflow Geçmişi</div>
        @foreach($workflowHistory as $historyRow)
            <table class="wf-history-row">
                <tr>
                    <td style="width:24%;">{{ $historyRow['at'] ?: '-' }}</td>
                    <td style="width:58%;">
                        <div>{{ $historyRow['label'] }}</div>
                        @if(filled($historyRow['note'] ?? null))
                            <div class="wf-note">{{ $historyRow['note'] }}</div>
                        @endif
                    </td>
                    <td style="width:18%;">
                        <span class="wf-badge {{ $historyRow['visibility'] === 'Müşteriye Açık' ? 'wf-badge-blue' : 'wf-badge-gray' }}">
                            {{ $historyRow['visibility'] }}
                        </span>
                    </td>
                </tr>
            </table>
        @endforeach
    </div>

    <div class="wf-section">
        <div class="wf-section-title">Operasyon Durum Özeti</div>
        <table class="wf-summary-table">
            <tr>
                <td class="wf-summary-pill"><strong>Grafik</strong>{{ $operationSummary['graphic'] }}</td>
                <td class="wf-summary-pill"><strong>Tedarik</strong>{{ $operationSummary['procurement'] }}</td>
                <td class="wf-summary-pill"><strong>Üretim</strong>{{ $operationSummary['production'] }}</td>
                <td class="wf-summary-pill"><strong>Kalite Kontrol</strong>{{ $operationSummary['quality_control'] }}</td>
                <td class="wf-summary-pill"><strong>Teslimat</strong>{{ $operationSummary['delivery'] }}</td>
            </tr>
        </table>
    </div>

    <div class="wf-footer">
        Bu form firma içi operasyon takip formudur. Fiyat bilgisi içermez. QR kod ile sipariş/kalem durumu takip edilebilir.
        <div style="margin-top:4px;">
            Oluşturulma: {{ $createdTimestamp }} |
            Son güncelleme: {{ $updatedTimestamp }} |
            Render zamanı: {{ $renderTimestamp }} |
            Versiyon: v{{ $workForm->version }}
        </div>
    </div>
</div>
</body>
</html>
