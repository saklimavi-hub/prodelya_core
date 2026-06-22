<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use Illuminate\Support\Collection;

class PublicWorkFormTrackingDataBuilder
{
    public function __construct(
        private readonly TenantCompanyProfileService $tenantCompanyProfileService
    ) {
    }

    private const FORBIDDEN_PUBLIC_PATTERNS = [
        '/\bunit_price\b/i',
        '/\bprint_unit_price\b/i',
        '/\bprint_total\b/i',
        '/\bproduct_total\b/i',
        '/\bsubtotal\b/i',
        '/\bvat_total\b/i',
        '/\bgrand_total\b/i',
        '/\bdiscount\b/i',
        '/\bbalance_due\b/i',
        '/\bbalance\b/i',
        '/\bpaid_total\b/i',
        '/\bpayment_amount\b/i',
        '/\bfinance warning\b/i',
        '/\bsupplier_cost\b/i',
        '/\bpurchase_total\b/i',
        '/\bpurchase_unit_price\b/i',
        '/\bsubcontractor_cost\b/i',
        '/\bprofit\b/i',
        '/\bmargin\b/i',
        '/\bsetup_cost\b/i',
        '/\bgroup_code\b/i',
        '/\bpdh_raw\b/i',
        '/\braw xml\b/i',
        '/\braw json\b/i',
        '/\bnotification_logs\b/i',
        '/\bcurrent_account_transactions\b/i',
        '/\bsmtp_password\b/i',
        '/\bapi_key\b/i',
        '/\btoken\b/i',
        '/\bfile_path\b/i',
        '/\bphysical_path\b/i',
        '/(^|[\s"\'])storage[\/\\\\]/i',
        '/(^|[\s"\'])work-forms[\/\\\\]/i',
        '/[A-Z]:\\\\/i',
    ];

    public function build(OrderItemWorkForm $workForm): array
    {
        $workForm->loadMissing(['tenant', 'attachments', 'activityLogs.attachment']);

        $attachments = $workForm->attachments
            ->where('visibility', 'customer_visible')
            ->sortBy(static function (OrderItemWorkFormAttachment $attachment): string {
                return implode('|', [
                    (string) $attachment->attachment_type,
                    str_pad((string) $attachment->sort_order, 6, '0', STR_PAD_LEFT),
                    str_pad((string) $attachment->id, 10, '0', STR_PAD_LEFT),
                ]);
            })
            ->values();

        $orderSnapshot = $workForm->order_snapshot ?? [];
        $productSnapshot = $workForm->product_snapshot ?? [];
        $printSnapshot = collect($workForm->print_snapshot ?? [])->values();
        $procurementSnapshot = $workForm->procurement_snapshot ?? [];
        $graphicSnapshot = $workForm->graphic_snapshot ?? [];
        $productionSnapshot = $workForm->production_snapshot ?? [];
        $deliverySnapshot = $workForm->delivery_snapshot ?? [];

        $publicAttachments = $this->mapAttachments($workForm, $attachments);
        $publicHistory = $workForm->activityLogs
            ->where('visibility', 'customer_visible')
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($log) => [
                'at' => optional($log->created_at)->format('d.m.Y H:i'),
                'label' => $this->humanizeActionType((string) $log->action_type),
                'note' => $this->safePublicNote($log->note),
            ])
            ->all();

        return [
            'tenantName' => $workForm->tenant
                ? $this->tenantCompanyProfileService->getProfile($workForm->tenant)['display_name']
                : 'Prodelya',
            'workForm' => [
                'number' => $workForm->work_form_number,
                'version' => $workForm->version,
                'status' => $this->buildGeneralStatus($procurementSnapshot, $graphicSnapshot, $productionSnapshot, $deliverySnapshot),
                'last_updated_at' => optional($workForm->updated_at)->format('d.m.Y H:i') ?: optional($workForm->created_at)->format('d.m.Y H:i'),
            ],
            'order' => [
                'document_number' => data_get($orderSnapshot, 'document_number', '-'),
                'source_quote_number' => data_get($orderSnapshot, 'source_quote_number'),
                'delivery_type' => data_get($orderSnapshot, 'delivery_type', '-'),
                'order_date' => data_get($orderSnapshot, 'order_date', '-'),
                'estimated_delivery_date' => null,
            ],
            'customer' => [
                'company_name' => data_get($workForm->customer_snapshot, 'company_name', '-'),
            ],
            'product' => [
                'name' => data_get($productSnapshot, 'product_name', '-'),
                'code' => data_get($productSnapshot, 'product_code', '-'),
                'quantity' => $this->formatQuantity(data_get($productSnapshot, 'quantity', 0), data_get($productSnapshot, 'unit')),
                'image_url' => $this->safePublicImageUrl(data_get($productSnapshot, 'image_url')),
            ],
            'procurement' => [
                'status' => data_get($procurementSnapshot, 'public_status_label', 'Ürününüz hazırlanıyor'),
            ],
            'production' => [
                'status' => data_get($productionSnapshot, 'public_status_label', $this->publicStatusLabel(data_get($productionSnapshot, 'status'), 'production')),
            ],
            'timeline' => [
                ['title' => 'Tedarik', 'status' => data_get($procurementSnapshot, 'public_status_label', 'Ürününüz hazırlanıyor')],
                ['title' => 'Grafik', 'status' => $this->publicStatusLabel(data_get($graphicSnapshot, 'status'), 'graphic')],
                ['title' => 'Üretim', 'status' => data_get($productionSnapshot, 'public_status_label', $this->publicStatusLabel(data_get($productionSnapshot, 'status'), 'production'))],
                ['title' => 'Kalite Kontrol', 'status' => $this->publicQualityStatusLabel($productionSnapshot)],
                ['title' => 'Teslimat', 'status' => data_get($deliverySnapshot, 'public_status_label', $this->publicStatusLabel(data_get($deliverySnapshot, 'status'), 'delivery'))],
            ],
            'prints' => $printSnapshot->map(fn (array $line) => [
                'type' => data_get($line, 'print_type', '-'),
                'option' => data_get($line, 'print_option', '-'),
                'production_type' => $this->publicProductionTypeLabel(data_get($line, 'production_type')),
                'quantity' => $this->formatQuantity(data_get($line, 'print_quantity', 0), data_get($productSnapshot, 'unit')),
                'note' => $this->safePublicNote(data_get($line, 'note')),
            ])->all(),
            'attachments' => $publicAttachments,
            'activityLogs' => $publicHistory,
        ];
    }

    private function mapAttachments(OrderItemWorkForm $workForm, Collection $attachments): array
    {
        return $attachments
            ->map(fn (OrderItemWorkFormAttachment $attachment) => [
                'id' => $attachment->id,
                'type' => $attachment->attachment_type,
                'file_name' => $this->safePublicFileName($attachment),
                'mime_type' => $attachment->mime_type,
                'note' => $this->safePublicNote($attachment->note),
                'is_image' => $attachment->isImage(),
                'is_document' => $attachment->isDocument(),
                'url' => route('public.work-forms.attachments.show', [
                    'token' => $workForm->public_tracking_token,
                    'attachment' => $attachment->id,
                ]),
                'visibility_label' => 'Müşteriye Açık',
                'created_at' => optional($attachment->created_at)->format('d.m.Y H:i'),
            ])
            ->all();
    }

    private function humanizeActionType(string $actionType): string
    {
        return match ($actionType) {
            'graphic_visual_added' => 'Grafik görseli eklendi',
            'customer_approval_added' => 'Onay dosyası eklendi',
            'production_photo_added' => 'Üretim fotoğrafı eklendi',
            'delivery_photo_added' => 'Teslimat fotoğrafı eklendi',
            'delivery_document_added' => 'Teslimat belgesi eklendi',
            'work_form_created' => 'İş Formu oluşturuldu',
            default => 'Güncelleme yapıldı',
        };
    }

    private function buildGeneralStatus(
        array $procurementSnapshot,
        array $graphicSnapshot,
        array $productionSnapshot,
        array $deliverySnapshot
    ): string
    {
        $delivery = (string) data_get($deliverySnapshot, 'public_status_label', $this->publicStatusLabel(data_get($deliverySnapshot, 'status'), 'delivery'));
        if (in_array($delivery, ['Teslim edildi', 'Kargoya verildi', 'Teslimata hazırlanıyor', 'Teslimata hazır', 'Kurye teslimatta', 'Kısmi teslim edildi'], true)) {
            return $delivery;
        }

        $procurement = (string) data_get($procurementSnapshot, 'public_status_label', 'Sipariş alındı');
        $production = data_get($productionSnapshot, 'public_status_label', $this->publicStatusLabel(data_get($productionSnapshot, 'status'), 'production'));
        $productionStatus = trim(strtolower(str_replace('_', ' ', (string) data_get($productionSnapshot, 'status', ''))));

        if ($productionStatus === 'gerekli degil') {
            return $procurement !== '' ? $procurement : 'Sipariş alındı';
        }

        if (!in_array($production, ['Bekliyor', 'Üretim bekliyor'], true)) {
            return $production;
        }

        $graphicStatus = trim(strtolower(str_replace('_', ' ', (string) data_get($graphicSnapshot, 'status', ''))));
        if ($graphicStatus === 'gerekli degil') {
            return $procurement !== '' ? $procurement : 'Sipariş alındı';
        }

        return $this->publicStatusLabel(data_get($graphicSnapshot, 'status'), 'graphic');
    }

    private function publicStatusLabel(mixed $status, string $scope): string
    {
        $normalized = trim(strtolower(str_replace('_', ' ', (string) ($status ?? ''))));

        if ($normalized === '') {
            return 'Bekliyor';
        }

        if ($normalized === 'gerekli degil') {
            return match ($scope) {
                'graphic' => 'Grafik gerekli değil',
                'production' => 'Üretim gerekli değil',
                'quality' => 'Kalite kontrol gerekli değil',
                default => 'Gerekli değil',
            };
        }

        return match ($scope) {
            'graphic' => match ($normalized) {
                'onaylandi', 'üretime hazır', 'uretime hazir' => 'Grafik onaylandı',
                'müşteri onayı bekliyor', 'musteri onayi bekliyor' => 'Grafik onay bekliyor',
                default => 'Grafik bekliyor',
            },
            'production' => match ($normalized) {
                'tamamlandı', 'tamamlandi' => 'Üretim tamamlandı',
                'işlemde', 'uretimde', 'üretimde', 'fasona gönderildi', 'fasona gonderildi', 'iç üretimde', 'ic uretimde' => 'Üretimde',
                'fasondan geldi', 'fasondan geldi' => 'Kalite kontrol bekliyor',
                'kalite kontrol', 'kalite kontrolde' => 'Kalite kontrolde',
                'sorunlu' => 'Üretim süreci kontrol ediliyor',
                'iptal' => 'Üretim süreci durduruldu',
                'üretim bekliyor', 'uretim bekliyor' => 'Üretim bekliyor',
                default => 'Üretim hazırlanıyor',
            },
            'quality' => match ($normalized) {
                'uygun' => 'Kalite kontrol tamamlandı',
                'sorunlu' => 'Kalite kontrol sürüyor',
                'tamamlandı', 'tamamlandi' => 'Kalite kontrol tamamlandı',
                default => 'Kalite kontrol bekliyor',
            },
            'delivery' => match ($normalized) {
                'teslim edildi' => 'Teslim edildi',
                'kargoya verildi' => 'Kargoya verildi',
                'teslimata hazir', 'teslimata hazır' => 'Teslimata hazır',
                'kurye teslimatta' => 'Kurye teslimatta',
                'kismi teslim edildi', 'kısmi teslim edildi' => 'Kısmi teslim edildi',
                'teslimat sorunu' => 'Teslimat süreci kontrol ediliyor',
                'iptal' => 'Teslimat süreci durduruldu',
                'hazırlanıyor', 'hazirlaniyor', 'teslimata hazırlanıyor', 'teslimata hazirlaniyor' => 'Teslimata hazırlanıyor',
                default => 'Teslimat bekliyor',
            },
            default => 'Bekliyor',
        };
    }

    private function publicProductionTypeLabel(?string $productionType): string
    {
        $normalized = trim((string) $productionType);

        if ($normalized === '') {
            return '-';
        }

        if (str_contains(mb_strtolower($normalized), 'fason') || str_contains(mb_strtolower($normalized), 'dış')) {
            return 'Dış Üretim';
        }

        return 'İç Üretim';
    }

    private function publicQualityStatusLabel(array $productionSnapshot): string
    {
        $publicProductionStatus = (string) data_get($productionSnapshot, 'public_status_label', '');

        if ($publicProductionStatus === 'Kalite kontrolde') {
            return 'Kalite kontrolde';
        }

        if ($publicProductionStatus === 'Üretim tamamlandı') {
            return 'Kalite kontrol tamamlandı';
        }

        return $this->publicStatusLabel(data_get($productionSnapshot, 'qc_status'), 'quality');
    }

    private function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function safePublicNote(mixed $value): ?string
    {
        $note = trim((string) ($value ?? ''));

        if ($note === '') {
            return null;
        }

        foreach (self::FORBIDDEN_PUBLIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $note) === 1) {
                return null;
            }
        }

        return $note;
    }

    private function safePublicImageUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '') {
            return null;
        }

        return preg_match('/^https?:\/\//i', $url) === 1 ? $url : null;
    }

    private function safePublicFileName(OrderItemWorkFormAttachment $attachment): string
    {
        $fileName = trim((string) ($attachment->file_name ?: basename((string) $attachment->file_path)));

        if ($fileName === '') {
            return $this->fallbackPublicFileName($attachment);
        }

        foreach (self::FORBIDDEN_PUBLIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $fileName) === 1) {
                return $this->fallbackPublicFileName($attachment);
            }
        }

        return $fileName;
    }

    private function fallbackPublicFileName(OrderItemWorkFormAttachment $attachment): string
    {
        $extension = pathinfo((string) ($attachment->file_name ?: $attachment->file_path), PATHINFO_EXTENSION);
        $label = match ((string) $attachment->attachment_type) {
            'delivery_document' => 'paylasilan-belge',
            'delivery_photo', 'production_photo', 'graphic_visual', 'customer_approval' => 'paylasilan-gorsel',
            default => 'paylasilan-dosya',
        };

        return $extension !== ''
            ? sprintf('%s.%s', $label, strtolower($extension))
            : $label;
    }
}
