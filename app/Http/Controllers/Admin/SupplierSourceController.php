<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\FeedSyncLog;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\RawProductStagingService;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SupplierSourceController extends Controller
{
    public function __construct(
        private readonly PreviewParserService $previewParser,
        private readonly RawProductStagingService $rawProductStaging,
        private readonly SourceFetchService $sourceFetch,
        private readonly SourceParserService $sourceParser
    ) {
    }

    /**
     * Get current tenant
     */
    private function currentTenant()
    {
        $tenant = request()->attributes->get('current_tenant');

        if ($tenant instanceof TenantAccount) {
            return $tenant;
        }

        $tenant = Auth::user()?->tenantAccount ?? null;
        if (!$tenant) {
            $tenant = TenantAccount::query()->first();
        }

        return $tenant;
    }

    /**
     * Display a listing of supplier sources
     */
    public function index(): View
    {
        $this->abortTenantAccess();

        $currentTenant = $this->currentTenant();
        $allowedSupplierIds = $this->allowedSupplierIds($currentTenant);

        $sources = SupplierSource::with(['supplier'])
            ->when(
                $allowedSupplierIds->isNotEmpty(),
                fn (Builder $query) => $query->whereIn('supplier_id', $allowedSupplierIds),
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->orderBy('status')
            ->orderBy('source_name')
            ->get();

        $sources->each(function (SupplierSource $source) {
            $profileKey = $this->resolveProfileKey($source->supplier, $source->config ?? []);
            $source->setAttribute('display_source_type', $this->displaySourceType($source));
            $source->setAttribute('profile_key', $profileKey);
            $source->setAttribute('profile_prefix', $source->config['supplier_prefix']
                ?? config("prodelya_product_data_hub.supplier_profiles.{$profileKey}.supplier_code_prefix")
                ?? '-');
            $source->setAttribute('display_location', $source->url ?: ($source->config['source_file_path'] ?? '-'));
            $source->setAttribute('display_path', $source->config['product_node_path'] ?? $source->config['items_path'] ?? '-');
            $source->setAttribute('last_test_display', FeedSyncLog::query()
                ->where('supplier_source_id', $source->id)
                ->latest('completed_at')
                ->value('completed_at'));
            $source->setAttribute('last_preview_display', FeedSyncLog::query()
                ->where('supplier_source_id', $source->id)
                ->latest('created_at')
                ->value('created_at'));
        });

        // Calculate statistics
        $stats = [
            'total' => $sources->count(),
            'active' => $sources->where('status', 'active')->count(),
            'inactive' => $sources->where('status', 'inactive')->count(),
            'error' => $sources->where('status', 'error')->count(),
        ];

        return view('admin.product-data-hub.sources.index', [
            'sources' => $sources,
            'stats' => $stats,
            'hasSupplierAccess' => $allowedSupplierIds->isNotEmpty(),
        ]);
    }

    /**
     * Show the form for creating a new supplier source
     */
    public function create(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    /**
     * Store a newly created supplier source
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    /**
     * Show the form for editing the specified supplier source
     */
    public function edit(SupplierSource $source): View
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    /**
     * Update the specified supplier source
     */
    public function update(Request $request, SupplierSource $source): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    /**
     * Remove the specified supplier source (soft delete)
     */
    public function destroy(SupplierSource $source): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    /**
     * Preview supplier source data
     */
    public function preview(SupplierSource $source): View
    {
        $this->abortTenantAccess();
        abort(403, 'Global kaynak önizlemesi Super Admin tarafından yönetilir.');
    }

    public function stagePreview(Request $request, SupplierSource $source): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    /**
     * Test connection to supplier source
     */
    public function testConnection(SupplierSource $source): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global tedarikçi kaynakları Super Admin tarafından yönetilir.');
    }

    private function recordPreviewAttempt(
        SupplierSource $source,
        string $status,
        int $recordsRead,
        int $warningCount,
        int $errorCount,
        string $message
    ): void {
        FeedSyncLog::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'sync_type' => 'manual',
            'started_at' => now(),
            'completed_at' => now(),
            'status' => $status === 'success' ? 'completed' : 'failed',
            'total_records' => $recordsRead,
            'processed_records' => $recordsRead,
            'error_records' => $errorCount,
            'error_summary' => $message,
            'sync_metadata' => [
                'preview_mode' => $status,
                'warning_count' => $warningCount,
                'error_count' => $errorCount,
            ],
        ]);
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
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->pluck('supplier_id');
    }

    private function tenantCanAccessSupplier(?TenantAccount $tenant, int $supplierId): bool
    {
        return $this->allowedSupplierIds($tenant)
            ->contains($supplierId);
    }

    private function ensureSourceAccess(SupplierSource $source, ?TenantAccount $tenant): void
    {
        if (!$this->tenantCanAccessSupplier($tenant, $source->supplier_id)) {
            abort(403, 'Bu kaynağa erişim izniniz yok.');
        }
    }

    private function sourceProfiles(): array
    {
        return config('prodelya_product_data_hub.supplier_profiles', []);
    }

    private function resolveProfileKey(Supplier $supplier, array $input): string
    {
        $profileKey = $input['profile_key'] ?? $input['config']['profile_key'] ?? null;

        if (filled($profileKey)) {
            if ($profileKey === 'CUSTOM') {
                return 'CUSTOM';
            }

            return (string) $profileKey;
        }

        return config('prodelya_product_data_hub.supplier_profiles.' . $supplier->code)
            ? $supplier->code
            : Str::upper(Str::slug($supplier->code ?: $supplier->name, '-'));
    }

    private function normalizeStoredSourceType(string $sourceType): string
    {
        return $sourceType === 'json' ? 'api' : $sourceType;
    }

    private function resolveFormat(string $sourceType, ?string $format): ?string
    {
        if ($sourceType === 'json') {
            return 'json';
        }

        return $format;
    }

    private function displaySourceType(SupplierSource $source): string
    {
        if (($source->config['ui_source_type'] ?? null) === 'json' || strtolower((string) ($source->config['format'] ?? '')) === 'json') {
            return 'json';
        }

        return $source->source_type;
    }

    private function abortTenantAccess(): never
    {
        abort(403, 'Hazır tedarikçi kaynağı teknik ekranı yalnız Super Admin tarafından yönetilir.');
    }
}
