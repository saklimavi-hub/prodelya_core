<?php

namespace App\Services;

class CurrentAccountLedgerDisplayService
{
    public function formatSignedBalance(?float $balance, string $currency = 'TL', bool $hasMultipleCurrencies = false): string
    {
        if ($hasMultipleCurrencies) {
            return 'Çoklu para birimi';
        }

        $amount = round((float) $balance, 2);

        if ($amount === 0.0) {
            return MoneyFormatter::format(0, $currency);
        }

        if ($amount < 0) {
            return '-' . MoneyFormatter::format(abs($amount), $currency);
        }

        return MoneyFormatter::format($amount, $currency);
    }

    public function moneyDisplayTone(?float $balance, bool $hasMultipleCurrencies = false): string
    {
        if ($hasMultipleCurrencies) {
            return 'mixed';
        }

        $amount = round((float) $balance, 2);

        if ($amount > 0) {
            return 'positive';
        }

        if ($amount < 0) {
            return 'negative';
        }

        return 'zero';
    }

    public function moneyDisplayClass(?float $balance, bool $hasMultipleCurrencies = false): string
    {
        return 'pd-money-display pd-money-' . $this->moneyDisplayTone($balance, $hasMultipleCurrencies);
    }

    public function balanceStatusLabel(?float $balance, bool $hasMultipleCurrencies = false): string
    {
        if ($hasMultipleCurrencies) {
            return 'Çoklu';
        }

        $amount = round((float) $balance, 2);

        if ($amount > 0) {
            return 'Borç Bakiyesi';
        }

        if ($amount < 0) {
            return 'Alacak Bakiyesi';
        }

        return 'Kapalı';
    }

    public function formatBalanceWithStatus(?float $balance, string $currency = 'TL', bool $hasMultipleCurrencies = false): string
    {
        $label = $this->formatSignedBalance($balance, $currency, $hasMultipleCurrencies);
        $status = $this->balanceStatusLabel($balance, $hasMultipleCurrencies);

        return $status === 'Kapalı' ? $label . ' · Kapalı' : $label;
    }
}
