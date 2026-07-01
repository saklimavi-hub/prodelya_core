<?php

namespace App\Services\ProductDataHub;

use Illuminate\Support\Str;

class SupplierWarningLabelService
{
    public function supplierSpecificTags(?string $supplierName, array $snapshot): array
    {
        $tags = [];

        if ($this->hasNetPriceWarning($snapshot)) {
            $tags[] = 'net_price';
        }

        if ($this->hasSupplierWarningFlag($snapshot)) {
            if ($this->isEtkin($supplierName)) {
                $tags[] = 'red_product';
            } elseif ($this->isYeniNesil($supplierName)) {
                $tags[] = 'amber_product';
            }
        }

        return array_values(array_unique($tags));
    }

    public function supplierSpecificBadges(?string $supplierName, array $snapshot): array
    {
        return array_map(
            fn (string $tag) => $this->labelForTag($tag),
            $this->supplierSpecificTags($supplierName, $snapshot)
        );
    }

    public function supplierSpecificMessages(?string $supplierName, array $snapshot): array
    {
        $messages = [];

        foreach ($this->supplierSpecificTags($supplierName, $snapshot) as $tag) {
            $message = match ($tag) {
                'red_product' => 'Bu ürün Etkin kaynağında kırmızı ürün olarak işaretlenmiş. Standart indirim uygulanmadan önce kontrol edilmelidir.',
                'amber_product' => 'Bu ürün Yeni Nesil kaynağında turuncu ürün olarak işaretlenmiş. Standart indirim uygulanmadan önce kontrol edilmelidir.',
                'net_price' => 'Bu ürün net fiyatlı olabilir. Teklif/sipariş sırasında standart iskonto uygulanmamalı; gerekirse birim satış fiyatı artırılarak çalışılmalıdır.',
                default => null,
            };

            if (filled($message)) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    public function isEtkin(?string $supplierName): bool
    {
        return $this->normalizedSupplierName($supplierName) === 'etkin';
    }

    public function isYeniNesil(?string $supplierName): bool
    {
        return $this->normalizedSupplierName($supplierName) === 'yeni_nesil';
    }

    public function isAkdeniz(?string $supplierName): bool
    {
        return $this->normalizedSupplierName($supplierName) === 'akdeniz';
    }

    public function isIlpen(?string $supplierName): bool
    {
        return $this->normalizedSupplierName($supplierName) === 'ilpen';
    }

    public function hasNetPriceWarning(array $snapshot): bool
    {
        return (bool) ($snapshot['net_price_warning'] ?? false)
            || (($snapshot['pricing_policy_type'] ?? null) === 'net_price');
    }

    public function hasSupplierWarningFlag(array $snapshot): bool
    {
        return (bool) ($snapshot['supplier_warning_flag'] ?? false)
            || filled($snapshot['supplier_warning_type'] ?? null);
    }

    public function labelForTag(string $tag): string
    {
        return match ($tag) {
            'red_product' => 'Kırmızı Ürün',
            'amber_product' => 'Turuncu Ürün',
            'net_price' => 'Net fiyat uyarısı',
            default => $tag,
        };
    }

    private function normalizedSupplierName(?string $supplierName): ?string
    {
        $value = Str::of((string) $supplierName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        return match (true) {
            $value === '' => null,
            str_contains($value, 'etkin') => 'etkin',
            str_contains($value, 'yeni nesil') => 'yeni_nesil',
            str_contains($value, 'akdeniz') => 'akdeniz',
            str_contains($value, 'ilpen') => 'ilpen',
            default => 'other',
        };
    }
}
