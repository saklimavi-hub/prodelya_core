<?php

namespace App\Services;

class PrintSetupUnitDistributionService
{
    public const STATUS_NONE = 'Yok';
    public const STATUS_EXISTS = 'Var';
    public const STATUS_NEW = 'Yeni üretilecek';
    public const STATUS_REUSE = 'Mevcut kullanılacak';
    public const STATUS_CUSTOMER = 'Müşteriden gelecek';
    public const STATUS_NOT_REQUIRED = 'Gerekli değil';

    public function calculate(
        float $basePrintUnitPrice,
        float $setupTotalAmount,
        float $distributionQuantity
    ): array {
        $distributionQuantity = max(0, $distributionQuantity);
        $setupTotalAmount = max(0, $setupTotalAmount);
        $basePrintUnitPrice = max(0, $basePrintUnitPrice);

        $setupUnitAmount = $distributionQuantity > 0
            ? round($setupTotalAmount / $distributionQuantity, 4)
            : 0.0;

        $finalPrintUnitPrice = round($basePrintUnitPrice + $setupUnitAmount, 4);
        $finalPrintTotal = round($finalPrintUnitPrice * $distributionQuantity, 4);

        return [
            'base_print_unit_price' => round($basePrintUnitPrice, 4),
            'setup_total_amount' => round($setupTotalAmount, 4),
            'setup_distribution_quantity' => round($distributionQuantity, 4),
            'setup_unit_amount' => $setupUnitAmount,
            'final_print_unit_price' => $finalPrintUnitPrice,
            'final_print_total' => $finalPrintTotal,
        ];
    }

    public function statusRequiresSetupAmount(?string $status): bool
    {
        return $this->normalizeStatus($status) === self::STATUS_NEW;
    }

    public function normalizeStatus(?string $status): ?string
    {
        $status = trim((string) $status);

        if ($status === '') {
            return null;
        }

        return match ($status) {
            self::STATUS_NONE,
            self::STATUS_EXISTS,
            self::STATUS_NEW,
            self::STATUS_REUSE,
            self::STATUS_CUSTOMER,
            self::STATUS_NOT_REQUIRED => $status,
            default => $status,
        };
    }
}
