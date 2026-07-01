<?php

namespace App\Services\ProductDataHub;

use App\Models\Supplier;
use App\Models\SupplierSource;
use Illuminate\Support\Collection;

class PozitronSourceProvisioningService
{
    public const SUPPLIER_NAME = 'Pozitron Promosyon';
    public const SUPPLIER_CODE = 'POZITRON';
    public const SOURCE_NAME = 'Pozitron Promosyon JSON';
    public const SOURCE_PROFILE_TEMPLATE = 'POZITRON_JSON';
    public const SOURCE_PROFILE_KEY = 'POZITRON';
    public const SOURCE_URL = 'https://pozitronpromosyon.com/wp-json/public-api/v1/urunler?all=1';
    public const BACKUP_XML_URL = 'https://pozitronpromosyon.com/wp-json/public-api/v1/urunler-xml?all=1';

    public function ensureSource(): array
    {
        $supplierCreated = false;
        $sourceCreated = false;
        $sourceUpdated = false;

        $supplier = Supplier::query()
            ->where('code', self::SUPPLIER_CODE)
            ->orWhere('name', self::SUPPLIER_NAME)
            ->orderBy('id')
            ->first();

        if (!$supplier) {
            $supplier = Supplier::query()->create([
                'name' => self::SUPPLIER_NAME,
                'code' => self::SUPPLIER_CODE,
                'status' => 'active',
            ]);
            $supplierCreated = true;
        } else {
            $supplierChanges = [];

            if ($supplier->name !== self::SUPPLIER_NAME) {
                $supplierChanges['name'] = self::SUPPLIER_NAME;
            }
            if ($supplier->code !== self::SUPPLIER_CODE) {
                $supplierChanges['code'] = self::SUPPLIER_CODE;
            }
            if ($supplier->status !== 'active') {
                $supplierChanges['status'] = 'active';
            }

            if ($supplierChanges !== []) {
                $supplier->update($supplierChanges);
            }
        }

        $source = SupplierSource::query()
            ->where(function ($query) use ($supplier) {
                $query->where('supplier_id', $supplier->id)
                    ->where('source_name', self::SOURCE_NAME);
            })
            ->orWhere(function ($query) {
                $query->where('source_name', self::SOURCE_NAME)
                    ->where('url', self::SOURCE_URL);
            })
            ->orderBy('id')
            ->first();

        $config = $this->buildSourceConfig($source?->config ?? []);

        if (!$source) {
            $source = SupplierSource::query()->create([
                'supplier_id' => $supplier->id,
                'source_type' => 'api',
                'source_name' => self::SOURCE_NAME,
                'url' => self::SOURCE_URL,
                'config' => $config,
                'status' => 'active',
            ]);
            $sourceCreated = true;
        } else {
            $payload = [
                'supplier_id' => $supplier->id,
                'source_type' => 'api',
                'source_name' => self::SOURCE_NAME,
                'url' => self::SOURCE_URL,
                'config' => $config,
                'status' => 'active',
            ];

            if (
                $source->supplier_id !== $payload['supplier_id']
                || $source->source_type !== $payload['source_type']
                || $source->source_name !== $payload['source_name']
                || $source->url !== $payload['url']
                || $source->status !== $payload['status']
                || $source->config !== $payload['config']
            ) {
                $source->update($payload);
                $sourceUpdated = true;
            }
        }

        $duplicateSupplierCount = Supplier::query()
            ->where('name', self::SUPPLIER_NAME)
            ->orWhere('code', self::SUPPLIER_CODE)
            ->count();

        $duplicateSourceCount = SupplierSource::query()
            ->where('source_name', self::SOURCE_NAME)
            ->count();

        return [
            'supplier' => $supplier->fresh(),
            'source' => $source->fresh(),
            'supplier_created' => $supplierCreated,
            'source_created' => $sourceCreated,
            'source_updated' => $sourceUpdated,
            'duplicate_supplier_count' => $duplicateSupplierCount,
            'duplicate_source_count' => $duplicateSourceCount,
        ];
    }

    public function buildPreviewSummary(array $preview): array
    {
        $products = collect($preview['products'] ?? []);
        $variants = collect($preview['variants'] ?? []);

        return [
            'records_read' => (int) ($preview['stats']['records_read'] ?? 0),
            'product_count' => $products->count(),
            'variant_count' => $variants->count(),
            'multi_variant_product_count' => $products->filter(function (array $product) use ($variants) {
                $parentKey = $product['parent_supplier_product_id']
                    ?? $product['supplier_product_id']
                    ?? $product['supplier_product_code']
                    ?? null;

                return $variants->where('parent_supplier_product_id', $parentKey)->count() > 1;
            })->count(),
            'single_variant_product_count' => $products->filter(function (array $product) use ($variants) {
                $parentKey = $product['parent_supplier_product_id']
                    ?? $product['supplier_product_id']
                    ?? $product['supplier_product_code']
                    ?? null;

                return $variants->where('parent_supplier_product_id', $parentKey)->count() === 1;
            })->count(),
            'flat_sellable_fallback_count' => $variants->filter(fn (array $variant) => collect($variant['warnings'] ?? [])->contains(
                'Bu üründe varyasyon listesi boş geldiği için flat/satılabilir ürün olarak değerlendirildi.'
            ))->count(),
            'bundle_component_product_count' => $products->filter(function (array $product) {
                return filled(data_get($product, 'normalized_payload.stock_source_type'))
                    || (int) data_get($product, 'normalized_payload.bundle_component_count', 0) > 0;
            })->count(),
            'usd_priced_parent_count' => $products->filter(fn (array $product) => ($product['currency'] ?? null) === 'USD' && filled($product['list_price'] ?? null))->count(),
            'usd_priced_variant_count' => $variants->filter(fn (array $variant) => ($variant['currency'] ?? null) === 'USD' && filled($variant['list_price'] ?? null))->count(),
            'image_present_count' => $products->filter(fn (array $product) => filled($product['image_url'] ?? null))->count()
                + $variants->filter(fn (array $variant) => filled($variant['variant_image_url'] ?? null))->count(),
            'image_fallback_used_count' => $products->filter(fn (array $product) => (bool) ($product['image_fallback_used'] ?? false))->count()
                + $variants->filter(fn (array $variant) => (bool) ($variant['image_fallback_used'] ?? false))->count(),
            'category_read_count' => $products->filter(fn (array $product) => filled($product['supplier_category_name'] ?? null))->count(),
        ];
    }

    private function buildSourceConfig(array $existingConfig = []): array
    {
        return [
            'ui_source_type' => 'json',
            'format' => 'json',
            'profile_key' => self::SOURCE_PROFILE_KEY,
            'source_profile_template' => self::SOURCE_PROFILE_TEMPLATE,
            'currency' => 'USD',
            'pricing_policy_type' => 'list_price',
            'net_price_warning' => false,
            'supplier_prefix' => 'PZ',
            'generated_code_template' => '{PREFIX}-{SUPPLIER_PRODUCT_CODE}',
            'generated_variant_code_template' => '{PREFIX}-{VARIANT_STOCK_CODE}',
            'sync_frequency' => 'manual',
            'sync_auto_build' => (bool) ($existingConfig['sync_auto_build'] ?? true),
            'sync_auto_project_to_tenant_catalog' => (bool) ($existingConfig['sync_auto_project_to_tenant_catalog'] ?? true),
            'sync_block_on_missing_category' => false,
            'missing_category_policy' => 'warn_and_project',
            'sync_block_on_missing_price' => false,
            'sync_block_on_conflict_category' => true,
            'sync_allow_warning_products_to_catalog' => true,
            'sync_policy' => [
                'sync_frequency' => 'manual',
                'update_stock' => true,
                'update_price' => true,
                'update_images' => true,
                'update_categories' => true,
                'sync_auto_build' => (bool) ($existingConfig['sync_auto_build'] ?? true),
                'sync_auto_project_to_tenant_catalog' => (bool) ($existingConfig['sync_auto_project_to_tenant_catalog'] ?? true),
                'sync_block_on_missing_category' => false,
                'missing_category_policy' => 'warn_and_project',
                'sync_block_on_missing_price' => false,
                'sync_block_on_conflict_category' => true,
                'sync_allow_warning_products_to_catalog' => true,
                'missing_product_policy' => 'manual_review',
                'missing_product_grace_runs' => 1,
                'report_enabled' => true,
                'report_channel' => 'screen',
            ],
            'http_method' => 'GET',
            'auth_type' => 'none',
            'timeout_seconds' => (int) ($existingConfig['timeout_seconds'] ?? 25),
            'source_file_path' => $existingConfig['source_file_path'] ?? null,
            'product_node_path' => null,
            'items_path' => null,
            'proxy_strategy' => $existingConfig['proxy_strategy'] ?? 'none',
            'enrich_gallery_from_product_page' => (bool) ($existingConfig['enrich_gallery_from_product_page'] ?? false),
            'max_gallery_enrichment_products' => (int) ($existingConfig['max_gallery_enrichment_products'] ?? 5),
            'max_gallery_images' => (int) ($existingConfig['max_gallery_images'] ?? 10),
            'product_page_gallery_selector' => $existingConfig['product_page_gallery_selector'] ?? null,
            'backup_validation_url' => self::BACKUP_XML_URL,
            'notes' => $existingConfig['notes'] ?? null,
        ];
    }
}
