<?php

namespace App\Services;

use App\Models\TenantSetting;
use App\Models\OrderItemWorkForm;
use Illuminate\Support\Str;

class WorkFolderPathService
{
    public const BASE_FOLDER = 'ISLER';

    public const SUBDIRECTORIES = [
        '01_GRAFIK',
        '02_BASKIYA_HAZIR',
        '03_URETIM_TESLIMAT',
    ];

    public function buildForWorkForm(OrderItemWorkForm $workForm): array
    {
        $customerName = (string) data_get($workForm->customer_snapshot ?? [], 'company_name', '');
        $orderNumber = (string) data_get($workForm->order_snapshot ?? [], 'document_number', '');
        $productCode = (string) data_get($workForm->product_snapshot ?? [], 'product_code', '');
        $productName = (string) data_get($workForm->product_snapshot ?? [], 'product_name', '');
        $itemSequence = str_pad((string) ($workForm->item_sequence ?: 0), 2, '0', STR_PAD_LEFT);

        $customerSegment = $this->buildCustomerSegment($customerName);
        $orderSegment = $this->normalizeSegment($orderNumber, 32, 'SIPARIS');
        $productSegment = $this->normalizeSegment($productCode !== '' ? $productCode : $productName, 48, 'URUN');
        $itemSegment = $itemSequence . '-' . $productSegment;

        $segments = [
            $this->resolveRootFolderName($workForm),
            $customerSegment,
            $orderSegment,
            $itemSegment,
        ];

        return [
            'relative_path' => implode('/', $segments),
            'display_path' => implode(' / ', $segments),
            'subdirectories' => self::SUBDIRECTORIES,
        ];
    }

    public function resolveRootFolderName(OrderItemWorkForm $workForm): string
    {
        $rootName = TenantSetting::getValue($workForm->tenant_account_id, 'work_folder_root_name', self::BASE_FOLDER);

        return $this->normalizeSegment((string) $rootName, 32, self::BASE_FOLDER);
    }

    public function buildCustomerSegment(?string $customerName): string
    {
        $normalized = $this->normalizeSegment($customerName, 80, 'MUSTERI');

        $words = array_values(array_filter(
            explode('-', $normalized),
            fn (string $word): bool => $word !== '' && !in_array($word, ['VE', 'ILE'], true)
        ));

        $words = array_slice($words, 0, 3);

        if ($words === []) {
            return 'MUSTERI';
        }

        return Str::of(implode('-', $words))
            ->limit(32, '')
            ->trim('-')
            ->value();
    }

    public function normalizeSegment(?string $value, int $maxLength = 48, string $fallback = 'KLASOR'): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        $value = strtr($value, [
            'Ç' => 'C', 'ç' => 'c',
            'Ğ' => 'G', 'ğ' => 'g',
            'İ' => 'I', 'I' => 'I', 'ı' => 'i',
            'Ö' => 'O', 'ö' => 'o',
            'Ş' => 'S', 'ş' => 's',
            'Ü' => 'U', 'ü' => 'u',
        ]);

        $value = str_replace(['.', ',', ';', ':', '\'', '"', '`'], '', $value);

        $value = Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->limit($maxLength, '')
            ->trim('-')
            ->value();

        return $value !== '' ? $value : $fallback;
    }
}
