<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class WorkFormCreationService
{
    public function __construct(
        protected NumberGenerationService $numberGenerationService,
        protected WorkFormDataBuilder $dataBuilder,
        protected WorkFormAttachmentService $workFormAttachmentService,
        protected WorkFolderCreationService $workFolderCreationService,
        protected ProcurementCreationService $procurementCreationService,
        protected ProductionCreationService $productionCreationService,
        protected DeliveryCreationService $deliveryCreationService,
        protected OrderItemPrintGraphicCreationService $orderItemPrintGraphicCreationService,
        protected OrderItemPrintSetupRequirementService $setupRequirementService
    ) {}

    public function createForOrder(Order $order, ?User $user = null): Collection
    {
        $order->loadMissing([
            'customer.contacts',
            'customer.addresses',
            'items.prints.subcontractorCompany',
            'items.tenantCatalogProductVariant.catalogProduct',
            'items.tenantCatalogProduct',
            'items.legacySupplierCompany',
        ]);

        $createdForms = new Collection();

        foreach ($order->items->values() as $index => $item) {
            $createdForms->push(
                $this->createForOrderItem($order, $item, $index + 1, $user)
            );
        }

        return $createdForms;
    }

    public function createForOrderItem(Order $order, OrderItem $item, int $sequence, ?User $user = null): OrderItemWorkForm
    {
        $existing = OrderItemWorkForm::query()
            ->where('order_item_id', $item->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            foreach ($item->prints as $print) {
                $this->setupRequirementService->syncForPrint($print);
            }

            $this->procurementCreationService->createForOrderItem($item, $existing, $user);
            $this->productionCreationService->createForWorkForm($existing, $user);
            $this->deliveryCreationService->createForWorkForm($existing, $user);
            $this->orderItemPrintGraphicCreationService->ensureForOrderItem($item, $user);
            return $existing;
        }

        $snapshots = $this->dataBuilder->build($order, $item, $sequence);

        $workForm = OrderItemWorkForm::create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_id' => $order->source_quote_id,
            'source_quote_number' => $order->source_quote_number,
            'work_form_number' => $this->numberGenerationService->generateNumber($order->tenant_account_id, 'work_form', 'IF'),
            'item_sequence' => $sequence,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => $this->generateUniqueTrackingToken(),
            'order_snapshot' => $snapshots['order_snapshot'],
            'customer_snapshot' => $snapshots['customer_snapshot'],
            'product_snapshot' => $snapshots['product_snapshot'],
            'print_snapshot' => $snapshots['print_snapshot'],
            'graphic_snapshot' => $snapshots['graphic_snapshot'],
            'production_snapshot' => $snapshots['production_snapshot'],
            'delivery_snapshot' => $snapshots['delivery_snapshot'],
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        $this->workFormAttachmentService->createInitialActivityLog($workForm, $user);
        $this->workFolderCreationService->createSystemFolderForWorkForm($workForm, $user);
        $this->procurementCreationService->createForOrderItem($item, $workForm, $user);
        foreach ($item->prints as $print) {
            $this->setupRequirementService->createForPrint($print);
        }
        $this->productionCreationService->createForWorkForm($workForm, $user);
        $this->deliveryCreationService->createForWorkForm($workForm, $user);
        $this->orderItemPrintGraphicCreationService->ensureForOrderItem($item, $user);

        return $workForm;
    }

    private function generateUniqueTrackingToken(): string
    {
        do {
            $token = Str::random(48);
        } while (OrderItemWorkForm::query()->where('public_tracking_token', $token)->exists());

        return $token;
    }
}
