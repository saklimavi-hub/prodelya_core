<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Collection;

class ProductHubOperationFlowService
{
    public function buildOverview(): array
    {
        $visibleSourceIds = SupplierSource::query()
            ->visibleInProductDataHub()
            ->pluck('id');

        $latestRun = ProductDataHubSyncRun::query()
            ->with('source.supplier')
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->latest('id')
            ->first();

        $openReviewChanges = ProductDataHubSyncChange::query()
            ->whereHas('run', function ($query) use ($visibleSourceIds) {
                $query->when(
                    $visibleSourceIds->isNotEmpty(),
                    fn ($builder) => $builder->whereIn('supplier_source_id', $visibleSourceIds),
                    fn ($builder) => $builder->whereRaw('1 = 0')
                );
            })
            ->openReview()
            ->get();

        $pendingCategoryId = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->value('id');

        $categoryWaitingProducts = StandardProduct::query()
            ->when($pendingCategoryId, function ($query) use ($pendingCategoryId) {
                $query->where(function ($inner) use ($pendingCategoryId) {
                    $inner->whereNull('standard_category_id')
                        ->orWhere('standard_category_id', $pendingCategoryId)
                        ->orWhere('meta->category_missing_warning', true);
                });
            }, function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNull('standard_category_id')
                        ->orWhere('meta->category_missing_warning', true);
                });
            })
            ->count();

        $pendingCategoryMappings = SupplierCategoryMapping::query()
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->where(function ($query) {
                $query->whereNull('standard_category_id')
                    ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict']);
            })
            ->count();

        $tenantOutputBlocks = TenantSupplierAccess::query()
            ->where(function ($query) {
                $query->where('is_active', false)
                    ->orWhere('visible_in_catalog', false)
                    ->orWhere('can_view_products', false)
                    ->orWhere('can_use_in_quotes', false);
            })
            ->count();

        $projectionIssues = TenantCatalogProduct::query()
            ->whereIn('catalog_status', ['missing_category', 'missing_price', 'blocked', 'inactive_candidate', 'missing_from_feed'])
            ->count();

        $latestPayload = $latestRun?->report_payload ?? [];
        $applySummary = data_get($latestPayload, 'delta_apply_summary', []);
        $autoUpdated = (int) data_get($applySummary, 'price_stock_applied', 0);
        if ($autoUpdated === 0) {
            $autoUpdated = (int) data_get($latestPayload, 'projection.updated_products', $latestRun?->products_updated ?? 0);
        }

        $reviewRequired = $openReviewChanges->count();
        $newItems = $openReviewChanges
            ->whereIn('change_type', ['new_product', 'new_variant'])
            ->count();
        $identityIssues = $openReviewChanges
            ->whereIn('change_type', ['missing_product', 'missing_variant', 'variant_structure_changed'])
            ->count();

        $counts = [
            'auto_updated' => $autoUpdated,
            'review_required' => $reviewRequired,
            'new_items' => $newItems,
            'category_waiting' => $pendingCategoryMappings + $categoryWaitingProducts,
            'projection_issues' => $projectionIssues,
            'tenant_output_blocks' => $tenantOutputBlocks,
            'price_changed' => (int) ($latestRun?->price_changed_count ?? 0),
            'stock_changed' => (int) ($latestRun?->stock_changed_count ?? 0),
            'identity_issues' => $identityIssues,
        ];

        return [
            'latest_run' => $latestRun,
            'counts' => $counts,
            'cards' => [
                [
                    'key' => 'clean_flow',
                    'title' => 'Temiz Akış',
                    'count' => $counts['auto_updated'],
                    'tone' => 'green',
                    'copy' => 'Normal fiyat ve stok değişimleri sessiz akışta güncellenir. Burada ekstra karar değil, bugünün otomatik ilerleyen hacmi görünür.',
                    'href' => route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'clean_flow']),
                    'action' => 'Temiz akışı gör',
                ],
                [
                    'key' => 'review_queue',
                    'title' => 'İnceleme Gerekenler',
                    'count' => $counts['review_required'],
                    'tone' => 'amber',
                    'copy' => 'Sadece manuel karar isteyen kayıtlar burada toplanır. Normal değişiklikler için ek komut zinciri beklenmez.',
                    'href' => route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'review_queue']),
                    'action' => 'İnceleme kuyruğunu aç',
                ],
                [
                    'key' => 'new_items',
                    'title' => 'Yeni Ürünler',
                    'count' => $counts['new_items'],
                    'tone' => 'blue',
                    'copy' => 'Yeni ürün ve varyantlar review kuyruğunda kalır; doğrudan satışa çıkmadan önce kontrol ister.',
                    'href' => route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1]),
                    'action' => 'Yeni kayıtları aç',
                ],
                [
                    'key' => 'category_waiting',
                    'title' => 'Kategori Bekleyenler',
                    'count' => $counts['category_waiting'],
                    'tone' => 'amber',
                    'copy' => 'Kategori kararı bekleyen kayıtlar satış akışını yavaşlatır. Burada yalnız gerçekten karar gerektiren eşleme işi tutulur.',
                    'href' => route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'category_waiting']),
                    'action' => 'Kategori kuyruğunu aç',
                ],
                [
                    'key' => 'projection_issues',
                    'title' => 'Projection Sorunları',
                    'count' => $counts['projection_issues'],
                    'tone' => 'red',
                    'copy' => 'Projection veya katalog yansıması geride kalan ürünler teklif ve katalog fiyatının eskimesine neden olabilir.',
                    'href' => route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'projection_issues']),
                    'action' => 'Projection sorunlarını aç',
                ],
                [
                    'key' => 'tenant_output_blocks',
                    'title' => 'Tenant Çıkışı Blokajları',
                    'count' => $counts['tenant_output_blocks'],
                    'tone' => 'purple',
                    'copy' => 'Tedarikçi erişimi, görünürlük veya quote kullanımı kapalıysa ürün doğru olsa bile satışa açılamaz.',
                    'href' => route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'tenant_output_blocks']),
                    'action' => 'Blokajları aç',
                ],
            ],
            'headline' => 'Bugün aksiyon gereken ürün var mı?',
            'supporting_note' => 'Hedef akış: güvenli fiyat ve stok değişimleri sessizce ilerler; yalnız yeni ürün, kategori eksikliği, kimlik sorunu ve projection blokajı operatör işi üretir.',
        ];
    }
}
