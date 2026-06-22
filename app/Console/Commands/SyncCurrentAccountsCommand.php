<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Console\Command;

class SyncCurrentAccountsCommand extends Command
{
    protected $signature = 'prodelya:sync-current-accounts
        {--tenant= : Sadece belirtilen tenant ID için çalıştır}
        {--dry-run : Veri yazmadan önizleme ve rapor üret}';

    protected $description = 'Company kayıtlarını hibrit current account çekirdeğine senkronize eder.';

    public function __construct(
        private readonly CurrentAccountSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $query = Company::query()
            ->with('companyRoles')
            ->orderBy('tenant_account_id')
            ->orderBy('id');

        if ($tenantId !== null && $tenantId !== '') {
            $query->where('tenant_account_id', (int) $tenantId);
        }

        $companies = $query->get();

        $createdAccounts = 0;
        $createdRoles = 0;
        $createdLinks = 0;

        foreach ($companies as $company) {
            if ($dryRun) {
                $existingLink = CurrentAccountLink::query()
                    ->where('tenant_account_id', $company->tenant_account_id)
                    ->where('link_type', CurrentAccountLink::LINK_COMPANY)
                    ->where('link_id', $company->id)
                    ->first();

                if (!$existingLink) {
                    $createdAccounts++;
                    $createdLinks++;
                }

                $mappedRoles = collect($company->getRoleKeys())
                    ->map(fn (string $role) => match ($role) {
                        'customer' => CurrentAccountRole::ROLE_CUSTOMER,
                        'supplier' => CurrentAccountRole::ROLE_SUPPLIER,
                        'print_fason' => CurrentAccountRole::ROLE_SUBCONTRACTOR,
                        'production_partner' => CurrentAccountRole::ROLE_SERVICE_PROVIDER,
                        'delivery_partner' => CurrentAccountRole::ROLE_CARRIER,
                        'other' => CurrentAccountRole::ROLE_OTHER,
                        default => null,
                    })
                    ->filter()
                    ->unique()
                    ->count();

                $createdRoles += $mappedRoles;

                continue;
            }

            $beforeAccounts = CurrentAccount::query()->count();
            $beforeLinks = CurrentAccountLink::query()->count();
            $beforeRoles = CurrentAccountRole::query()->count();

            $this->syncService->ensureForCompany($company);

            $createdAccounts += max(CurrentAccount::query()->count() - $beforeAccounts, 0);
            $createdLinks += max(CurrentAccountLink::query()->count() - $beforeLinks, 0);
            $createdRoles += max(CurrentAccountRole::query()->count() - $beforeRoles, 0);
        }

        $this->line('İşlenen company sayısı: ' . $companies->count());
        $this->line('Yeni current account: ' . $createdAccounts);
        $this->line('Yeni role: ' . $createdRoles);
        $this->line('Yeni link: ' . $createdLinks);

        if ($dryRun) {
            $this->comment('Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.');
        }

        return self::SUCCESS;
    }
}
