<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantDeliveryType;
use App\Services\TenantDeliveryTypeService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantDeliveryTypeController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantDeliveryTypeService $tenantDeliveryTypeService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $types = $this->tenantDeliveryTypeService->ensureDefaultsForTenant($tenant);

        return view('admin.settings.delivery-types.index', [
            'tenant' => $tenant,
            'deliveryTypes' => $types,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $validated = $this->validatePayload($request, $tenant->id);

        $deliveryType = TenantDeliveryType::query()->create([
            'tenant_account_id' => $tenant->id,
            'name' => trim((string) $validated['name']),
            'code' => $this->resolveCode($validated),
            'description' => filled($validated['description'] ?? null) ? trim((string) $validated['description']) : null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        if ($deliveryType->is_default) {
            $this->tenantDeliveryTypeService->setDefault($deliveryType);
        } else {
            $this->tenantDeliveryTypeService->ensureSingleDefault($tenant->id);
        }

        return redirect()
            ->route('admin.settings.delivery-types.index')
            ->with('success', 'Teslimat tipi eklendi.');
    }

    public function update(Request $request, TenantDeliveryType $tenantDeliveryType): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantDeliveryType, $tenant->id);

        $validated = $this->validatePayload($request, $tenant->id, $tenantDeliveryType->id);

        $tenantDeliveryType->fill([
            'name' => trim((string) $validated['name']),
            'code' => $this->resolveCode($validated, $tenantDeliveryType),
            'description' => filled($validated['description'] ?? null) ? trim((string) $validated['description']) : null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        if (!$tenantDeliveryType->is_active && $tenantDeliveryType->is_default) {
            throw ValidationException::withMessages([
                'is_active' => 'Varsayılan teslimat tipi pasif yapılamaz.',
            ]);
        }

        $tenantDeliveryType->save();

        if ($tenantDeliveryType->is_default) {
            $this->tenantDeliveryTypeService->setDefault($tenantDeliveryType);
        } else {
            $this->tenantDeliveryTypeService->ensureSingleDefault($tenant->id);
        }

        return redirect()
            ->route('admin.settings.delivery-types.index')
            ->with('success', 'Teslimat tipi güncellendi.');
    }

    public function makeDefault(Request $request, TenantDeliveryType $tenantDeliveryType): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantDeliveryType, $tenant->id);

        $this->tenantDeliveryTypeService->setDefault($tenantDeliveryType);

        return redirect()
            ->route('admin.settings.delivery-types.index')
            ->with('success', 'Varsayılan teslimat tipi güncellendi.');
    }

    private function validatePayload(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tenant_delivery_types', 'name')
                    ->where(fn ($query) => $query->where('tenant_account_id', $tenantId))
                    ->ignore($ignoreId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('tenant_delivery_types', 'code')
                    ->where(fn ($query) => $query->where('tenant_account_id', $tenantId))
                    ->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Teslimat tipi adı zorunludur.',
        ]);
    }

    private function resolveCode(array $validated, ?TenantDeliveryType $tenantDeliveryType = null): string
    {
        $candidate = trim((string) ($validated['code'] ?? ''));

        if ($candidate === '' && $tenantDeliveryType) {
            $candidate = $tenantDeliveryType->code;
        }

        return TenantDeliveryType::makeCode($candidate !== '' ? $candidate : (string) $validated['name']);
    }

    private function guardTenantScope(TenantDeliveryType $tenantDeliveryType, int $tenantId): void
    {
        if ((int) $tenantDeliveryType->tenant_account_id !== $tenantId) {
            abort(403, 'Bu teslimat tipine erişim yetkiniz yok.');
        }
    }
}
