<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $statusLabel }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 32px; }
        .card { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 28px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; margin-bottom: 16px; }
        .badge.green { background: #dcfce7; color: #166534; }
        .badge.red { background: #fee2e2; color: #b91c1c; }
        .badge.amber { background: #fef3c7; color: #b45309; }
        h1 { margin: 0 0 12px; font-size: 28px; }
        p { margin: 0 0 14px; line-height: 1.6; color: #475569; }
        .meta { margin-top: 22px; padding-top: 18px; border-top: 1px solid #e2e8f0; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge {{ $statusTone }}">{{ $statusLabel }}</span>
        <h1>{{ $session->reference_no }}</h1>
        <p>{{ $message }}</p>
        <div class="meta">
            <p>Tenant: {{ $session->tenant?->name ?: 'Tanımsız' }}</p>
            <p>Provider: {{ $session->provider?->display_name ?: 'Tanımsız' }}</p>
            <p>Tutar: {{ \App\Services\MoneyFormatter::format((float) $session->amount, $session->currency ?: 'TRY') }}</p>
        </div>
    </div>
</body>
</html>
