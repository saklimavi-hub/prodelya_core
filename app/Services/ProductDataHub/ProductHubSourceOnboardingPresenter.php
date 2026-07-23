<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ProductHubSourceOnboardingPresenter
{
    public function present(SupplierSource $source): array
    {
        $profileKey = (string) ($source->profile_key ?? data_get($source, 'config.profile_key', 'CUSTOM'));
        $hasLocation = (bool) ($source->has_location ?? false);
        $hasMappings = (bool) ($source->has_field_mappings ?? false);
        $missingRequiredMappingCount = (int) ($source->missing_required_mapping_count ?? 0);
        $categoryPendingCount = (int) ($source->category_pending_count ?? 0);
        $latestSync = $source->latest_sync_run;
        $latestSyncStatus = $latestSync?->normalizedStatus();
        $lastTestAt = $this->parseDate($source->last_test_display ?? null);
        $lastPreviewAt = $this->parseDate($source->last_preview_display ?? null);
        $previewMeta = $this->previewMeta($source);
        $previewMode = (string) ($previewMeta['preview_mode'] ?? '');
        $rawProducts = (int) data_get($source, 'dependency_summary.raw_products', 0);
        $standardProducts = (int) data_get($source, 'dependency_summary.standard_products', 0);
        $tenantCatalogProducts = (int) data_get($source, 'dependency_summary.tenant_catalog_products', 0);
        $tenantAccess = (int) data_get($source, 'dependency_summary.tenant_access', 0);
        $rawVariants = (int) ($source->raw_variant_count ?? 0);
        $standardVariants = (int) ($source->standard_variant_count ?? 0);
        $warningProductCount = (int) ($source->warning_product_count ?? 0);
        $hasPreview = $lastPreviewAt !== null;
        $hasSuccessfulPreview = in_array($previewMode, ['success', 'fallback'], true);
        $hasSyncEvidence = $latestSync !== null
            || $rawProducts > 0
            || $standardProducts > 0
            || $tenantCatalogProducts > 0;
        $hasSuccessfulConnection = $hasLocation
            && $lastTestAt !== null
            && $source->status !== 'error'
            && $previewMode !== 'error';

        $stateKey = match (true) {
            !$hasLocation => 'source_info_missing',
            !$hasSuccessfulConnection => 'connection_check_required',
            !$hasPreview => 'preview_required',
            !$hasMappings || $missingRequiredMappingCount > 0 => 'mapping_required',
            $hasSyncEvidence || ($tenantAccess > 0 && $tenantCatalogProducts > 0) || in_array($latestSyncStatus, [
                ProductDataHubSyncRun::STATUS_COMPLETED,
                ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS,
                ProductDataHubSyncRun::STATUS_RECOVERED,
            ], true) => 'active_sync',
            default => 'first_import_ready',
        };

        $stateMap = [
            'source_info_missing' => ['label' => 'Kaynak bilgisi eksik', 'tone' => 'amber', 'step' => 'source', 'readiness' => 16],
            'connection_check_required' => ['label' => 'Bağlantı kontrolü gerekiyor', 'tone' => 'amber', 'step' => 'connection', 'readiness' => 34],
            'preview_required' => ['label' => 'Önizleme bekleniyor', 'tone' => 'blue', 'step' => 'preview', 'readiness' => 50],
            'mapping_required' => ['label' => 'Alan eşleme eksik', 'tone' => 'amber', 'step' => 'field_mapping', 'readiness' => 68],
            'first_import_ready' => ['label' => 'İlk aktarıma hazır', 'tone' => 'green', 'step' => 'sync', 'readiness' => 86],
            'active_sync' => ['label' => 'Aktif kaynak', 'tone' => 'green', 'step' => 'auto_settings', 'readiness' => 100],
        ];
        $state = $stateMap[$stateKey];
        $primaryAction = $this->primaryAction($source, $stateKey);
        $pricing = $this->pricingContract($source, $profileKey);
        $displayLocation = (string) ($source->display_location ?? '');

        return [
            'id' => $source->id,
            'supplier_name' => $source->supplier?->name ?? '-',
            'source_name' => $source->source_name,
            'format_label' => strtoupper((string) ($source->display_source_type ?? $source->source_type)),
            'profile_key' => $profileKey,
            'status_label' => $source->status_label ?? $source->getStatusDisplayName(),
            'status_tone' => $source->status_badge ?? 'gray',
            'state_key' => $stateKey,
            'state_label' => $state['label'],
            'state_tone' => $state['tone'],
            'readiness_percent' => $state['readiness'],
            'active_step' => $state['step'],
            'primary_action' => $primaryAction,
            'next_job' => $this->nextJob($stateKey, $primaryAction),
            'connection_summary' => $this->normalizeDisplayText($this->connectionSummary($source, $hasLocation, $lastTestAt, $previewMode)),
            'preview_summary' => $this->normalizeDisplayText($this->previewSummary($hasPreview, $previewMode, $lastPreviewAt)),
            'mapping_summary' => $this->normalizeDisplayText($this->mappingSummary($hasMappings, $missingRequiredMappingCount)),
            'sync_frequency_label' => (string) ($source->sync_frequency_label ?? 'Manuel'),
            'last_test_display' => $lastTestAt?->format('d.m.Y H:i') ?? 'Kontrol edilmedi',
            'last_preview_display' => $lastPreviewAt?->format('d.m.Y H:i') ?? 'Henüz önizleme alınmadı',
            'last_sync_display' => $this->normalizeDisplayText($this->formatSyncDate($latestSync?->finished_at)),
            'next_sync_display' => $this->normalizeDisplayText((string) ($source->next_sync_label ?? 'Otomatik plan yok')),
            'readiness_help' => $this->normalizeDisplayText($this->readinessHelp($stateKey)),
            'step_cards' => $this->stepCards($state['step'], $hasLocation, $hasSuccessfulConnection, $hasPreview, $missingRequiredMappingCount, $categoryPendingCount, $stateKey === 'active_sync'),
            'first_import_checks' => $this->firstImportChecks(
                $hasSuccessfulConnection,
                $hasPreview,
                $hasMappings && $missingRequiredMappingCount === 0,
                $pricing['gross_list'] !== 'Mapping kontrolü gerekli' && $pricing['currency'] !== 'Mapping kontrolü gerekli',
                $rawVariants > 0 || $standardVariants > 0,
                (int) ($source->category_mappings_count ?? 0) > 0 && $categoryPendingCount === 0
            ),
            'pricing_contract' => $pricing,
            'advanced_actions' => $this->advancedActions($source),
            'operational_notes' => [
                'Bağlantı kontrolü ürün verisi kaydetmez; işlem sistem günlüğüne kaydedilebilir.',
                'Önizleme ürün havuzuna veya Abone Firma kataloglarına yazmaz; önizleme denemesi sistem günlüğüne kaydedilebilir.',
            ],
            'activity_summary' => [
                'raw_products' => $rawProducts,
                'standard_products' => $standardProducts,
                'tenant_catalog_products' => $tenantCatalogProducts,
                'category_pending' => $categoryPendingCount,
                'warning_products' => $warningProductCount,
            ],
            'location_host' => $this->locationHost($displayLocation),
            'location_display' => $this->normalizeDisplayText($displayLocation !== '' ? $displayLocation : '-'),
            'is_temp_profile' => (bool) ($source->is_temp_profile ?? false),
        ];
    }

    private function primaryAction(SupplierSource $source, string $stateKey): array
    {
        return match ($stateKey) {
            'source_info_missing' => [
                'label' => 'Kaynak Bilgilerini Tamamla',
                'type' => 'link',
                'url' => route('admin.super.product-data-hub.sources.edit', $source),
                'method' => 'GET',
                'disabled' => false,
                'help' => 'Bağlantı, profil ve güncelleme ayarlarını tamamlayın.',
            ],
            'connection_check_required' => [
                'label' => 'Bağlantıyı Kontrol Et',
                'type' => 'form',
                'url' => route('admin.super.product-data-hub.sources.test', $source),
                'method' => 'POST',
                'disabled' => false,
                'help' => 'Bu işlem ürün verisi kaydetmez. Sonuç sistem günlüğüne kaydedilebilir.',
            ],
            'preview_required' => [
                'label' => 'Ön Kontrol',
                'type' => 'link',
                'url' => route('admin.super.product-data-hub.sources.preview', $source),
                'method' => 'GET',
                'disabled' => false,
                'help' => 'Örnek ürünleri görüp fiyat, para birimi ve varyant yapısını kontrol edin.',
            ],
            'mapping_required' => [
                'label' => 'Alan Eşlemeyi Tamamla',
                'type' => 'link',
                'url' => route('admin.super.product-data-hub.field-mappings.source', $source),
                'method' => 'GET',
                'disabled' => false,
                'help' => 'Zorunlu alanlar tamamlanmadan güvenli aktarım hazır kabul edilmez.',
            ],
            'first_import_ready' => [
                'label' => 'Ürünleri Senkronize Et',
                'type' => 'button',
                'url' => null,
                'method' => 'NONE',
                'disabled' => true,
                'help' => 'Uygun ürünler, Abone Firmanın aktif tedarikçi erişimine göre kataloğa otomatik yansır.',
            ],
            default => [
                'label' => 'Otomatik Güncelleme Ayarları',
                'type' => 'link',
                'url' => route('admin.super.product-data-hub.sources.edit', $source),
                'method' => 'GET',
                'disabled' => false,
                'help' => 'Kaynağın zamanlama ve güvenlik ayarlarını buradan yönetin.',
            ],
        };
    }

    private function advancedActions(SupplierSource $source): array
    {
        return [
            ['label' => 'Ön Kontrolü Hazırla', 'type' => 'form', 'url' => route('admin.super.product-data-hub.sources.stage-preview', $source), 'method' => 'POST'],
            ['label' => 'Ürün Havuzunu Oluştur', 'type' => 'form', 'url' => route('admin.super.product-data-hub.sources.build-standard-products', $source), 'method' => 'POST'],
            ['label' => 'Değişiklik Ön Kontrolü', 'type' => 'form', 'url' => route('admin.super.product-data-hub.sources.delta-dry-run', $source), 'method' => 'POST'],
            ['label' => 'Onaylı Fiyat/Stok Güncelle', 'type' => 'form', 'url' => route('admin.super.product-data-hub.sources.apply-price-stock', $source), 'method' => 'POST'],
            ['label' => 'Satış Listesi Onar', 'type' => 'form', 'url' => route('admin.super.product-data-hub.sources.apply-price-stock-project-dirty', $source), 'method' => 'POST'],
            ['label' => 'Ürünleri Senkronize Et', 'type' => 'form', 'url' => route('admin.super.product-data-hub.sources.sync', $source), 'method' => 'POST'],
            ['label' => 'Kaynak Kayıtlarını Aç', 'type' => 'link', 'url' => route('admin.super.product-data-hub.supplier-products', ['source_id' => $source->id]), 'method' => 'GET'],
            ['label' => 'Raporları Aç', 'type' => 'link', 'url' => route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]), 'method' => 'GET'],
        ];
    }

    private function stepCards(
        string $activeStep,
        bool $hasLocation,
        bool $hasSuccessfulConnection,
        bool $hasPreview,
        int $missingRequiredMappingCount,
        int $categoryPendingCount,
        bool $isActive
    ): array {
        $steps = [
            'source' => ['title' => 'Kaynak Bilgileri', 'done' => $hasLocation, 'note' => $hasLocation ? 'Kaynak adresi veya dosya bilgisi tanımlı.' : 'Kaynak adresi veya dosya bilgisi eksik.'],
            'connection' => ['title' => 'Bağlantı / Dosya Kontrolü', 'done' => $hasSuccessfulConnection, 'note' => $hasSuccessfulConnection ? 'Son bağlantı veya dosya kontrolü alınmış görünüyor.' : 'Bağlantı veya dosya kontrolü yapılmalı.'],
            'field_mapping' => ['title' => 'Alan Eşleme', 'done' => $missingRequiredMappingCount === 0, 'note' => $missingRequiredMappingCount === 0 ? 'Zorunlu alanlar tamam.' : $missingRequiredMappingCount . ' zorunlu alan eksik.'],
            'category' => ['title' => 'Kategori Eşleme', 'done' => $categoryPendingCount === 0, 'note' => $categoryPendingCount === 0 ? 'Kategori eşleme kuyruğu temiz görünüyor.' : $categoryPendingCount . ' kategori eşleşmemiş kaydı var.'],
            'preview' => ['title' => 'Örnek Ürün Ön Kontrolü', 'done' => $hasPreview, 'note' => $hasPreview ? 'Ön kontrol kaydı mevcut.' : 'Henüz örnek ürün ön kontrolü alınmadı.'],
            'sync' => ['title' => 'Ürünleri Senkronize Et', 'done' => $isActive, 'note' => $isActive ? 'Kaynak aktif akışta çalışıyor.' : 'Senkronizasyon hazır olduğunda uygun ürünler otomatik işlenir.'],
            'auto_settings' => ['title' => 'Otomatik Güncelleme Ayarları', 'done' => $isActive || $categoryPendingCount === 0, 'note' => 'Zamanlama ve güvenli güncelleme politikası buradan izlenir.'],
            'reviews' => ['title' => 'Bekleyen Kontroller', 'done' => $categoryPendingCount === 0, 'note' => $categoryPendingCount === 0 ? 'Bekleyen kategori kontrolü yok.' : $categoryPendingCount . ' kategori eşleşmemiş kaydı var.'],
        ];

        $cards = [];
        $keys = array_keys($steps);
        $activeIndex = array_search($activeStep, $keys, true);

        foreach ($keys as $index => $key) {
            $step = $steps[$key];
            $status = $index < $activeIndex
                ? 'done'
                : ($key === $activeStep
                    ? 'current'
                    : 'locked');

            if ($key === 'sync' && $activeStep === 'auto_settings') {
                $status = 'done';
            }

            $cards[] = [
                'key' => $key,
                'title' => $step['title'],
                'status' => $status,
                'status_label' => match ($status) {
                    'done' => 'Tamamlandı',
                    'current' => 'Aktif adım',
                    default => 'Kilitli',
                },
                'note' => $step['note'],
            ];
        }

        return $cards;
    }

    private function firstImportChecks(
        bool $connectionReady,
        bool $previewReady,
        bool $mappingReady,
        bool $priceCurrencyReady,
        bool $variantReady,
        bool $categoryReady
    ): array {
        return [
            ['label' => 'Bağlantı başarılı', 'done' => $connectionReady],
            ['label' => 'Önizleme kontrol edildi', 'done' => $previewReady],
            ['label' => 'Zorunlu alanlar eşlendi', 'done' => $mappingReady],
            ['label' => 'Fiyat / para birimi doğrulandı', 'done' => $priceCurrencyReady],
            ['label' => 'Varyant yapısı doğrulandı', 'done' => $variantReady],
            ['label' => 'Kategori politikası doğrulandı', 'done' => $categoryReady],
        ];
    }

    private function connectionSummary(SupplierSource $source, bool $hasLocation, ?Carbon $lastTestAt, string $previewMode): string
    {
        if (!$hasLocation) {
            return 'Kaynak adresi veya dosya yolu eksik.';
        }

        if ($source->status === 'error' || $previewMode === 'error') {
            return 'Son kontrol sorunlu görünüyor. Bağlantı yeniden doğrulanmalı.';
        }

        if ($lastTestAt === null) {
            return 'Henüz bağlantı kontrolü yapılmadı.';
        }

        return 'Son bağlantı kontrolü ' . $lastTestAt->format('d.m.Y H:i') . '.';
    }

    private function previewSummary(bool $hasPreview, string $previewMode, ?Carbon $lastPreviewAt): string
    {
        if (!$hasPreview) {
            return 'Henüz önizleme alınmadı.';
        }

        if ($previewMode === 'fallback') {
            return 'Son önizleme demo fallback ile alındı; canlı kaynak gibi değerlendirilmemeli.';
        }

        if ($previewMode === 'error') {
            return 'Son önizleme denemesi hata ile sonuçlandı.';
        }

        return 'Son önizleme ' . ($lastPreviewAt?->format('d.m.Y H:i') ?? '-') . ' tarihinde kaydedildi.';
    }

    private function mappingSummary(bool $hasMappings, int $missingRequiredMappingCount): string
    {
        if (!$hasMappings) {
            return 'Henüz mapping kaydı oluşturulmadı.';
        }

        if ($missingRequiredMappingCount > 0) {
            return $missingRequiredMappingCount . ' zorunlu alan eksik.';
        }

        return 'Zorunlu alanlar tamam.';
    }

    private function readinessHelp(string $stateKey): string
    {
        return match ($stateKey) {
            'source_info_missing' => 'Kurulum kaydı tamamlanmadan onboarding ilerlemez.',
            'connection_check_required' => 'Bağlantı kontrolü veri yazmaz; yalnız okuma ve log üretir.',
            'preview_required' => 'Ön kontrol örnek ürünlerle fiyat, para birimi ve varyant yapısını görmenizi sağlar.',
            'mapping_required' => 'Kaynağa özel alan eşleme route’u üzerinden zorunlu alanları tamamlayın.',
            'first_import_ready' => 'Senkronizasyon hazır; katalog yansıması otomatik erişim kurallarına göre ilerler.',
            default => 'Kaynak aktif akışta; günlük bakım ve senkron ayarları devam ediyor.',
        };
    }

    private function nextJob(string $stateKey, array $primaryAction): string
    {
        return match ($stateKey) {
            'source_info_missing' => 'Eksik bağlantı ve profil bilgisini tamamlayın.',
            'connection_check_required' => 'Bağlantıyı no-write kontrol ile doğrulayın.',
            'preview_required' => 'Örnek ürün ön kontrolünü açıp veri kalitesini görün.',
            'mapping_required' => 'Zorunlu alan eşlemelerini kapatın.',
            'first_import_ready' => 'Ürünleri Senkronize Et ile hazırlık durumunu koruyun.',
            default => $primaryAction['label'] . ' ile bakım ayarlarını yönetin.',
        };
    }

    private function pricingContract(SupplierSource $source, string $profileKey): array
    {
        $aliases = (array) config("prodelya_product_data_hub.supplier_profiles.{$profileKey}.field_aliases", []);
        $grossList = $this->findSourceField($aliases, ['list_price']);
        $netReference = $this->findSourceField($aliases, ['purchase_price']);
        $currency = $this->findSourceField($aliases, ['currency']);

        return [
            'gross_list' => $grossList ?? 'Mapping kontrolü gerekli',
            'net_reference' => $netReference ?? 'Ayrı net referans yok',
            'currency' => $currency ?? 'Mapping kontrolü gerekli',
        ];
    }

    private function findSourceField(array $aliases, array $targets): ?string
    {
        foreach ($aliases as $sourceField => $targetField) {
            if (in_array($targetField, $targets, true)) {
                return (string) $sourceField;
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    private function previewMeta(SupplierSource $source): array
    {
        $log = $source->latest_preview_log;
        $metadata = $log?->getAttribute('sync_metadata');

        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeDisplayText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(['\\r\\n', '\\n', '\\r', "\r\n", "\n", "\r"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }

    private function formatSyncDate(mixed $value): string
    {
        if (blank($value)) {
            return 'Henüz sync çalışmadı';
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->format('d.m.Y H:i');
    }

    private function locationHost(string $displayLocation): string
    {
        if ($displayLocation === '') {
            return 'Konum tanımlanmadı';
        }

        $host = parse_url($displayLocation, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return Str::lower($host);
        }

        return basename(str_replace('\\', '/', $displayLocation));
    }
}
