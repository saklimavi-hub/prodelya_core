<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use App\Services\TenantCatalog\TenantLocalProductCsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LocalProductImportController extends Controller
{
    public function __construct(
        private readonly TenantLocalProductCsvImportService $csvImportService,
    ) {
    }

    public function create(): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $preview = session('local_product_import_preview');

        return view('admin.catalog.local-products-import', compact('preview'));
    }

    public function template(): Response
    {
        return response($this->csvImportService->templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prodelya-kendi-urunlerim-sablonu.csv"',
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        session([
            'local_product_import_preview' => $this->csvImportService->preview($validated['file']),
        ]);

        return redirect()
            ->route('admin.catalog.local-products.import')
            ->with('success', 'Import önizlemesi hazırlandı. İlk satırları kontrol edin.');
    }

    public function apply(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $preview = session('local_product_import_preview');
        if (!$preview) {
            return back()->with('error', 'Import önizlemesi bulunamadı. Önce dosya yükleyin.');
        }

        $policy = $request->string('duplicate_policy')->toString() ?: 'update';
        $result = $this->csvImportService->apply($tenant, $preview, $policy, $request, $request->user());

        session()->forget('local_product_import_preview');

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', "CSV aktarımı tamamlandı. Oluşturulan: {$result['created']}, güncellenen: {$result['updated']}, atlanan: {$result['skipped']}.");
    }

    private function currentTenant(): ?TenantAccount
    {
        return request()->attributes->get('current_tenant')
            ?? auth()->user()?->tenantAccount
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->orderBy('id')->first();
    }
}
