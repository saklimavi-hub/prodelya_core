<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>{{ $order->document_number }} Etiketleri</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color:#111827; margin:20px; }
        .print-head { margin-bottom:20px; }
        .print-title { font-size:22px; font-weight:700; }
        .print-subtitle { margin-top:6px; color:#475467; font-size:13px; }
        .label-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
        .label-card { border:1px solid #d0d5dd; border-radius:8px; padding:16px; page-break-inside:avoid; }
        .label-card h2 { margin:0 0 8px; font-size:18px; }
        .label-card ul { margin:10px 0 0; padding-left:18px; }
        .label-card li { margin-bottom:6px; }
        .label-meta { color:#475467; font-size:12px; line-height:1.5; }
        @media print {
            body { margin:0; padding:12mm; }
            .print-actions { display:none; }
        }
    </style>
</head>
<body>
    <div class="print-actions" style="margin-bottom:12px;">
        <button type="button" onclick="window.print()">Yazdır</button>
    </div>
    <div class="print-head">
        <div class="print-title">Teslimat Etiketleri</div>
        <div class="print-subtitle">
            {{ $order->document_number }} · {{ $customer_name }} · {{ $template_label }}
            @if($page_summary)
                · {{ $page_summary }}
            @endif
        </div>
        <div class="print-subtitle">Hazırlanma tarihi: {{ $print_date_label }}</div>
    </div>

    @if($labels === [])
        <section class="label-card">
            <h2>Etiket Hazır Değil</h2>
            <div class="label-meta">
                @if($planning_available ?? true)
                    Bu sipariş için henüz koli planı veya etiket partisi hazırlanmadı.
                @else
                    Bu ortamda koli planı ve etiket kayıtları henüz hazır değil.
                @endif
            </div>
        </section>
    @else
        <div class="label-grid">
            @foreach($labels as $label)
                <section class="label-card">
                    <h2>{{ $label['package_label'] }}</h2>
                    <div class="label-meta">Sipariş No: {{ $order->document_number }}</div>
                    <div class="label-meta">Müşteri: {{ $customer_name }}</div>
                    <div class="label-meta">Koli Tipi: {{ $label['package_type_label'] }}</div>
                    <div class="label-meta">Koli Toplamı: {{ $label['total_quantity_label'] }}</div>
                    <ul>
                        @foreach($label['item_lines'] as $itemLine)
                            <li>{{ $itemLine['product_name'] }} · {{ $itemLine['quantity_label'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</body>
</html>
