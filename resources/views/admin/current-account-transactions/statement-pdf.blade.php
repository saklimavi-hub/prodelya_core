<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Cari Ekstre</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 10px; }
        .muted { color: #6b7280; font-size: 10px; }
        .grid { width: 100%; margin: 12px 0 16px; }
        .card { width: 24%; display: inline-block; border: 1px solid #d1d5db; padding: 8px; margin-right: 1%; vertical-align: top; box-sizing: border-box; }
        .meta-table td { border: none; padding: 2px 0; }
        .text-right { text-align: right; }
        .badge { display: inline-block; padding: 2px 6px; border: 1px solid #d1d5db; border-radius: 999px; font-size: 10px; }
        .footer { margin-top: 16px; font-size: 10px; color: #6b7280; }
    </style>
</head>
<body>
    <h1>İŞLEM DÖKÜMÜ</h1>
    <div class="muted">{{ $account->safeDisplayName() }} • {{ $mode === 'detailed' ? 'Detaylı Ekstre' : 'Genel Ekstre' }} • {{ $generatedAt }}</div>

    <table class="meta-table" style="margin-top:10px;">
        <tr>
            <td style="width:25%;"><strong>Cari Adı</strong></td>
            <td style="width:25%;">{{ $account->safeDisplayName() }}</td>
            <td style="width:25%;"><strong>Cari Kodu</strong></td>
            <td style="width:25%;">{{ $account->account_code ?: '-' }}</td>
        </tr>
        <tr>
            <td><strong>Rol</strong></td>
            <td>{{ $roleLabel }}</td>
            <td><strong>Tarih Aralığı</strong></td>
            <td>{{ $periodLabel }}</td>
        </tr>
        <tr>
            <td><strong>Para Birimi</strong></td>
            <td>{{ $currency }}</td>
            <td><strong>Filtre</strong></td>
            <td>{{ $filterLabel }}</td>
        </tr>
        @if($mode === 'detailed')
            <tr>
                <td><strong>Vergi No</strong></td>
                <td>{{ $company?->tax_number ?: ($account->tax_number ?: '-') }}</td>
                <td><strong>Telefon / WhatsApp</strong></td>
                <td>{{ $company?->mobile ?: $company?->phone ?: ($account->mobile ?: ($account->phone ?: '-')) }}</td>
            </tr>
            <tr>
                <td><strong>E-posta</strong></td>
                <td>{{ $company?->email ?: ($account->email ?: '-') }}</td>
                <td><strong>Adres</strong></td>
                <td>{{ $addressLabel ?: '-' }}</td>
            </tr>
        @endif
    </table>

    <div class="grid">
        <div class="card"><strong>Toplam Alacak</strong><br>{{ $filteredSummary['formatted_total_credit'] ?? '0,00 TL' }}</div>
        <div class="card"><strong>Toplam Borç</strong><br>{{ $filteredSummary['formatted_total_debit'] ?? '0,00 TL' }}</div>
        <div class="card"><strong>Kapanış Bakiyesi</strong><br>{{ $filteredSummary['formatted_balance'] ?? '0,00 TL' }}<br><span class="muted">{{ $filteredSummary['balance_direction_label'] ?? 'Kapalı' }}</span></div>
        <div class="card"><strong>Vadesi Geçen</strong><br>{{ $overallSummary['formatted_overdue_amount'] ?? 'Yok' }}<br><span class="muted">Açık Hareket: {{ $overallSummary['open_transaction_count'] ?? 0 }}</span></div>
    </div>

    <h2>Hareketler</h2>
    <table>
        <thead>
            <tr>
                <th>İşlem Tarihi</th>
                <th>Açıklama</th>
                <th>Vade Tarihi</th>
                <th>Borç</th>
                <th>Alacak</th>
                <th>Bakiye ₺</th>
            </tr>
        </thead>
        <tbody>
            @if($openingBalance !== null)
                <tr>
                    <td>{{ $filters['from'] ? \Illuminate\Support\Carbon::parse($filters['from'])->locale('tr')->translatedFormat('d.m.Y') : '-' }}</td>
                    <td><strong>Önceden Devreden</strong></td>
                    <td>-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">{{ $openingBalance['label'] }}</td>
                </tr>
            @endif
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['transaction_date'] }}</td>
                    <td>
                        <div><strong>{{ $row['type_label'] }}</strong></div>
                        @if($row['description'] !== '-')
                            <div>{{ $row['description'] }}</div>
                        @endif
                        @if($row['document_number'] !== '-' || $row['order_number'] !== '-')
                            <div class="muted">
                                {{ $row['document_number'] !== '-' ? 'Belge: ' . $row['document_number'] : '' }}
                                {{ $row['document_number'] !== '-' && $row['order_number'] !== '-' ? ' • ' : '' }}
                                {{ $row['order_number'] !== '-' ? 'Sipariş: ' . $row['order_number'] : '' }}
                            </div>
                        @endif
                        @if($mode === 'detailed')
                            <div class="muted">
                                {{ $row['source_label'] }} • {{ $row['status_label'] }}
                                @if($row['payment_method'] !== '-')
                                    • {{ $row['payment_method'] }}
                                @endif
                            </div>
                        @endif
                    </td>
                    <td>{{ $row['due_date'] }}</td>
                    <td class="text-right">{{ $row['debit_amount'] !== '' ? $row['debit_amount'] . ' ' . $row['currency'] : '-' }}</td>
                    <td class="text-right">{{ $row['credit_amount'] !== '' ? $row['credit_amount'] . ' ' . $row['currency'] : '-' }}</td>
                    <td class="text-right">{{ $row['balance_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Kayıt bulunamadı.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top: 14px;">
        <tbody>
            <tr>
                <td style="width:55%; border:none;"></td>
                <td style="width:15%;"><strong>Toplam Alacak</strong></td>
                <td style="width:15%;" class="text-right">{{ $filteredSummary['formatted_total_credit'] ?? '0,00 TL' }}</td>
            </tr>
            <tr>
                <td style="border:none;"></td>
                <td><strong>Toplam Borç</strong></td>
                <td class="text-right">{{ $filteredSummary['formatted_total_debit'] ?? '0,00 TL' }}</td>
            </tr>
            <tr>
                <td style="border:none;"></td>
                <td><strong>Bakiye</strong></td>
                <td class="text-right">{{ $filteredSummary['formatted_balance'] ?? '0,00 TL' }}</td>
            </tr>
        </tbody>
    </table>

    @if($mode === 'detailed' && !empty($agingSummary['buckets']))
        <h2>Vade Yaşlandırma</h2>
        <table>
            <thead>
                <tr>
                    <th>Grup</th>
                    <th>Tutar</th>
                    <th>Hareket Sayısı</th>
                </tr>
            </thead>
            <tbody>
                @foreach($agingSummary['buckets'] as $bucket)
                    <tr>
                        <td>{{ $bucket['label'] }}</td>
                        <td>{{ $bucket['formatted_amount'] }}</td>
                        <td>{{ $bucket['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Bu belge Prodelya cari ekstre çıktısıdır. {{ $tenantName }} için {{ $mode === 'detailed' ? 'detaylı' : 'genel' }} ekstre görünümünü içerir.
    </div>
</body>
</html>
