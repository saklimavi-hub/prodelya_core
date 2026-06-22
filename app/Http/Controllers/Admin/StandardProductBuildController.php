<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\ProductDataHub\StandardProductBuilderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

class StandardProductBuildController extends Controller
{
    public function __construct(
        private readonly StandardProductBuilderService $builder
    ) {
    }

    public function buildFromRaw(SupplierProductRaw $rawProduct): RedirectResponse
    {
        abort(403, 'Standart ürün dönüşümü Super Admin tarafından yönetilir.');
    }

    public function buildSource(SupplierSource $source): RedirectResponse
    {
        abort(403, 'Standart ürün dönüşümü Super Admin tarafından yönetilir.');
    }

    private function currentTenant(): ?TenantAccount
    {
        return request()->attributes->get('current_tenant')
            ?? auth()->user()?->tenantAccount
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->orderBy('id')->first();
    }

    private function ensureSupplierAccess(?int $supplierId): void
    {
        $tenant = $this->currentTenant();

        if (!$tenant || !$supplierId) {
            abort(403, 'Bu kaynağa erişim izniniz yok.');
        }

        $hasAccess = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $supplierId)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (!$hasAccess) {
            abort(403, 'Bu kaynağa erişim izniniz yok.');
        }
    }
}
