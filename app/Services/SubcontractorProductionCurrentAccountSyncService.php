<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubcontractorProductionCurrentAccountSyncService
{
    public const SOURCE_TYPE = 'order_item_print_production';

    public function __construct(
        private readonly CurrentAccountSyncService $currentAccountSyncService,
        private readonly CurrentAccountTransactionService $transactionService,
    ) {
    }

    public function syncProduction(OrderItemPrintProduction $production): ?CurrentAccountTransaction
    {
        $production->loadMissing([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]);

        $existing = $this->findExistingTransactionForProduction($production);

        if ($this->shouldCancelForProduction($production)) {
            if ($existing && !$existing->isCancelled()) {
                $this->cancelLinkedTransaction($existing, 'Fason üretim kaydı artık aktif değil.');
            }

            return $existing?->fresh();
        }

        $payload = $this->mapProductionToTransactionData($production);

        if ($payload === null) {
            if ($existing && !$existing->isCancelled()) {
                $this->cancelLinkedTransaction($existing, 'Fason maliyeti bulunmadığı için cari hareket iptal edildi.');
            }

            return null;
        }

        $account = $this->resolveSubcontractorCurrentAccount($production);

        if (!$account) {
            if ($existing && !$existing->isCancelled()) {
                $this->cancelLinkedTransaction($existing, 'Fason firma bağlantısı bulunamadığı için cari hareket iptal edildi.');
            }

            return null;
        }

        if ($existing && !$existing->isCancelled()) {
            $existing->forceFill(array_merge($payload, [
                'tenant_account_id' => $account->tenant_account_id,
                'current_account_id' => $account->id,
                'meta_json' => array_merge((array) $existing->meta_json, [
                    'created_via' => 'subcontractor_production_sync',
                    'order_number' => $production->order?->document_number,
                    'product_name' => $production->orderItem?->product_name,
                    'print_type' => $production->orderItemPrint?->print_type,
                    'print_sequence' => $production->orderItemPrint?->sequence_code,
                ]),
            ]))->save();

            return $existing->fresh();
        }

        return CurrentAccountTransaction::query()->create(array_merge($payload, [
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $production->id,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'meta_json' => [
                'created_via' => 'subcontractor_production_sync',
                'order_number' => $production->order?->document_number,
                'product_name' => $production->orderItem?->product_name,
                'print_type' => $production->orderItemPrint?->print_type,
                'print_sequence' => $production->orderItemPrint?->sequence_code,
            ],
        ]));
    }

    public function cancelForProduction(OrderItemPrintProduction $production, string $reason, ?User $user = null): void
    {
        $existing = $this->findExistingTransactionForProduction($production);

        if (!$existing || $existing->isCancelled()) {
            return;
        }

        $this->cancelLinkedTransaction($existing, $reason, $user);
    }

    public function syncTenantProductions(TenantAccount $tenant, bool $dryRun = false, ?int $productionId = null): array
    {
        $report = [
            'productions' => 0,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped_internal' => 0,
            'skipped_cost_missing' => 0,
            'skipped_company_missing' => 0,
            'errors' => 0,
        ];

        $runner = function () use ($tenant, $productionId, &$report): void {
            $productions = OrderItemPrintProduction::query()
                ->where('tenant_account_id', $tenant->id)
                ->when($productionId, fn ($query) => $query->whereKey($productionId))
                ->with([
                    'order.customer',
                    'orderItem',
                    'orderItemPrint',
                    'productionCompany.companyRoles',
                ])
                ->orderBy('id')
                ->get();

            $report['productions'] = $productions->count();

            foreach ($productions as $production) {
                try {
                    $before = $this->findExistingTransactionForProduction($production);
                    $beforeCancelled = $before?->isCancelled() ?? false;
                    $beforeExists = $before !== null;

                    if ($production->isInternal()) {
                        if ($beforeExists && !$beforeCancelled) {
                            $this->cancelLinkedTransaction($before, 'Üretim iç üretime döndüğü için cari hareket iptal edildi.');
                            $report['cancelled']++;
                        } else {
                            $report['skipped_internal']++;
                        }

                        continue;
                    }

                    if (!$this->hasPositiveCost($production)) {
                        if ($beforeExists && !$beforeCancelled) {
                            $this->cancelLinkedTransaction($before, 'Fason maliyeti bulunmadığı için cari hareket iptal edildi.');
                            $report['cancelled']++;
                        } else {
                            $report['skipped_cost_missing']++;
                        }

                        continue;
                    }

                    if (!$production->production_company_id) {
                        if ($beforeExists && !$beforeCancelled) {
                            $this->cancelLinkedTransaction($before, 'Fason firma kaydı bulunmadığı için cari hareket iptal edildi.');
                            $report['cancelled']++;
                        } else {
                            $report['skipped_company_missing']++;
                        }

                        continue;
                    }

                    $synced = $this->syncProduction($production);

                    if ($synced === null) {
                        $report['skipped_company_missing']++;
                        continue;
                    }

                    if (!$beforeExists || $beforeCancelled) {
                        $report['created']++;
                    } else {
                        $report['updated']++;
                    }
                } catch (\Throwable) {
                    $report['errors']++;
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $runner();
            } finally {
                DB::rollBack();
            }
        } else {
            $runner();
        }

        return $report;
    }

    public function findExistingTransactionForProduction(OrderItemPrintProduction $production): ?CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()
            ->where('tenant_account_id', $production->tenant_account_id)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $production->id)
            ->latest('id')
            ->first();
    }

    public function mapProductionToTransactionData(OrderItemPrintProduction $production): ?array
    {
        $amount = $production->subcontractor_cost !== null ? round((float) $production->subcontractor_cost, 2) : null;

        if ($amount === null || $amount <= 0) {
            return null;
        }

        $orderNumber = $production->order?->document_number ?: ('Sipariş #' . $production->order_id);
        $printOperation = trim((string) (($production->orderItemPrint?->sequence_code ?: '') . ' ' . ($production->orderItemPrint?->print_type ?: 'Baskı')));
        $productName = trim((string) ($production->orderItem?->product_name ?: $production->orderItem?->product_code ?: ('Ürün #' . $production->order_item_id)));
        $currency = (string) ($production->subcontractor_cost_currency ?: $production->order?->currency ?: 'TRY');
        $transactionDate = optional($production->completed_at)->toDateString()
            ?: optional($production->updated_at)->toDateString()
            ?: optional($production->created_at)->toDateString()
            ?: now()->toDateString();

        return [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => $transactionDate,
            'due_date' => null,
            'description' => sprintf('Fason üretim borcu: %s / %s / %s', $orderNumber, $printOperation, $productName),
            'created_by' => $production->updated_by ?: $production->created_by,
        ];
    }

    public function resolveSubcontractorCurrentAccount(OrderItemPrintProduction $production): ?CurrentAccount
    {
        $company = $production->productionCompany;

        if (!$company || $company->tenant_account_id !== $production->tenant_account_id) {
            return null;
        }

        $account = $this->currentAccountSyncService->ensureForCompany($company);
        $this->currentAccountSyncService->ensureRole($account, CurrentAccountRole::ROLE_SUBCONTRACTOR);

        return $account->fresh(['roles', 'links']);
    }

    private function shouldCancelForProduction(OrderItemPrintProduction $production): bool
    {
        if ((int) $production->tenant_account_id <= 0) {
            return true;
        }

        if (in_array($production->production_status, [OrderItemPrintProduction::STATUS_CANCELLED], true)) {
            return true;
        }

        return $production->isInternal();
    }

    private function hasPositiveCost(OrderItemPrintProduction $production): bool
    {
        return $production->subcontractor_cost !== null && (float) $production->subcontractor_cost > 0;
    }

    private function cancelLinkedTransaction(CurrentAccountTransaction $transaction, string $reason, ?User $user = null): void
    {
        if ($user) {
            $this->transactionService->cancelTransaction($transaction, $reason, $user);

            return;
        }

        $transaction->forceFill([
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => trim($reason),
        ])->save();
    }
}
