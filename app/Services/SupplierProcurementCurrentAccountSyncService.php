<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierProcurementCurrentAccountSyncService
{
    public const SOURCE_TYPE = 'supplier_procurement_request_item';

    public function __construct(
        private readonly CurrentAccountSyncService $currentAccountSyncService,
        private readonly CurrentAccountTransactionService $transactionService,
    ) {
    }

    public function syncRequestItem(SupplierProcurementRequestItem $item): ?CurrentAccountTransaction
    {
        $item->loadMissing([
            'request.supplier',
            'request.supplier.tenants',
            'procurement',
            'order',
        ]);

        $existing = $this->findExistingTransactionForItem($item);

        if ($this->shouldCancelForItem($item)) {
            if ($existing && !$existing->isCancelled()) {
                $this->cancelLinkedTransaction($existing, 'Tedarik talep kalemi artık aktif değil.');
            }

            return $existing?->fresh();
        }

        $payload = $this->mapItemToTransactionData($item);

        if ($payload === null) {
            if ($existing && !$existing->isCancelled()) {
                $this->cancelLinkedTransaction($existing, 'Tedarik talep kaleminde alış tutarı bulunmadığı için iptal edildi.');
            }

            return null;
        }

        $account = $this->resolveSupplierCurrentAccount($item);

        if ($existing && !$existing->isCancelled()) {
            $existing->forceFill(array_merge($payload, [
                'tenant_account_id' => $account->tenant_account_id,
                'current_account_id' => $account->id,
            ]))->save();

            return $existing->fresh();
        }

        return CurrentAccountTransaction::query()->create(array_merge($payload, [
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $item->id,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'meta_json' => [
                'created_via' => 'supplier_procurement_request_item_sync',
                'request_number' => $item->request?->request_number,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
            ],
        ]));
    }

    public function cancelForRequestItem(SupplierProcurementRequestItem $item, string $reason, ?User $user = null): void
    {
        $existing = $this->findExistingTransactionForItem($item);

        if (!$existing || $existing->isCancelled()) {
            return;
        }

        $this->cancelLinkedTransaction($existing, $reason, $user);
    }

    public function syncRequest(SupplierProcurementRequest $request): array
    {
        $request->loadMissing(['items.request.supplier', 'items.procurement', 'supplier']);

        $report = [
            'items' => 0,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($request->items as $item) {
            $report['items']++;

            try {
                $before = $this->findExistingTransactionForItem($item);
                $beforeCancelled = $before?->isCancelled() ?? false;
                $beforeExists = $before !== null;

                $synced = $this->syncRequestItem($item);

                if ($synced === null) {
                    $report['skipped']++;
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

        return $report;
    }

    public function syncTenantProcurements(TenantAccount $tenant, bool $dryRun = false, ?int $requestId = null, ?int $itemId = null): array
    {
        $report = [
            'requests' => 0,
            'items' => 0,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $runner = function () use ($tenant, $requestId, $itemId, &$report): void {
            $items = SupplierProcurementRequestItem::query()
                ->where('tenant_account_id', $tenant->id)
                ->when($requestId, fn ($query) => $query->where('supplier_procurement_request_id', $requestId))
                ->when($itemId, fn ($query) => $query->whereKey($itemId))
                ->with(['request.supplier.tenants', 'procurement', 'order'])
                ->orderBy('supplier_procurement_request_id')
                ->orderBy('id')
                ->get();

            $report['items'] = $items->count();
            $report['requests'] = $items->pluck('supplier_procurement_request_id')->filter()->unique()->count();

            foreach ($items as $item) {
                try {
                    $before = $this->findExistingTransactionForItem($item);
                    $beforeCancelled = $before?->isCancelled() ?? false;
                    $beforeExists = $before !== null;
                    $eligible = $this->isTransactionEligible($item);

                    $synced = $this->syncRequestItem($item);

                    if (!$eligible || $synced === null) {
                        if ($beforeExists && !$beforeCancelled) {
                            $report['cancelled']++;
                        } else {
                            $report['skipped']++;
                        }

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

    public function findExistingTransactionForItem(SupplierProcurementRequestItem $item): ?CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()
            ->where('tenant_account_id', $item->tenant_account_id)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->latest('id')
            ->first();
    }

    public function mapItemToTransactionData(SupplierProcurementRequestItem $item): ?array
    {
        $amount = $item->purchase_total !== null ? round((float) $item->purchase_total, 2) : null;

        if ($amount === null || $amount <= 0) {
            return null;
        }

        $request = $item->request;
        $order = $item->order;
        $currency = (string) ($order?->currency ?: $request?->tenant?->default_currency ?: 'TRY');
        $transactionDate = optional($request?->request_date)?->toDateString() ?: optional($item->created_at)?->toDateString() ?: now()->toDateString();
        $requestNumber = $request?->request_number ?: ('Talep #' . $item->supplier_procurement_request_id);
        $productName = trim((string) ($item->product_name ?: $item->product_code ?: ('Kalem #' . $item->id)));

        return [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => $transactionDate,
            'due_date' => null,
            'description' => sprintf('Tedarik alış borcu: %s / %s', $requestNumber, $productName),
            'created_by' => $item->updated_by ?: $item->created_by,
        ];
    }

    public function resolveSupplierCurrentAccount(SupplierProcurementRequest|SupplierProcurementRequestItem $requestOrItem): CurrentAccount
    {
        $request = $requestOrItem instanceof SupplierProcurementRequestItem ? $requestOrItem->request : $requestOrItem;
        $supplier = $request?->supplier;
        $tenantId = (int) ($request?->tenant_account_id ?? 0);

        abort_unless($supplier && $tenantId > 0, 403);

        $tenant = $request->tenant ?: TenantAccount::query()->findOrFail($tenantId);

        $existingLink = CurrentAccount::query()
            ->where('tenant_account_id', $tenantId)
            ->whereHas('links', function ($query) use ($supplier) {
                $query->where('link_type', \App\Models\CurrentAccountLink::LINK_SUPPLIER)
                    ->where('link_id', $supplier->id);
            })
            ->first();

        if ($existingLink) {
            $this->currentAccountSyncService->ensureRole($existingLink, \App\Models\CurrentAccountRole::ROLE_SUPPLIER);

            return $existingLink->fresh(['roles', 'links']);
        }

        return $this->currentAccountSyncService->ensureForSupplier($supplier, $tenant);
    }

    private function shouldCancelForItem(SupplierProcurementRequestItem $item): bool
    {
        $request = $item->request;

        return !$request
            || $request->tenant_account_id !== $item->tenant_account_id
            || $request->isCancelled();
    }

    private function isTransactionEligible(SupplierProcurementRequestItem $item): bool
    {
        if ($this->shouldCancelForItem($item)) {
            return false;
        }

        return $item->purchase_total !== null && (float) $item->purchase_total > 0;
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
