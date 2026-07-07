<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\StandardPrintType;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use App\Services\TenantPrintOptionService;
use App\Services\TenantPrintSettingSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantPrintSettingController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantPrintSettingSyncService $syncService,
        protected TenantPrintOptionService $tenantPrintOptionService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $canViewFinancialDefaults = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        $query = TenantPrintSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->with(['standardPrintType', 'defaultSubcontractorCompany']);

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('custom_name', 'like', '%' . $search . '%')
                    ->orWhereHas('standardPrintType', function ($typeQuery) use ($search): void {
                        $typeQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('code', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', (string) $request->string('status') === 'active');
        }

        if ($request->filled('production_mode')) {
            $query->where('production_mode', (string) $request->string('production_mode'));
        }

        if ($request->filled('requires_setup')) {
            $query->where('requires_setup', (string) $request->string('requires_setup') === '1');
        }

        $settings = $query
            ->join('standard_print_types', 'standard_print_types.id', '=', 'tenant_print_settings.standard_print_type_id')
            ->orderBy('standard_print_types.sort_order')
            ->orderBy('standard_print_types.name')
            ->select('tenant_print_settings.*')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'active' => TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->where('is_active', true)->count(),
            'internal' => TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->where('production_mode', StandardPrintType::MODE_INTERNAL)->count(),
            'outsourced' => TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->where('production_mode', StandardPrintType::MODE_OUTSOURCED)->count(),
            'requires_setup' => TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->where('requires_setup', true)->count(),
            'passive' => TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->where('is_active', false)->count(),
        ];

        $missingCount = StandardPrintType::query()
            ->where('status', StandardPrintType::STATUS_ACTIVE)
            ->count() - TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->count();

        return view('admin.settings.print-settings.index', [
            'tenant' => $tenant,
            'settings' => $settings,
            'stats' => $stats,
            'missingCount' => max(0, $missingCount),
            'filters' => [
                'search' => $request->get('search', ''),
                'status' => $request->get('status', ''),
                'production_mode' => $request->get('production_mode', ''),
                'requires_setup' => $request->get('requires_setup', ''),
            ],
            'canViewFinancialDefaults' => $canViewFinancialDefaults,
            'productionModeOptions' => StandardPrintType::productionModeLabels(),
        ]);
    }

    public function edit(Request $request, TenantPrintSetting $tenantPrintSetting): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantPrintSetting, $tenant->id);

        $tenantPrintSetting->load(['standardPrintType', 'defaultSubcontractorCompany']);
        $this->tenantPrintOptionService->ensureDefaultsForSetting($tenantPrintSetting);

        return view('admin.settings.print-settings.edit', [
            'tenant' => $tenant,
            'setting' => $tenantPrintSetting,
            'printOptions' => $this->tenantPrintOptionService->optionsForSetting($tenantPrintSetting),
            'canViewFinancialDefaults' => $request->user()?->canViewFinancialData($tenant->id) ?? false,
            'productionModeOptions' => StandardPrintType::productionModeLabels(),
            'setupTypeOptions' => StandardPrintType::setupTypeLabels(),
            'setupStatusOptions' => ['Yok', 'Var', 'Yeni üretilecek', 'Mevcut kullanılacak'],
            'currencyOptions' => [
                'TRY' => 'TRY',
                'USD' => 'USD',
                'EUR' => 'EUR',
            ],
            'subcontractorOptions' => $this->subcontractorCompanyOptions($tenant->id),
        ]);
    }

    public function update(Request $request, TenantPrintSetting $tenantPrintSetting): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantPrintSetting, $tenant->id);

        $canViewFinancialDefaults = $request->user()?->canViewFinancialData($tenant->id) ?? false;
        $validated = $this->validatePayload($request, $tenant->id);

        if (!empty($validated['default_subcontractor_company_id'])) {
            $this->guardSubcontractorCompany((int) $validated['default_subcontractor_company_id'], $tenant->id);
        }

        if (!empty($validated['default_subcontractor_current_account_id'])) {
            $this->guardCurrentAccountScope((int) $validated['default_subcontractor_current_account_id'], $tenant->id);
        }

        $payload = [
            'custom_name' => $validated['custom_name'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'production_mode' => $validated['production_mode'],
            'default_subcontractor_company_id' => $validated['default_subcontractor_company_id'] ?? null,
            'default_subcontractor_current_account_id' => $validated['default_subcontractor_current_account_id'] ?? null,
            'requires_graphic' => (bool) ($validated['requires_graphic'] ?? false),
            'requires_production' => (bool) ($validated['requires_production'] ?? false),
            'requires_setup' => (bool) ($validated['requires_setup'] ?? false),
            'setup_types' => (bool) ($validated['requires_setup'] ?? false)
                ? array_values(array_unique(array_filter((array) ($validated['setup_types'] ?? []))))
                : [],
            'notes' => $validated['notes'] ?? null,
        ];

        if ($canViewFinancialDefaults) {
            $payload['default_currency'] = $validated['default_currency'] ?? null;
            $payload['default_unit_price'] = $validated['default_unit_price'] ?? null;
            $payload['default_setup_cost'] = $validated['default_setup_cost'] ?? null;
        }

        $tenantPrintSetting->fill($payload);
        $tenantPrintSetting->save();

        return redirect()
            ->route('admin.settings.print-settings.edit', $tenantPrintSetting)
            ->with('success', 'Baskı ayarı güncellendi.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $report = $this->syncService->syncForTenant($tenant);

        return redirect()
            ->route('admin.settings.print-settings.index')
            ->with('success', sprintf(
                'Eksik baskı ayarları tamamlandı. Oluşturulan: %d, mevcut bırakılan: %d.',
                (int) ($report['created'] ?? 0),
                (int) ($report['skipped_existing'] ?? 0)
            ));
    }

    public function storeOption(Request $request, TenantPrintSetting $tenantPrintSetting): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantPrintSetting, $tenant->id);
        $this->tenantPrintOptionService->ensureDefaultsForSetting($tenantPrintSetting);

        $validated = $this->validateOptionPayload($request, $tenantPrintSetting, null);
        $canViewFinancialDefaults = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        $tenantPrintOption = TenantPrintOption::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_print_setting_id' => $tenantPrintSetting->id,
            'standard_print_type_id' => $tenantPrintSetting->standard_print_type_id,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'default_unit_price' => $canViewFinancialDefaults ? ($validated['default_unit_price'] ?? null) : null,
            'requires_setup' => (bool) ($validated['requires_setup'] ?? false),
            'setup_type' => (bool) ($validated['requires_setup'] ?? false) ? ($validated['setup_type'] ?? null) : null,
            'setup_status_default' => (bool) ($validated['requires_setup'] ?? false) ? ($validated['setup_status_default'] ?? null) : null,
        ]);

        if ($tenantPrintOption->is_default) {
            TenantPrintOption::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('tenant_print_setting_id', $tenantPrintSetting->id)
                ->whereKeyNot($tenantPrintOption->id)
                ->update(['is_default' => false]);
        } else {
            $this->tenantPrintOptionService->normalizeDefaultOption($tenantPrintSetting);
        }

        return redirect()
            ->route('admin.settings.print-settings.edit', $tenantPrintSetting)
            ->with('success', 'Baskı seçeneği eklendi.');
    }

    public function updateOption(Request $request, TenantPrintSetting $tenantPrintSetting, TenantPrintOption $tenantPrintOption): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantPrintSetting, $tenant->id);
        $this->guardPrintOptionScope($tenantPrintOption, $tenant->id, $tenantPrintSetting->id);

        $validated = $this->validateOptionPayload($request, $tenantPrintSetting, $tenantPrintOption);
        $canViewFinancialDefaults = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        $tenantPrintOption->fill([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'requires_setup' => (bool) ($validated['requires_setup'] ?? false),
            'setup_type' => (bool) ($validated['requires_setup'] ?? false) ? ($validated['setup_type'] ?? null) : null,
            'setup_status_default' => (bool) ($validated['requires_setup'] ?? false) ? ($validated['setup_status_default'] ?? null) : null,
        ]);

        if ($canViewFinancialDefaults) {
            $tenantPrintOption->default_unit_price = $validated['default_unit_price'] ?? null;
        }

        $tenantPrintOption->save();

        if ($tenantPrintOption->is_default) {
            TenantPrintOption::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('tenant_print_setting_id', $tenantPrintSetting->id)
                ->whereKeyNot($tenantPrintOption->id)
                ->update(['is_default' => false]);
        } else {
            $this->tenantPrintOptionService->normalizeDefaultOption($tenantPrintSetting);
        }

        return redirect()
            ->route('admin.settings.print-settings.edit', $tenantPrintSetting)
            ->with('success', 'Baskı seçeneği güncellendi.');
    }

    public function makeDefaultOption(Request $request, TenantPrintSetting $tenantPrintSetting, TenantPrintOption $tenantPrintOption): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($tenantPrintSetting, $tenant->id);
        $this->guardPrintOptionScope($tenantPrintOption, $tenant->id, $tenantPrintSetting->id);

        TenantPrintOption::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('tenant_print_setting_id', $tenantPrintSetting->id)
            ->update(['is_default' => false]);

        $tenantPrintOption->forceFill(['is_default' => true])->save();

        return redirect()
            ->route('admin.settings.print-settings.edit', $tenantPrintSetting)
            ->with('success', 'Varsayılan baskı seçeneği güncellendi.');
    }

    private function validatePayload(Request $request, int $tenantId): array
    {
        return $request->validate([
            'custom_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'production_mode' => ['required', Rule::in([
                StandardPrintType::MODE_INTERNAL,
                StandardPrintType::MODE_OUTSOURCED,
                StandardPrintType::MODE_BOTH,
            ])],
            'requires_graphic' => ['nullable', 'boolean'],
            'requires_production' => ['nullable', 'boolean'],
            'requires_setup' => ['nullable', 'boolean'],
            'setup_types' => ['nullable', 'array'],
            'setup_types.*' => ['required', Rule::in(array_keys(StandardPrintType::setupTypeLabels()))],
            'default_subcontractor_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'default_subcontractor_current_account_id' => ['nullable', 'integer', Rule::exists('current_accounts', 'id')->where(
                fn ($query) => $query->where('tenant_account_id', $tenantId)
            )],
            'default_currency' => ['nullable', Rule::in(['TRY', 'USD', 'EUR'])],
            'default_unit_price' => ['nullable', 'numeric', 'min:0'],
            'default_setup_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function validateOptionPayload(Request $request, TenantPrintSetting $setting, ?TenantPrintOption $option): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tenant_print_options', 'code')
                    ->ignore($option?->id)
                    ->where(fn ($query) => $query
                        ->where('tenant_account_id', $setting->tenant_account_id)
                        ->where('tenant_print_setting_id', $setting->id)),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_default' => ['nullable', 'boolean'],
            'default_unit_price' => ['nullable', 'numeric', 'min:0'],
            'requires_setup' => ['nullable', 'boolean'],
            'setup_type' => ['nullable', Rule::in(array_keys(StandardPrintType::setupTypeLabels()))],
            'setup_status_default' => ['nullable', Rule::in(['Yok', 'Var', 'Yeni üretilecek', 'Mevcut kullanılacak'])],
        ]);
    }

    private function subcontractorCompanyOptions(int $tenantId)
    {
        return Company::query()
            ->where('tenant_account_id', $tenantId)
            ->whereHas('companyRoles', fn ($query) => $query->where('role_key', 'print_fason'))
            ->orderBy('legal_name')
            ->get(['id', 'legal_name', 'short_name']);
    }

    private function guardTenantScope(TenantPrintSetting $setting, int $tenantId): void
    {
        if ($setting->tenant_account_id !== $tenantId) {
            abort(403, 'Bu baskı ayarına erişim yetkiniz yok.');
        }
    }

    private function guardPrintOptionScope(TenantPrintOption $option, int $tenantId, int $settingId): void
    {
        if ($option->tenant_account_id !== $tenantId || $option->tenant_print_setting_id !== $settingId) {
            abort(403, 'Bu baskı seçeneğine erişim yetkiniz yok.');
        }
    }

    private function guardSubcontractorCompany(int $companyId, int $tenantId): void
    {
        $valid = Company::query()
            ->where('tenant_account_id', $tenantId)
            ->whereKey($companyId)
            ->whereHas('companyRoles', fn ($query) => $query->where('role_key', 'print_fason'))
            ->exists();

        if (!$valid) {
            throw ValidationException::withMessages([
                'default_subcontractor_company_id' => 'Seçilen fasoncu kaydı bu tenant için uygun değil.',
            ]);
        }
    }

    private function guardCurrentAccountScope(int $currentAccountId, int $tenantId): void
    {
        $valid = CurrentAccount::query()
            ->where('tenant_account_id', $tenantId)
            ->whereKey($currentAccountId)
            ->exists();

        if (!$valid) {
            throw ValidationException::withMessages([
                'default_subcontractor_current_account_id' => 'Seçilen cari kayıt bu tenant için uygun değil.',
            ]);
        }
    }
}
