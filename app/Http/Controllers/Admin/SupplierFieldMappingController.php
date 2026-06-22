<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SupplierFieldMappingController extends Controller
{
    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary
    ) {
        // TODO: Add middleware for manage_product_data_hub
    }

    public function index(Request $request): View
    {
        abort(403, 'Global alan eşleme ekranı Super Admin tarafından yönetilir.');
    }

    public function show(SupplierSource $source): View
    {
        abort(403, 'Global alan eşleme ekranı Super Admin tarafından yönetilir.');
    }

    public function storeOrUpdate(Request $request, SupplierSource $source): RedirectResponse
    {
        abort(403, 'Global alan eşleme ekranı Super Admin tarafından yönetilir.');
    }

    public function suggest(SupplierSource $source): RedirectResponse
    {
        abort(403, 'Global alan eşleme ekranı Super Admin tarafından yönetilir.');
    }

    public function reset(SupplierSource $source): RedirectResponse
    {
        abort(403, 'Global alan eşleme ekranı Super Admin tarafından yönetilir.');
    }

    private function currentTenant(): ?TenantAccount
    {
        $tenant = request()->attributes->get('current_tenant');

        if ($tenant instanceof TenantAccount) {
            return $tenant;
        }

        return Auth::user()?->tenantAccount
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->where('status', 'active')->first()
            ?? TenantAccount::query()->whereIn('status', ['active', 'trial'])->orderBy('created_at')->first();
    }

    private function allowedSupplierIds(?TenantAccount $tenant)
    {
        if (!$tenant) {
            return collect();
        }

        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->pluck('supplier_id');
    }

    private function tenantCanAccessSupplier(?TenantAccount $tenant, int $supplierId): bool
    {
        return $this->allowedSupplierIds($tenant)->contains($supplierId);
    }

    private function ensureSourceAccess(SupplierSource $source, ?TenantAccount $tenant): void
    {
        if (!$this->tenantCanAccessSupplier($tenant, $source->supplier_id)) {
            abort(403, 'Bu kaynağa erişim izniniz yok.');
        }
    }

    private function supplierKeyForSource(SupplierSource $source): ?string
    {
        return $this->fieldDictionary->detectSupplierKey(
            $source->supplier?->code,
            $source->supplier?->name
        );
    }

    private function upsertMappingForSource(SupplierSource $source, string $sourceField, array $payload, array $standardFields, array $suggested, ?TenantAccount $tenant = null): SupplierFieldMapping
    {
        $targetField = $payload['standard_field_key'] ?: null;
        $fieldMeta = $targetField ? ($standardFields[$targetField] ?? []) : [];
        $normalizedSourceField = $this->fieldDictionary->normalizeSourceFieldWithoutAlias($sourceField);
        $mappingStatus = $payload['mapping_status'] ?? null;

        if (blank($targetField)) {
            $mappingStatus = in_array($mappingStatus, ['ignored', 'needs_review'], true) ? $mappingStatus : 'pending';
        } elseif (blank($mappingStatus) || $mappingStatus === 'pending') {
            $mappingStatus = 'mapped';
        }

        return SupplierFieldMapping::query()->updateOrCreate(
            [
                'supplier_source_id' => $source->id,
                'source_field' => $sourceField,
            ],
            [
                'supplier_id' => $source->supplier_id,
                'tenant_account_id' => $tenant?->id,
                'legacy_field_name' => $normalizedSourceField,
                'target_field' => $targetField,
                'field_type' => $fieldMeta['type'] ?? 'text',
                'mapping_status' => $mappingStatus,
                'confidence_score' => $payload['confidence_score'] ?? ($suggested[$sourceField]['confidence_score'] ?? null),
                'transform_rule' => $payload['transform_rule'] ?? null,
                'note' => $payload['note'] ?? null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'is_required' => (bool) ($fieldMeta['required'] ?? false),
                'meta' => [
                    'supplier_profile' => $this->supplierKeyForSource($source),
                    'normalized_source_field' => $normalizedSourceField,
                    'scope' => $tenant ? 'tenant' : 'global',
                ],
            ]
        );
    }
}
