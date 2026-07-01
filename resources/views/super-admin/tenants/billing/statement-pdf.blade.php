<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Tenant SaaS Cari Ekstresi</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 20px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .grid { width: 100%; margin-top: 12px; margin-bottom: 18px; }
        .card { width: 24%; display: inline-block; border: 1px solid #d1d5db; padding: 10px; margin-right: 1%; vertical-align: top; }
        .muted { color: #6b7280; font-size: 11px; }
    </style>
</head>
<body>
    <h1>{{ $tenant->name }} SaaS Cari Ekstresi</h1>
    <div class="muted">Abone Firma bazlı hizmet, paket ve tahsilat hareketleri</div>

    <div class="grid">
        <div class="card"><strong>Cari Bakiye</strong><br>{{ \App\Services\MoneyFormatter::format((float) $summary['balance']) }}</div>
        <div class="card"><strong>Toplam Borç</strong><br>{{ \App\Services\MoneyFormatter::format((float) $summary['total_debit']) }}</div>
        <div class="card"><strong>Toplam Alacak</strong><br>{{ \App\Services\MoneyFormatter::format((float) $summary['total_credit']) }}</div>
        <div class="card"><strong>Hareket Sayısı</strong><br>{{ $summary['entry_count'] }}</div>
    </div>

    <h2>Hareketler</h2>
    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Hareket Tipi</th>
                <th>Başlık</th>
                <th>Hizmet</th>
                <th>Referans</th>
                <th>Borç</th>
                <th>Alacak</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td>{{ optional($entry->entry_date)->format('d.m.Y') }}</td>
                    <td>{{ $entry->typeLabel() }}</td>
                    <td>{{ $entry->title }}</td>
                    <td>{{ $entry->serviceDefinition?->service_name ?: '-' }}</td>
                    <td>{{ $entry->reference_no ?: '-' }}</td>
                    <td>{{ $entry->direction === 'debit' ? \App\Services\MoneyFormatter::format((float) $entry->amount, $entry->currency ?: 'TRY') : '-' }}</td>
                    <td>{{ $entry->direction === 'credit' ? \App\Services\MoneyFormatter::format((float) $entry->amount, $entry->currency ?: 'TRY') : '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Kayıt bulunamadı.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
