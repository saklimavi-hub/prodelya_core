<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Prodelya Teklifiniz</title>
</head>
<body>
    <p>Merhaba {{ $customerName }},</p>

    <p>
        {{ $tenant->name ?: 'Prodelya' }} tarafından hazırlanan teklifinizi aşağıdaki bağlantıdan görüntüleyebilirsiniz.
    </p>

    <p>
        <strong>Teklif No:</strong> {{ $quote->document_number }}<br>
        <strong>Geçerlilik Tarihi:</strong> {{ $validUntilLabel }}<br>
        <strong>Toplam:</strong> {{ $grandTotalLabel }}
    </p>

    <p>
        <strong>Teklifi Görüntüle / Onayla:</strong><br>
        <a href="{{ $publicApprovalUrl }}">{{ $publicApprovalUrl }}</a>
    </p>
</body>
</html>
