<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormAttachment;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;

trait BuildsProductionShowFixtures
{
    use BuildsCounterpartyCurrentAccountFixtures;

    protected const PRODUCTION_SHOW_HOST = 'prodelya_core.test';

    protected function setUpProductionShowFixtures(): void
    {
        Storage::fake('public');
        $this->setUpCounterpartyFixtures();
    }

    protected function createInternalProductionForShow(
        array $productionOverrides = [],
        array $itemOverrides = [],
        array $printOverrides = []
    ): OrderItemPrintProduction {
        $order = $this->createOrder('SP-PRD-SHOW-' . random_int(1000, 9999));

        $item = OrderItem::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'customer_supplied',
            'product_name' => 'Üretim Görünüm Ürünü',
            'product_code' => 'PRD-SHOW-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'has_print' => true,
            'print_total' => 100,
            'status' => 'pending',
        ], $itemOverrides));

        OrderItemPrint::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_size' => 'Standart',
            'print_quantity' => (float) $item->quantity,
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'status' => 'draft',
        ], $printOverrides));

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

        $production = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->where('order_id', $order->id)
            ->latest('id')
            ->firstOrFail();

        $production->forceFill(array_merge([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'production_unit_name' => 'İç Üretim Hattı',
            'assigned_to' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ], $productionOverrides))->save();

        return $production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint.graphicOperation',
            'workForm.procurement',
            'workForm.attachments',
            'workForm.activityLogs.creator',
            'assignedUser',
        ]);
    }

    protected function createExternalProductionForShow(
        ?Company $partner = null,
        array $productionOverrides = []
    ): OrderItemPrintProduction {
        $partner ??= $this->createPartnerCompany('Üretim Fason Partneri');

        $production = $this->createProduction(
            'SP-PRD-EXT-' . random_int(1000, 9999),
            $partner,
            OrderItemPrintProduction::TYPE_OUTSOURCED
        );

        $production->forceFill(array_merge([
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'production_company_id' => $partner->id,
            'production_status' => OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            'planned_quantity' => 100,
            'completed_quantity' => 35,
            'remaining_quantity' => 65,
            'subcontractor_cost' => 1800,
            'subcontractor_cost_currency' => 'TRY',
            'sent_to_subcontractor_at' => now()->subDay(),
            'returned_from_subcontractor_at' => now()->addDay(),
            'updated_by' => $this->adminUser->id,
        ], $productionOverrides))->save();

        return $production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint.graphicOperation',
            'workForm.procurement',
            'workForm.attachments',
            'workForm.activityLogs.creator',
            'productionCompany',
            'assignedUser',
        ]);
    }

    protected function prepareProductionForReadyState(
        OrderItemPrintProduction $production,
        string $fileName = 'production-ready.jpg'
    ): OrderItemPrintProduction {
        $production = $production->fresh(['orderItemPrint.graphicOperation', 'workForm.procurement']);
        $graphic = $production->orderItemPrint?->graphicOperation;

        if ($graphic instanceof OrderItemPrintGraphic) {
            app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
                $graphic,
                UploadedFile::fake()->image($fileName),
                ['note' => 'Üretim görseli', 'visibility' => 'internal'],
                $this->adminUser
            );

            app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
            app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);
        }

        $procurement = $production->workForm?->procurement;

        if ($procurement) {
            $procurement->forceFill([
                'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                'updated_by' => $this->adminUser->id,
            ])->save();

            $production->workForm?->forceFill([
                'procurement_snapshot' => array_merge(
                    is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                    [
                        'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                        'procurement_status_label' => 'Tamamı Geldi',
                        'public_status_label' => 'Ürün üretime hazır',
                        'received_quantity' => (float) $production->planned_quantity,
                    ]
                ),
                'updated_by' => $this->adminUser->id,
            ])->save();
        }

        return $production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint.graphicOperation',
            'workForm.procurement',
            'workForm.attachments.uploader',
            'workForm.activityLogs.creator',
            'assignedUser',
        ]);
    }

    protected function uploadProductionPhoto(
        OrderItemPrintProduction $production,
        string $fileName = 'production-photo.jpg',
        string $note = 'Üretim fotoğrafı'
    ): OrderItemWorkFormAttachment {
        return OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $production->tenant_account_id,
            'work_form_id' => $production->work_form_id,
            'order_id' => $production->order_id,
            'order_item_id' => $production->order_item_id,
            'attachment_type' => 'production_photo',
            'visibility' => 'internal',
            'file_path' => 'work-forms/' . $production->work_form_id . '/' . $fileName,
            'file_name' => $fileName,
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => $note,
        ]);
    }

    protected function createExternalTransactionStatus(OrderItemPrintProduction $production): ?CurrentAccountTransaction
    {
        return app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction(
            $production->fresh([
                'order.customer',
                'orderItem',
                'orderItemPrint',
                'productionCompany.companyRoles',
            ])
        );
    }
}
