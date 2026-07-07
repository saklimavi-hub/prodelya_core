@php
    $ledgerDisplay = app(\App\Services\CurrentAccountLedgerDisplayService::class);
    $displayLabel = $label ?? '0,00 TL';
    $displayAmount = isset($amount) && is_numeric($amount) ? (float) $amount : null;
    $displayHasMultipleCurrencies = (bool) ($hasMultipleCurrencies ?? false);
    $displayClass = trim(($class ?? '') . ' ' . $ledgerDisplay->moneyDisplayClass($displayAmount, $displayHasMultipleCurrencies));
@endphp

@once
    <style>
        .pd-money-display { color:#111827; font-weight:700; }
        .pd-money-positive { color:#111827; }
        .pd-money-negative { color:#dc2626; }
        .pd-money-zero { color:#475467; font-weight:600; }
        .pd-money-mixed { color:#475467; font-weight:600; }
    </style>
@endonce

<span class="{{ $displayClass }}">{{ $displayLabel }}</span>
