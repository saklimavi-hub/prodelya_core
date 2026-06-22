<?php

namespace App\Services;

use App\Models\OrderItemProcurement;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierProcurementRequestService
{
    public function __construct(
        protected NumberGenerationService $numberGenerationService,
        protected ProcurementWorkflowService $procurementWorkflowService,
        protected SupplierProcurementRequestDataBuilder $dataBuilder,
        protected SupplierProcurementCurrentAccountSyncService $currentAccountSyncService,
        protected NotificationEventService $notificationEventService,
    ) {
    }

    public function createDraftForSupplier(TenantAccount $tenant, int $supplierId, array $procurementIds, ?User $user = null): SupplierProcurementRequest
    {
        $procurements = $this->validatedProcurementsForSupplier($tenant, $supplierId, $procurementIds);

        return DB::transaction(function () use ($tenant, $supplierId, $procurements, $user): SupplierProcurementRequest {
            $request = SupplierProcurementRequest::query()->create([
                'tenant_account_id' => $tenant->id,
                'supplier_id' => $supplierId,
                'request_number' => $this->generateRequestNumber($tenant),
                'request_date' => now()->toDateString(),
                'status' => SupplierProcurementRequest::STATUS_DRAFT,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            foreach ($procurements as $procurement) {
                $requestItem = new SupplierProcurementRequestItem([
                    'tenant_account_id' => $tenant->id,
                    'supplier_procurement_request_id' => $request->id,
                    'order_item_procurement_id' => $procurement->id,
                    'order_id' => $procurement->order_id,
                    'order_item_id' => $procurement->order_item_id,
                    'work_form_id' => $procurement->work_form_id,
                    'supplier_source_id' => $procurement->supplier_source_id,
                    'product_code' => data_get($procurement->snapshot, 'product_code'),
                    'product_name' => (string) data_get($procurement->snapshot, 'product_name', $procurement->orderItem?->product_name),
                    'requested_quantity' => round((float) $procurement->remaining_quantity, 2),
                    'unit' => (string) data_get($procurement->snapshot, 'unit', $procurement->orderItem?->unit ?: 'Adet'),
                    'received_quantity' => 0,
                    'remaining_quantity' => round((float) $procurement->remaining_quantity, 2),
                    'purchase_list_price' => $this->dataBuilder->suggestPurchaseListPrice($procurement),
                    'discount_rate' => 0,
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);

                $requestItem->recalculatePurchaseTotals();
                $requestItem->save();
            }

            $request = $request->fresh(['tenant', 'supplier', 'items.procurement.order.customer.contacts', 'items.procurement.orderItem', 'items.procurement.workForm', 'items.request.supplier', 'creator', 'updater']);
            $this->currentAccountSyncService->syncRequest($request);
            $this->dispatchSafely($request, 'procurement_request_created', [
                'audience_type' => 'procurement_team',
                'channels' => ['internal', 'email'],
                'created_by' => $user,
                'context' => [
                    'status_label' => $request->safeStatusLabel(),
                ],
            ]);

            return $request;
        });
    }

    public function updateRequestItems(SupplierProcurementRequest $request, array $itemsData, ?User $user = null): SupplierProcurementRequest
    {
        return DB::transaction(function () use ($request, $itemsData, $user): SupplierProcurementRequest {
            $request->loadMissing('items');
            $itemsById = $request->items->keyBy('id');
            $includedItemIds = collect($itemsData)
                ->filter(fn ($row) => !array_key_exists('included', $row) || (bool) $row['included'])
                ->map(fn ($row) => (int) ($row['id'] ?? 0))
                ->filter(fn ($id) => $id > 0)
                ->values();

            foreach ($itemsData as $row) {
                $itemId = (int) ($row['id'] ?? 0);
                /** @var SupplierProcurementRequestItem|null $item */
                $item = $itemsById->get($itemId);

                if (!$item) {
                    throw new InvalidArgumentException('Talep kalemi bulunamadı.');
                }

                if (array_key_exists('included', $row) && !(bool) $row['included']) {
                    $this->currentAccountSyncService->cancelForRequestItem($item, 'Talep kalemi talepten çıkarıldı.', $user);
                    $item->delete();

                    continue;
                }

                $item->fill([
                    'requested_quantity' => array_key_exists('requested_quantity', $row)
                        ? round((float) $row['requested_quantity'], 2)
                        : $item->requested_quantity,
                    'purchase_list_price' => array_key_exists('purchase_list_price', $row) && $row['purchase_list_price'] !== null && $row['purchase_list_price'] !== ''
                        ? round((float) $row['purchase_list_price'], 2)
                        : null,
                    'discount_rate' => array_key_exists('discount_rate', $row) && $row['discount_rate'] !== null && $row['discount_rate'] !== ''
                        ? round((float) $row['discount_rate'], 2)
                        : 0.0,
                    'note' => $row['note'] ?? $item->note,
                    'updated_by' => $user?->id,
                ]);

                $manualUnitPrice = array_key_exists('purchase_unit_price', $row) && $row['purchase_unit_price'] !== null && $row['purchase_unit_price'] !== ''
                    ? round((float) $row['purchase_unit_price'], 2)
                    : null;

                $item->recalculatePurchaseTotals($manualUnitPrice)
                    ->save();
            }

            $removedItems = $itemsById
                ->filter(fn (SupplierProcurementRequestItem $item) => !$includedItemIds->contains($item->id))
                ->values();

            foreach ($removedItems as $removedItem) {
                $this->currentAccountSyncService->cancelForRequestItem($removedItem, 'Talep kalemi talepten çıkarıldı.', $user);
                $removedItem->delete();
            }

            $request->forceFill(['updated_by' => $user?->id])->save();
            $this->recalculateHeaderStatus($request->fresh('items'));
            $request = $request->fresh(['supplier', 'items.procurement', 'items.request.supplier']);
            $this->currentAccountSyncService->syncRequest($request);

            return $request;
        });
    }

    public function markRequested(SupplierProcurementRequest $request, ?User $user = null): SupplierProcurementRequest
    {
        return DB::transaction(function () use ($request, $user): SupplierProcurementRequest {
            $request->loadMissing('items.procurement.workForm');

            foreach ($request->items as $item) {
                if ($item->procurement) {
                    $this->procurementWorkflowService->markRequestCreated($item->procurement->fresh(['workForm']), $user);
                }
            }

            $request->forceFill([
                'status' => SupplierProcurementRequest::STATUS_REQUESTED,
                'updated_by' => $user?->id,
            ])->save();

            $this->syncItemsFromProcurements($request->fresh('items.procurement.orderItem'));
            $this->recalculateHeaderStatus($request->fresh('items.procurement'));

            $request = $request->fresh(['items.procurement.workForm', 'items.request.supplier']);
            $this->currentAccountSyncService->syncRequest($request);

            return $request;
        });
    }

    public function markSupplierOrdered(SupplierProcurementRequest $request, ?User $user = null): SupplierProcurementRequest
    {
        return DB::transaction(function () use ($request, $user): SupplierProcurementRequest {
            $request->loadMissing('items.procurement.workForm');

            foreach ($request->items as $item) {
                if ($item->procurement) {
                    $this->procurementWorkflowService->markSupplierOrdered($item->procurement->fresh(['workForm']), $user);
                }
            }

            $request->forceFill([
                'status' => SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED,
                'updated_by' => $user?->id,
            ])->save();

            $this->syncItemsFromProcurements($request->fresh('items.procurement.orderItem'));
            $this->recalculateHeaderStatus($request->fresh('items.procurement'));

            $request = $request->fresh(['items.procurement.workForm', 'items.request.supplier']);
            $this->currentAccountSyncService->syncRequest($request);

            return $request;
        });
    }

    public function markPartiallyReceived(SupplierProcurementRequest $request, array $receivedItems, ?User $user = null): SupplierProcurementRequest
    {
        return DB::transaction(function () use ($request, $receivedItems, $user): SupplierProcurementRequest {
            $request->loadMissing('items.procurement.workForm');
            $itemsById = $request->items->keyBy('id');
            $processedAny = false;

            foreach ($receivedItems as $itemId => $receivedQuantity) {
                $item = $itemsById->get((int) $itemId);

                if (!$item || !$item->procurement) {
                    continue;
                }

                $delta = round((float) $receivedQuantity, 2);

                if ($delta <= 0) {
                    continue;
                }

                $this->procurementWorkflowService->markPartiallyReceived(
                    $item->procurement->fresh(['workForm']),
                    $delta,
                    $user
                );

                $processedAny = true;
            }

            if (!$processedAny) {
                throw new InvalidArgumentException('Kısmi geldi işlemi için en az bir kalemde gelen miktar girilmelidir.');
            }

            $request->forceFill([
                'status' => SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED,
                'updated_by' => $user?->id,
            ])->save();

            $this->syncItemsFromProcurements($request->fresh('items.procurement.orderItem'));
            $this->recalculateHeaderStatus($request->fresh('items.procurement'));

            $request = $request->fresh(['items.procurement.workForm', 'items.request.supplier']);
            $this->currentAccountSyncService->syncRequest($request);

            return $request;
        });
    }

    public function markCompleted(SupplierProcurementRequest $request, ?User $user = null): SupplierProcurementRequest
    {
        return DB::transaction(function () use ($request, $user): SupplierProcurementRequest {
            $request->loadMissing('items.procurement.workForm');

            foreach ($request->items as $item) {
                if (!$item->procurement) {
                    continue;
                }

                if ((float) $item->procurement->remaining_quantity > 0.0001) {
                    $this->procurementWorkflowService->markFullyReceived(
                        $item->procurement->fresh(['workForm']),
                        $user
                    );
                }
            }

            $request->forceFill([
                'status' => SupplierProcurementRequest::STATUS_COMPLETED,
                'updated_by' => $user?->id,
            ])->save();

            $this->syncItemsFromProcurements($request->fresh('items.procurement.orderItem'));
            $this->recalculateHeaderStatus($request->fresh('items.procurement'));

            $request = $request->fresh(['items.procurement.workForm', 'items.request.supplier']);
            $this->currentAccountSyncService->syncRequest($request);

            return $request;
        });
    }

    public function cancelRequest(SupplierProcurementRequest $request, ?User $user = null): SupplierProcurementRequest
    {
        return DB::transaction(function () use ($request, $user): SupplierProcurementRequest {
            $request->loadMissing('items.procurement.workForm');

            foreach ($request->items as $item) {
                if ((float) $item->received_quantity > 0 || (float) ($item->procurement?->received_quantity ?? 0) > 0) {
                    throw new InvalidArgumentException('Teslim alınmış talep iptal edilemez.');
                }
            }

            $request->forceFill([
                'status' => SupplierProcurementRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'updated_by' => $user?->id,
            ])->save();

            $request = $request->fresh(['items.procurement.workForm', 'items.request.supplier']);

            foreach ($request->items as $item) {
                $this->currentAccountSyncService->cancelForRequestItem($item, 'Tedarikçi talebi iptal edildi.', $user);
            }

            return $request;
        });
    }

    public function syncItemsFromProcurements(SupplierProcurementRequest $request): void
    {
        $request->loadMissing('items.procurement.orderItem');

        foreach ($request->items as $item) {
            $procurement = $item->procurement;

            if (!$procurement) {
                continue;
            }

            $item->forceFill([
                'order_id' => $procurement->order_id,
                'order_item_id' => $procurement->order_item_id,
                'work_form_id' => $procurement->work_form_id,
                'supplier_source_id' => $procurement->supplier_source_id,
                'product_code' => data_get($procurement->snapshot, 'product_code', $item->product_code),
                'product_name' => data_get($procurement->snapshot, 'product_name', $item->product_name),
                'unit' => data_get($procurement->snapshot, 'unit', $item->unit),
                'requested_quantity' => round((float) $procurement->requested_quantity, 2),
                'received_quantity' => round((float) $procurement->received_quantity, 2),
                'remaining_quantity' => round((float) $procurement->remaining_quantity, 2),
                'updated_at' => now(),
            ]);

            if ($item->purchase_unit_price !== null) {
                $item->purchase_total = round((float) $item->purchase_unit_price * (float) $item->requested_quantity, 2);
                $item->save();
            } else {
                $item->recalculatePurchaseTotals()
                    ->save();
            }
        }
    }

    public function recalculateHeaderStatus(SupplierProcurementRequest $request): void
    {
        $request->loadMissing('items.procurement');

        if ($request->isCancelled()) {
            return;
        }

        $items = $request->items;

        if ($items->isEmpty()) {
            $request->forceFill(['status' => SupplierProcurementRequest::STATUS_DRAFT])->save();

            return;
        }

        $allComplete = $items->every(fn (SupplierProcurementRequestItem $item) => (float) $item->remaining_quantity <= 0.0001);
        $anyReceived = $items->contains(fn (SupplierProcurementRequestItem $item) => (float) $item->received_quantity > 0.0001);
        $anyRemaining = $items->contains(fn (SupplierProcurementRequestItem $item) => (float) $item->remaining_quantity > 0.0001);
        $anySupplierOrdered = $items->contains(
            fn (SupplierProcurementRequestItem $item) => $item->procurement?->procurement_status === OrderItemProcurement::STATUS_SUPPLIER_ORDERED
        );
        $anyRequestCreated = $items->contains(
            fn (SupplierProcurementRequestItem $item) => $item->procurement?->procurement_status === OrderItemProcurement::STATUS_REQUEST_CREATED
        );

        $status = $request->status;

        if ($allComplete) {
            $status = SupplierProcurementRequest::STATUS_COMPLETED;
        } elseif ($anyReceived && $anyRemaining) {
            $status = SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED;
        } elseif ($anySupplierOrdered) {
            $status = SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED;
        } elseif ($anyRequestCreated) {
            $status = SupplierProcurementRequest::STATUS_REQUESTED;
        } elseif ($status === SupplierProcurementRequest::STATUS_COMPLETED && !$allComplete) {
            $status = $anyReceived
                ? SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED
                : SupplierProcurementRequest::STATUS_DRAFT;
        }

        $request->forceFill([
            'status' => $status,
            'updated_at' => now(),
        ])->save();
    }

    protected function validatedProcurementsForSupplier(TenantAccount $tenant, int $supplierId, array $procurementIds)
    {
        $ids = collect($procurementIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException('En az bir tedarik kalemi seçilmelidir.');
        }

        $procurements = $this->dataBuilder->getCandidateProcurementsForSupplier($tenant, $supplierId)
            ->whereIn('id', $ids->all())
            ->values();

        if ($procurements->count() !== $ids->count()) {
            throw new InvalidArgumentException('Seçilen tedarik kalemleri aynı tedarikçiye ait değil veya açık talep altında.');
        }

        if ($procurements->contains(fn (OrderItemProcurement $procurement) => (int) $procurement->supplier_id !== $supplierId)) {
            throw new InvalidArgumentException('Tüm tedarik kalemleri aynı tedarikçiye ait olmalıdır.');
        }

        return $procurements;
    }

    protected function generateRequestNumber(TenantAccount $tenant): string
    {
        return $this->numberGenerationService->generateNumber(
            $tenant->id,
            'supplier_procurement_request',
            'TS'
        );
    }

    private function dispatchSafely(SupplierProcurementRequest $request, string $eventKey, array $options = []): void
    {
        $tenant = $request->tenant;

        if (!$tenant) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent($tenant, $eventKey, $request, $options);
        } catch (\Throwable) {
            // Supplier request akışı notification hatası nedeniyle rollback edilmemeli.
        }
    }
}
