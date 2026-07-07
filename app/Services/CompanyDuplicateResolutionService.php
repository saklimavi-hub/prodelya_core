<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Supplier;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyDuplicateResolutionService
{
    public function __construct(
        protected TenantSupplierCurrentAccountSyncService $tenantSupplierCurrentAccountSyncService,
    ) {}

    public function auditCompanyDuplicateStatus(Company $company): ?array
    {
        $company->loadMissing(['companyRoles', 'contacts', 'addresses', 'portalUsers']);

        if (! $company->hasRole('supplier')) {
            return null;
        }

        $currentAccount = $this->resolveLinkedCurrentAccount($company);
        $supplier = $this->resolveSupplierForCompany($company, $currentAccount);

        if (! $supplier) {
            return [
                'has_mapping' => false,
                'has_similar_companies' => false,
                'has_duplicate_candidates' => false,
                'status_label' => 'Hazır ürün kaynağı eşleşmesi bulunmuyor',
                'status_tone' => 'amber',
                'can_archive' => false,
                'is_archived' => false,
                'archive_button_visible' => false,
                'archive_blocked_message' => 'Bu kayıt otomatik arşivlenemez. Önce hazır ürün kaynağı eşleşmesi tamamlanmalıdır.',
                'blocking_reasons' => ['Hazır ürün kaynağı eşleşmesi bulunmuyor.'],
                'checklist' => [],
                'main_company' => null,
                'current_company' => [
                    'is_main_company' => false,
                    'is_similar_company' => false,
                    'is_archive_candidate' => false,
                ],
                'similar_companies' => [],
                'warnings' => [],
            ];
        }

        $audit = $this->tenantSupplierCurrentAccountSyncService->auditDuplicateSupplierCaris($company->tenant, $supplier);
        $currentCompanyAudit = collect($audit['companies'] ?? [])
            ->firstWhere('company.id', $company->id);
        $mainCompanyAudit = data_get($audit, 'canonical_candidate');
        $duplicateCandidates = collect($audit['duplicate_candidates'] ?? []);
        $isMainCompany = (int) data_get($mainCompanyAudit, 'company.id') === $company->id;
        $isSimilarCompany = $duplicateCandidates->contains(fn (array $candidate): bool => (int) data_get($candidate, 'company.id') === $company->id);

        $customerOrderCount = Order::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('customer_company_id', $company->id)
            ->where('document_type', 'order')
            ->count();

        $quoteCount = Order::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('customer_company_id', $company->id)
            ->where('document_type', 'quote')
            ->count();

        $paymentCount = OrderPayment::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('customer_company_id', $company->id)
            ->count();

        $transactionCount = (int) ($currentCompanyAudit['transaction_count'] ?? 0);
        $openTransactionCount = (int) ($currentCompanyAudit['open_transaction_count'] ?? 0);
        $linkedOrderCount = max($customerOrderCount, (int) ($currentCompanyAudit['linked_orders_count'] ?? 0));
        $linkedQuoteCount = $quoteCount;
        $linkedProcurementCount = (int) ($currentCompanyAudit['linked_procurements_count'] ?? 0);
        $linkedProductionCount = (int) ($currentCompanyAudit['linked_productions_count'] ?? 0);
        $linkedPaymentCount = max($paymentCount, (int) ($currentCompanyAudit['linked_order_payments_count'] ?? 0));
        $portalUsersCount = (int) ($currentCompanyAudit['portal_users_count'] ?? $company->portalUsers->count());
        $contactsCount = (int) ($currentCompanyAudit['contacts_count'] ?? $company->contacts->count());
        $addressesCount = (int) ($currentCompanyAudit['addresses_count'] ?? $company->addresses->count());
        $supplierLinkCount = (int) ($currentCompanyAudit['linked_supplier_count'] ?? 0);
        $supplierAccessLinkCount = (int) ($currentCompanyAudit['linked_supplier_access_count'] ?? 0);
        $balanceAmount = (float) data_get($currentCompanyAudit, 'balance_summary.balance_amount', 0);

        $checklist = [
            $this->checklistItem(
                'Finans hareketi',
                $transactionCount > 0,
                $transactionCount > 0 ? 'Cari hareket kaydı var.' : 'Cari hareket kaydı bulunmuyor.'
            ),
            $this->checklistItem(
                'Açık hareket',
                $openTransactionCount > 0,
                $openTransactionCount > 0 ? 'Açık hareket bulundu.' : 'Açık hareket yok.'
            ),
            $this->checklistItem(
                'Güncel bakiye',
                abs($balanceAmount) > 0.0001,
                abs($balanceAmount) > 0.0001 ? 'Cari bakiyesi sıfır değil.' : 'Cari bakiyesi sıfır.'
            ),
            $this->checklistItem(
                'Sipariş bağlantısı',
                $linkedOrderCount > 0,
                $linkedOrderCount > 0 ? 'Sipariş bağlantısı var.' : 'Sipariş bağlantısı yok.'
            ),
            $this->checklistItem(
                'Teklif bağlantısı',
                $linkedQuoteCount > 0,
                $linkedQuoteCount > 0 ? 'Teklif bağlantısı var.' : 'Teklif bağlantısı yok.'
            ),
            $this->checklistItem(
                'Tedarik bağlantısı',
                $linkedProcurementCount > 0,
                $linkedProcurementCount > 0 ? 'Tedarik bağlantısı var.' : 'Tedarik bağlantısı yok.'
            ),
            $this->checklistItem(
                'Fason / üretim bağlantısı',
                $linkedProductionCount > 0,
                $linkedProductionCount > 0 ? 'Fason veya üretim bağlantısı var.' : 'Fason veya üretim bağlantısı yok.'
            ),
            $this->checklistItem(
                'Tahsilat / ödeme',
                $linkedPaymentCount > 0,
                $linkedPaymentCount > 0 ? 'Tahsilat veya ödeme bağlantısı var.' : 'Tahsilat veya ödeme bağlantısı yok.'
            ),
            $this->checklistItem(
                'Portal kullanıcıları',
                $portalUsersCount > 0,
                $portalUsersCount > 0 ? 'Portal kullanıcısı var.' : 'Portal kullanıcısı yok.'
            ),
            $this->checklistItem(
                'Yetkili kayıtları',
                $contactsCount > 0,
                $contactsCount > 0 ? 'Yetkili kaydı var.' : 'Yetkili kaydı yok.'
            ),
            $this->checklistItem(
                'Adres kayıtları',
                $addressesCount > 0,
                $addressesCount > 0 ? 'Adres kaydı var.' : 'Adres kaydı yok.'
            ),
            $this->checklistItem(
                'Tedarikçi eşleşmesi',
                ($supplierLinkCount + $supplierAccessLinkCount) > 0,
                ($supplierLinkCount + $supplierAccessLinkCount) > 0 ? 'Aktif tedarikçi eşleşmesi bu kayıt üzerinde duruyor.' : 'Aktif tedarikçi eşleşmesi bu kayıt üzerinde görünmüyor.'
            ),
        ];

        $blockingReasons = collect($checklist)
            ->filter(fn (array $item): bool => ! $item['is_clear'])
            ->map(fn (array $item): string => $item['detail'])
            ->values()
            ->all();

        $isArchived = $company->status !== 'active';
        $canArchive = ! $isArchived
            && $isSimilarCompany
            && ! $isMainCompany
            && count($blockingReasons) === 0;

        $statusLabel = $isArchived
            ? 'Arşivlendi'
            : ($canArchive
                ? 'Arşivlemeye uygun'
                : ($isSimilarCompany ? 'Manuel inceleme gerekir' : ($isMainCompany ? 'Ana Cari Kart' : 'Güvenli kayıt')));

        $statusTone = $isArchived
            ? 'gray'
            : ($canArchive ? 'amber' : ($isMainCompany ? 'blue' : ($isSimilarCompany ? 'amber' : 'green')));

        return [
            'has_mapping' => true,
            'has_similar_companies' => $duplicateCandidates->isNotEmpty(),
            'has_duplicate_candidates' => $duplicateCandidates->isNotEmpty(),
            'status_label' => $statusLabel,
            'status_tone' => $statusTone,
            'can_archive' => $canArchive,
            'is_archived' => $isArchived,
            'archive_button_visible' => $canArchive,
            'archive_blocked_message' => $canArchive
                ? null
                : ($isArchived
                    ? 'Bu benzer cari daha önce arşivlendi.'
                    : 'Bu kayıt otomatik arşivlenemez. Manuel inceleme gerekir.'),
            'blocking_reasons' => $blockingReasons,
            'checklist' => $checklist,
            'main_company' => $mainCompanyAudit ? [
                'id' => (int) data_get($mainCompanyAudit, 'company.id'),
                'name' => trim((string) (data_get($mainCompanyAudit, 'company.short_name') ?: data_get($mainCompanyAudit, 'company.legal_name') ?: ('Cari #' . data_get($mainCompanyAudit, 'company.id')))),
            ] : null,
            'current_company' => [
                'is_main_company' => $isMainCompany,
                'is_similar_company' => $isSimilarCompany,
                'is_archive_candidate' => $canArchive,
                'has_financial_history' => $transactionCount > 0,
                'has_operational_links' => ($linkedOrderCount + $linkedProcurementCount + $linkedProductionCount + $linkedPaymentCount + $linkedQuoteCount) > 0,
                'transaction_count' => $transactionCount,
                'linked_orders_count' => $linkedOrderCount,
                'linked_quotes_count' => $linkedQuoteCount,
                'linked_procurements_count' => $linkedProcurementCount,
                'linked_productions_count' => $linkedProductionCount,
                'linked_order_payments_count' => $linkedPaymentCount,
                'selection_reasons' => (array) ($currentCompanyAudit['selection_reasons'] ?? []),
                'repair_warnings' => (array) ($currentCompanyAudit['repair_warnings'] ?? []),
            ],
            'similar_companies' => $duplicateCandidates
                ->reject(fn (array $candidate): bool => (int) data_get($candidate, 'company.id') === $company->id)
                ->map(fn (array $candidate): array => [
                    'id' => (int) data_get($candidate, 'company.id'),
                    'name' => trim((string) (data_get($candidate, 'company.short_name') ?: data_get($candidate, 'company.legal_name') ?: ('Cari #' . data_get($candidate, 'company.id')))),
                    'is_archive_candidate' => (bool) ($candidate['is_safe_link_repair_candidate'] ?? false),
                    'is_archived' => (string) data_get($candidate, 'company.status') !== 'active',
                ])
                ->values()
                ->all(),
            'warnings' => (array) ($audit['warnings'] ?? []),
        ];
    }

    public function canArchiveDuplicate(Company $company): bool
    {
        return (bool) data_get($this->auditCompanyDuplicateStatus($company), 'can_archive', false);
    }

    public function archiveEmptyDuplicate(Company $company, ?User $actor = null): array
    {
        $summary = $this->auditCompanyDuplicateStatus($company);

        if (! $summary || ! ($summary['can_archive'] ?? false)) {
            throw new \RuntimeException('Bu cari otomatik arşivlenemez.');
        }

        DB::transaction(function () use ($company): void {
            $company->forceFill(['status' => 'inactive'])->save();

            $currentAccount = $this->resolveLinkedCurrentAccount($company);
            if ($currentAccount) {
                $currentAccount->forceFill(['status' => CurrentAccount::STATUS_ARCHIVED])->save();
            }
        });

        return $this->auditCompanyDuplicateStatus($company->fresh(['companyRoles', 'contacts', 'addresses', 'portalUsers']));
    }

    private function checklistItem(string $label, bool $hasBlocker, string $detail): array
    {
        return [
            'label' => $label,
            'status_label' => $hasBlocker ? 'Var' : 'Yok',
            'is_clear' => ! $hasBlocker,
            'detail' => $detail,
        ];
    }

    private function resolveLinkedCurrentAccount(Company $company): ?CurrentAccount
    {
        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->where('is_primary', true)
            ->first();

        if (! $companyLink?->current_account_id) {
            return null;
        }

        return CurrentAccount::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->with('roles')
            ->find($companyLink->current_account_id);
    }

    private function resolveSupplierForCompany(Company $company, ?CurrentAccount $currentAccount): ?Supplier
    {
        $supplierId = $this->resolveMappedSupplierId($company->tenant_account_id, $currentAccount?->id);

        if ($supplierId) {
            return Supplier::query()->find($supplierId);
        }

        $normalizedCandidates = collect([$company->legal_name, $company->short_name])
            ->filter(fn ($value): bool => filled($value))
            ->map(fn ($value): string => Str::lower(Str::squish((string) $value)))
            ->unique()
            ->values();

        if ($normalizedCandidates->isEmpty()) {
            return null;
        }

        return TenantSupplierAccess::query()
            ->with('supplier')
            ->where('tenant_account_id', $company->tenant_account_id)
            ->active()
            ->get()
            ->map->supplier
            ->filter()
            ->first(function (Supplier $supplier) use ($normalizedCandidates): bool {
                $normalizedName = Str::lower(Str::squish((string) $supplier->name));

                return $normalizedCandidates->contains($normalizedName);
            });
    }

    private function resolveMappedSupplierId(int $tenantId, ?int $currentAccountId): ?int
    {
        if (! $currentAccountId) {
            return null;
        }

        $supplierId = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccountId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->value('link_id');

        if ($supplierId) {
            return (int) $supplierId;
        }

        $tenantSupplierAccessId = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccountId)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->value('link_id');

        if (! $tenantSupplierAccessId) {
            return null;
        }

        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->whereKey($tenantSupplierAccessId)
            ->value('supplier_id');
    }
}
