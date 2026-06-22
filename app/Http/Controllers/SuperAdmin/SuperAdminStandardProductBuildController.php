<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\StandardProductBuilderService;
use Illuminate\Http\RedirectResponse;

class SuperAdminStandardProductBuildController extends Controller
{
    public function __construct(
        private readonly StandardProductBuilderService $builder
    ) {
    }

    public function buildFromRaw(SupplierProductRaw $rawProduct): RedirectResponse
    {
        $result = $this->builder->buildFromRawProduct($rawProduct->load('variants'));

        return redirect()
            ->route('admin.super.product-data-hub.raw-products.index')
            ->with('success', "Ham ürün standart ürüne dönüştürüldü. Kod: {$result['standard_product_code']}, Varyasyon: {$result['variant_count']}.");
    }

    public function buildSource(SupplierSource $source): RedirectResponse
    {
        $result = $this->builder->buildManyFromSource($source);

        return redirect()
            ->route('admin.super.product-data-hub.raw-products.index')
            ->with('success', "İşlem tamamlandı: standart ürün {$result['processed']}, varyasyon {$result['variants']}, yeni ürün {$result['created_products']}, güncellenen ürün {$result['updated_products']}, yeni varyasyon {$result['created_variants']}, güncellenen varyasyon {$result['updated_variants']}, atlanan kayıt {$result['skipped']}, uyarılı kayıt {$result['warnings']}, hatalı kayıt {$result['errors']}.");
    }
}
