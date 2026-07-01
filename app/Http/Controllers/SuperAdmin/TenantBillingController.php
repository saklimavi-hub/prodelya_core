<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use App\Models\TenantBillingEntry;
use App\Models\PaymentProvider;
use App\Models\TenantServiceDefinition;
use App\Models\User;
use App\Services\TenantBillingLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantBillingController extends Controller
{
    public function __construct(
        protected TenantBillingLedgerService $ledgerService
    ) {
    }

    public function index(Request $request, TenantAccount $tenant): View
    {
        $filters = $this->filters($request);
        $entries = $this->ledgerService->paginatedEntries($tenant, $filters, 20);
        $summary = $this->ledgerService->summary($tenant, $filters);

        return view('super-admin.tenants.billing.index', [
            'tenant' => $tenant->loadMissing('package'),
            'entries' => $entries,
            'summary' => $summary,
            'filters' => $filters,
            'entryTypeOptions' => $this->ledgerService->entryTypeOptions(),
            'directionOptions' => $this->ledgerService->directionOptions(),
            'serviceDefinitions' => TenantServiceDefinition::query()->where('is_active', true)->orderBy('sort_order')->orderBy('service_name')->get(),
            'recentCheckoutSessions' => $tenant->paymentCheckoutSessions()->with('provider')->latest()->limit(5)->get(),
            'sharedProviderCount' => PaymentProvider::query()->where('status', 'active')->where('supports_shared_saas_payments', true)->count(),
        ]);
    }

    public function create(Request $request, TenantAccount $tenant): View
    {
        $entryType = (string) $request->input('entry_type', 'service_fee');
        $entry = new TenantBillingEntry([
            'entry_type' => $entryType,
            'direction' => $entryType === 'collection' ? 'credit' : 'debit',
            'currency' => $tenant->package?->currency ?: 'TRY',
            'entry_date' => now()->toDateString(),
        ]);

        return view('super-admin.tenants.billing.form', [
            'tenant' => $tenant->loadMissing('package'),
            'entry' => $entry,
            'serviceDefinitions' => TenantServiceDefinition::query()->where('is_active', true)->orderBy('sort_order')->orderBy('service_name')->get(),
            'entryTypeOptions' => $this->ledgerService->entryTypeOptions(),
            'directionOptions' => $this->ledgerService->directionOptions(),
            'formAction' => route('admin.super.tenants.billing.store', $tenant),
            'formMethod' => 'POST',
            'pageTitle' => 'SaaS Cari Kaydı Ekle',
            'pageSubtitle' => 'Hizmet borcu, manuel cari hareketi veya tahsilat kaydı oluşturun.',
        ]);
    }

    public function store(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $this->validateEntry($request);
        $this->ledgerService->createEntry($tenant, $validated, $actor);

        return redirect()
            ->route('admin.super.tenants.billing.index', $tenant)
            ->with('success', 'SaaS cari hareketi oluşturuldu.');
    }

    public function edit(TenantAccount $tenant, TenantBillingEntry $entry): View
    {
        abort_unless($entry->tenant_account_id === $tenant->id, 404);

        return view('super-admin.tenants.billing.form', [
            'tenant' => $tenant->loadMissing('package'),
            'entry' => $entry,
            'serviceDefinitions' => TenantServiceDefinition::query()->where('is_active', true)->orderBy('sort_order')->orderBy('service_name')->get(),
            'entryTypeOptions' => $this->ledgerService->entryTypeOptions(),
            'directionOptions' => $this->ledgerService->directionOptions(),
            'formAction' => route('admin.super.tenants.billing.update', [$tenant, $entry]),
            'formMethod' => 'PUT',
            'pageTitle' => 'SaaS Cari Kaydını Düzenle',
            'pageSubtitle' => 'Tenant için oluşturulmuş cari hareket detaylarını güncelleyin.',
        ]);
    }

    public function update(Request $request, TenantAccount $tenant, TenantBillingEntry $entry): RedirectResponse
    {
        abort_unless($entry->tenant_account_id === $tenant->id, 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $this->validateEntry($request);
        $this->ledgerService->updateEntry($entry, $validated, $actor);

        return redirect()
            ->route('admin.super.tenants.billing.index', $tenant)
            ->with('success', 'SaaS cari hareketi güncellendi.');
    }

    public function destroy(TenantAccount $tenant, TenantBillingEntry $entry): RedirectResponse
    {
        abort_unless($entry->tenant_account_id === $tenant->id, 404);

        $entry->delete();

        return redirect()
            ->route('admin.super.tenants.billing.index', $tenant)
            ->with('success', 'SaaS cari hareketi silindi.');
    }

    public function chargePackageFee(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $this->ledgerService->chargePackageFee($tenant, $actor);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.super.tenants.billing.index', $tenant)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.super.tenants.billing.index', $tenant)
            ->with('success', 'Paket bedeli tenant cari hesabına borç olarak eklendi.');
    }

    public function exportCsv(Request $request, TenantAccount $tenant)
    {
        $filters = $this->filters($request);
        $entries = $this->ledgerService->entries($tenant, $filters);

        return response()->streamDownload(function () use ($entries): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Tarih', 'Hareket Tipi', 'Baslik', 'Hizmet', 'Referans', 'Yön', 'Borç', 'Alacak', 'Olusturan']);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    optional($entry->entry_date)->format('d.m.Y'),
                    $entry->typeLabel(),
                    $entry->title,
                    $entry->serviceDefinition?->service_name ?: '-',
                    $entry->reference_no ?: '-',
                    $entry->directionLabel(),
                    $entry->direction === 'debit' ? MoneyFormatter::formatAmount((float) $entry->amount) : '',
                    $entry->direction === 'credit' ? MoneyFormatter::formatAmount((float) $entry->amount) : '',
                    $entry->creator?->name ?: '-',
                ]);
            }

            fclose($handle);
        }, 'tenant-saas-cari-ekstresi.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request, TenantAccount $tenant)
    {
        $filters = $this->filters($request);
        $entries = $this->ledgerService->entries($tenant, $filters);
        $summary = $this->ledgerService->summary($tenant, $filters);

        return Pdf::loadView('super-admin.tenants.billing.statement-pdf', [
            'tenant' => $tenant,
            'entries' => $entries,
            'summary' => $summary,
        ])->download('tenant-saas-cari-ekstresi.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'tenant_service_definition_id' => ['nullable', 'integer', 'exists:tenant_service_definitions,id'],
            'package_key' => ['nullable', 'string', 'max:100'],
            'entry_type' => ['required', Rule::in(array_keys($this->ledgerService->entryTypeOptions()))],
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'direction' => ['required', Rule::in(array_keys($this->ledgerService->directionOptions()))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'entry_date' => ['required', 'date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'date_from' => trim((string) $request->input('date_from')),
            'date_to' => trim((string) $request->input('date_to')),
            'entry_type' => trim((string) $request->input('entry_type')),
            'direction' => trim((string) $request->input('direction')),
            'tenant_service_definition_id' => trim((string) $request->input('tenant_service_definition_id')),
        ];
    }
}
