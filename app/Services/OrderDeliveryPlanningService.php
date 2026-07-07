<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDeliveryLabelBatch;
use App\Models\OrderDeliveryPackage;
use App\Models\OrderDeliveryPackageItem;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderDeliveryPlanningService
{
    public function __construct(
        protected DeliveryWorkflowService $deliveryWorkflowService,
    ) {
    }

    public function buildContext(Order $order): array
    {
        $planningAvailable = $this->supportsPlanningStorage();

        $order->loadMissing([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]);

        if ($planningAvailable) {
            $order->loadMissing([
                'deliveryPackages.items.orderItem',
                'deliveryLabelBatches',
            ]);
        }

        $packages = $planningAvailable
            ? $order->deliveryPackages->sortBy('package_no')->values()
            : collect();
        $labelBatches = $planningAvailable
            ? $order->deliveryLabelBatches->sortByDesc('id')->values()
            : collect();
        $deliveries = $order->deliveries->values();
        $allDelivered = $deliveries->isNotEmpty() && $deliveries->every(fn (OrderItemWorkFormDelivery $delivery): bool => $delivery->isDelivered() && ! $delivery->hasIssue());

        $itemReadiness = $order->items
            ->values()
            ->map(function (OrderItem $item): array {
                $readyQuantity = $this->eligibleReadyQuantity($item);
                $orderedQuantity = round((float) $item->quantity, 4);
                $preparedQuantity = min($readyQuantity, $orderedQuantity);
                $waitingQuantity = max(round($orderedQuantity - $preparedQuantity, 4), 0.0);

                return [
                    'order_item_id' => $item->id,
                    'product_name' => $item->product_name ?: '-',
                    'product_code' => $item->product_code ?: '-',
                    'ordered_quantity' => $orderedQuantity,
                    'ordered_quantity_label' => $this->formatQuantity($orderedQuantity, $item->unit),
                    'ready_quantity' => $preparedQuantity,
                    'ready_quantity_label' => $this->formatQuantity($preparedQuantity, $item->unit),
                    'waiting_quantity' => $waitingQuantity,
                    'waiting_quantity_label' => $this->formatQuantity($waitingQuantity, $item->unit),
                    'status_label' => $preparedQuantity <= 0.0001
                        ? 'Bekliyor'
                        : ($waitingQuantity <= 0.0001 ? 'Hazır' : 'Kısmi Hazır'),
                    'status_tone' => $preparedQuantity <= 0.0001
                        ? 'gray'
                        : ($waitingQuantity <= 0.0001 ? 'green' : 'amber'),
                    'unit' => $item->unit ?: '',
                ];
            });

        $packageRows = $packages->map(function (OrderDeliveryPackage $package): array {
            $items = $package->items->map(function (OrderDeliveryPackageItem $packageItem): array {
                return [
                    'product_name' => $packageItem->item_name_snapshot ?: ($packageItem->orderItem?->product_name ?: '-'),
                    'product_code' => $packageItem->item_sku_snapshot ?: ($packageItem->orderItem?->product_code ?: '-'),
                    'quantity' => (float) $packageItem->quantity,
                    'quantity_label' => $this->formatQuantity((float) $packageItem->quantity, $packageItem->orderItem?->unit),
                ];
            })->values();

            $totalQuantity = round((float) $package->items->sum('quantity'), 4);
            $statusLabel = OrderDeliveryPackage::statusLabels()[$package->status] ?? 'Planlandı';

            return [
                'id' => $package->id,
                'package_no' => $package->package_no,
                'package_label' => $package->package_label ?: ('Koli ' . $package->package_no),
                'package_type_label' => OrderDeliveryPackage::packageTypeLabels()[$package->package_type] ?? 'Koli',
                'status_label' => $statusLabel,
                'status_tone' => match ($package->status) {
                    OrderDeliveryPackage::STATUS_DELIVERED => 'green',
                    OrderDeliveryPackage::STATUS_LABEL_READY => 'blue',
                    OrderDeliveryPackage::STATUS_CANCELLED => 'red',
                    default => 'gray',
                },
                'total_quantity' => $totalQuantity,
                'total_quantity_label' => $this->formatQuantity($totalQuantity),
                'items' => $items,
                'item_count' => $items->count(),
                'notes' => $package->notes,
            ];
        })->values();

        $latestLabelBatch = $labelBatches->first();
        $deliveryInfo = $this->buildDeliveryInfo($deliveries, $order);

        return [
            'steps' => [
                $this->stepRow('Teslimata Hazırla', $itemReadiness->contains(fn (array $row): bool => $row['ready_quantity'] > 0.0001), 'Kalem bazlı hazır miktarlar izlenir.'),
                $this->stepRow('Koli Planı', $packages->isNotEmpty(), $packages->isNotEmpty() ? ($packages->count() . ' koli planlandı.') : 'Koli planı bekleniyor.'),
                $this->stepRow('Etiket Oluştur', $latestLabelBatch !== null, $latestLabelBatch ? $this->labelBatchSummary($latestLabelBatch) : 'Etiket partisi henüz oluşturulmadı.'),
                $this->stepRow('Teslim Bilgisi', $deliveryInfo['has_details'], $deliveryInfo['summary']),
                $this->stepRow('Teslim Edildi', $allDelivered, $allDelivered ? 'Teslimat tamamlandı.' : 'Teslimat tamamlanmadı.'),
            ],
            'item_readiness' => $itemReadiness->all(),
            'package_rows' => $packageRows->all(),
            'package_count' => $packages->count(),
            'label_count' => $packages->count(),
            'label_batches' => $labelBatches->map(function (OrderDeliveryLabelBatch $batch): array {
                return [
                    'id' => $batch->id,
                    'template_label' => OrderDeliveryLabelBatch::templateLabels()[$batch->template_type] ?? 'Etiket',
                    'label_count' => $batch->label_count,
                    'page_summary' => $this->labelBatchSummary($batch),
                    'status_label' => match ($batch->status) {
                        OrderDeliveryLabelBatch::STATUS_PRINTED => 'Basıldı',
                        OrderDeliveryLabelBatch::STATUS_READY => 'Hazır',
                        default => 'Taslak',
                    },
                    'printed_at_label' => optional($batch->printed_at)->format('d.m.Y H:i'),
                ];
            })->all(),
            'delivery_info' => $deliveryInfo,
            'planning_available' => $planningAvailable,
            'planning_notice' => $planningAvailable ? null : 'Bu ortamda koli planı ve etiket kayıtları henüz hazır değil. Teslimat görünümü açılır, ancak koli ve etiket kayıtları kullanılamaz.',
            'method_options' => OrderItemWorkFormDelivery::deliveryMethodLabels(),
            'package_type_options' => OrderDeliveryPackage::packageTypeLabels(),
            'label_template_options' => OrderDeliveryLabelBatch::templateLabels(),
            'latest_label_batch' => $latestLabelBatch,
            'is_delivered' => $allDelivered,
            'completion_note' => $allDelivered
                ? 'Teslimat tamamlandı. Sipariş operasyon akışından çıkarıldı. Finans bakiyesi açıksa Cari Ekstre ve Finans ekranında takip edilmeye devam eder.'
                : null,
        ];
    }

    public function storePackages(Order $order, array $packages, User $actor): void
    {
        $this->ensurePlanningStorageAvailable();

        $order->loadMissing(['items.delivery', 'items.workForm.printProductions', 'items.workForm.procurement', 'deliveries']);

        if ($this->isOrderDelivered($order)) {
            throw ValidationException::withMessages([
                'packages' => 'Teslim edilmiş siparişe yeni koli planı eklenemez.',
            ]);
        }

        if ($packages === []) {
            throw ValidationException::withMessages([
                'packages' => 'Boş koli kaydedilemez.',
            ]);
        }

        $itemsById = $order->items->keyBy('id');
        $plannedTotals = [];

        foreach ($packages as $packageIndex => $packageRow) {
            $packageItems = collect($packageRow['items'] ?? [])->filter(fn (array $item): bool => (float) ($item['quantity'] ?? 0) > 0)->values();

            if ($packageItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'packages' => 'Boş koli kaydedilemez.',
                ]);
            }

            foreach ($packageItems as $itemRow) {
                $orderItemId = (int) ($itemRow['order_item_id'] ?? 0);
                $quantity = round((float) ($itemRow['quantity'] ?? 0), 4);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'packages' => 'Koli içi ürün adetleri pozitif olmalı.',
                    ]);
                }

                /** @var OrderItem|null $orderItem */
                $orderItem = $itemsById->get($orderItemId);
                if (! $orderItem || (int) $orderItem->tenant_account_id !== (int) $order->tenant_account_id) {
                    throw ValidationException::withMessages([
                        'packages' => 'Başka tenant sipariş kalemi koli planına eklenemez.',
                    ]);
                }

                $plannedTotals[$orderItemId] = round(($plannedTotals[$orderItemId] ?? 0) + $quantity, 4);
            }
        }

        foreach ($plannedTotals as $orderItemId => $plannedQuantity) {
            /** @var OrderItem $orderItem */
            $orderItem = $itemsById->get($orderItemId);
            $eligible = $this->eligibleReadyQuantity($orderItem);

            if ($plannedQuantity - $eligible > 0.0001) {
                throw ValidationException::withMessages([
                    'packages' => 'Koli miktarı teslimata hazır adedi aşamaz.',
                ]);
            }
        }

        DB::transaction(function () use ($order, $packages, $actor): void {
            OrderDeliveryPackageItem::query()
                ->where('tenant_account_id', $order->tenant_account_id)
                ->where('order_id', $order->id)
                ->delete();

            OrderDeliveryPackage::query()
                ->where('tenant_account_id', $order->tenant_account_id)
                ->where('order_id', $order->id)
                ->delete();

            foreach (array_values($packages) as $index => $packageRow) {
                $package = OrderDeliveryPackage::query()->create([
                    'tenant_account_id' => $order->tenant_account_id,
                    'order_id' => $order->id,
                    'package_no' => $index + 1,
                    'package_label' => $this->cleanNullable($packageRow['package_label'] ?? null),
                    'package_type' => $packageRow['package_type'] ?? OrderDeliveryPackage::TYPE_BOX,
                    'status' => OrderDeliveryPackage::STATUS_PLANNED,
                    'notes' => $this->cleanNullable($packageRow['notes'] ?? null),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                foreach (collect($packageRow['items'] ?? [])->filter(fn (array $item): bool => (float) ($item['quantity'] ?? 0) > 0) as $itemRow) {
                    $orderItem = $order->items->firstWhere('id', (int) $itemRow['order_item_id']);

                    OrderDeliveryPackageItem::query()->create([
                        'tenant_account_id' => $order->tenant_account_id,
                        'order_delivery_package_id' => $package->id,
                        'order_id' => $order->id,
                        'order_item_id' => $orderItem->id,
                        'quantity' => round((float) $itemRow['quantity'], 4),
                        'item_name_snapshot' => $orderItem->product_name ?: '-',
                        'item_sku_snapshot' => $orderItem->product_code,
                    ]);
                }
            }

            $this->syncDeliveryPackageSummary($order, $actor);
        });
    }

    public function createLabelBatch(Order $order, array $attributes, User $actor): OrderDeliveryLabelBatch
    {
        $this->ensurePlanningStorageAvailable();

        $order->loadMissing('deliveryPackages');

        $packageCount = $order->deliveryPackages->count();

        if ($packageCount <= 0) {
            throw ValidationException::withMessages([
                'template_type' => 'Etiket oluşturmadan önce koli planı kaydedilmelidir.',
            ]);
        }

        return DB::transaction(function () use ($order, $attributes, $actor, $packageCount): OrderDeliveryLabelBatch {
            $templateType = (string) ($attributes['template_type'] ?? OrderDeliveryLabelBatch::TEMPLATE_A4_1_4);
            $pageCount = $this->labelPageCount($templateType, $packageCount);

            $batch = OrderDeliveryLabelBatch::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'template_type' => $templateType,
                'label_count' => $packageCount,
                'page_count' => $pageCount,
                'roll_width_mm' => $templateType === OrderDeliveryLabelBatch::TEMPLATE_ROLL ? round((float) ($attributes['roll_width_mm'] ?? 0), 2) : null,
                'roll_height_mm' => $templateType === OrderDeliveryLabelBatch::TEMPLATE_ROLL ? round((float) ($attributes['roll_height_mm'] ?? 0), 2) : null,
                'roll_gap_mm' => $templateType === OrderDeliveryLabelBatch::TEMPLATE_ROLL ? round((float) ($attributes['roll_gap_mm'] ?? 0), 2) : null,
                'status' => OrderDeliveryLabelBatch::STATUS_READY,
                'created_by' => $actor->id,
            ]);

            OrderDeliveryPackage::query()
                ->where('tenant_account_id', $order->tenant_account_id)
                ->where('order_id', $order->id)
                ->update([
                    'status' => OrderDeliveryPackage::STATUS_LABEL_READY,
                    'updated_by' => $actor->id,
                ]);

            return $batch;
        });
    }

    public function updateDeliveryInfo(Order $order, array $attributes, User $actor): void
    {
        $order->loadMissing('deliveries');

        DB::transaction(function () use ($order, $attributes, $actor): void {
            if (array_key_exists('delivery_type', $attributes)) {
                $order->forceFill([
                    'delivery_type' => $this->cleanNullable($attributes['delivery_type']),
                ])->save();
            }

            foreach ($order->deliveries as $delivery) {
                $this->deliveryWorkflowService->updateDetails($delivery, $attributes, $actor, 'Teslim bilgisi güncellendi.');
            }
        });
    }

    public function completeDelivery(Order $order, array $attributes, User $actor): void
    {
        $this->ensurePlanningStorageAvailable();

        $order->loadMissing(['deliveries', 'deliveryPackages']);

        if ($order->deliveryPackages->isEmpty()) {
            throw ValidationException::withMessages([
                'delivery_method' => 'Teslimi tamamlamadan önce koli planı kaydedilmelidir.',
            ]);
        }

        DB::transaction(function () use ($order, $attributes, $actor): void {
            foreach ($order->deliveries as $delivery) {
                if ($delivery->isDelivered()) {
                    continue;
                }

                $this->deliveryWorkflowService->markDelivered($delivery, $attributes, $actor, 'Sipariş teslim edildi.');
            }

            OrderDeliveryPackage::query()
                ->where('tenant_account_id', $order->tenant_account_id)
                ->where('order_id', $order->id)
                ->update([
                    'status' => OrderDeliveryPackage::STATUS_DELIVERED,
                    'updated_by' => $actor->id,
                ]);
        });
    }

    public function buildPrintData(Order $order, ?OrderDeliveryLabelBatch $batch = null): array
    {
        $planningAvailable = $this->supportsPlanningStorage();

        $order->loadMissing('customer');

        if ($planningAvailable) {
            $order->loadMissing(['deliveryPackages.items.orderItem', 'deliveryLabelBatches']);
            $batch ??= $order->deliveryLabelBatches()->latest('id')->first();
            $packages = $order->deliveryPackages->sortBy('package_no')->values();
        } else {
            $batch = null;
            $packages = collect();
        }

        $labelCount = $packages->count();

        return [
            'order' => $order,
            'customer_name' => $order->customer?->legal_name ?: 'Müşteri bilgisi yok',
            'template_label' => $batch ? (OrderDeliveryLabelBatch::templateLabels()[$batch->template_type] ?? 'Etiket') : 'Etiket',
            'page_summary' => $batch ? $this->labelBatchSummary($batch) : null,
            'labels' => $packages->map(function (OrderDeliveryPackage $package): array {
                return [
                    'package_label' => $package->package_label ?: ('Koli ' . $package->package_no),
                    'package_no' => $package->package_no,
                    'package_type_label' => OrderDeliveryPackage::packageTypeLabels()[$package->package_type] ?? 'Koli',
                    'item_lines' => $package->items->map(function (OrderDeliveryPackageItem $item): array {
                        return [
                            'product_name' => $item->item_name_snapshot ?: ($item->orderItem?->product_name ?: '-'),
                            'quantity_label' => $this->formatQuantity((float) $item->quantity, $item->orderItem?->unit),
                        ];
                    })->all(),
                    'total_quantity_label' => $this->formatQuantity((float) $package->items->sum('quantity')),
                ];
            })->all(),
            'print_date_label' => now()->format('d.m.Y H:i'),
            'label_count' => $labelCount,
            'planning_available' => $planningAvailable,
        ];
    }

    public function supportsPlanningStorage(): bool
    {
        return Schema::hasTable('order_delivery_packages')
            && Schema::hasTable('order_delivery_package_items')
            && Schema::hasTable('order_delivery_label_batches');
    }

    public function labelPageCount(string $templateType, int $labelCount): ?int
    {
        $perPage = match ($templateType) {
            OrderDeliveryLabelBatch::TEMPLATE_A4_1_4 => 4,
            OrderDeliveryLabelBatch::TEMPLATE_A4_1_2 => 2,
            OrderDeliveryLabelBatch::TEMPLATE_A4_1_1 => 1,
            default => null,
        };

        return $perPage ? (int) ceil($labelCount / $perPage) : null;
    }

    public function labelBatchSummary(OrderDeliveryLabelBatch $batch): string
    {
        if ($batch->template_type === OrderDeliveryLabelBatch::TEMPLATE_ROLL) {
            return sprintf(
                '%d etiket · %s mm x %s mm · Ara %s mm',
                (int) $batch->label_count,
                $this->trimDecimal((float) $batch->roll_width_mm),
                $this->trimDecimal((float) $batch->roll_height_mm),
                $this->trimDecimal((float) $batch->roll_gap_mm)
            );
        }

        $perPage = match ($batch->template_type) {
            OrderDeliveryLabelBatch::TEMPLATE_A4_1_4 => 4,
            OrderDeliveryLabelBatch::TEMPLATE_A4_1_2 => 2,
            OrderDeliveryLabelBatch::TEMPLATE_A4_1_1 => 1,
            default => 1,
        };

        $fullPages = intdiv((int) $batch->label_count, $perPage);
        $remainder = (int) $batch->label_count % $perPage;
        $parts = [];

        if ($fullPages > 0) {
            $parts[] = $fullPages . ' tam sayfa';
        }

        if ($remainder > 0) {
            $parts[] = '1 yarım sayfa';
        }

        return sprintf(
            '%d etiket için %d A4 sayfa hazırlanacak. %s.',
            (int) $batch->label_count,
            (int) ($batch->page_count ?? 0),
            $parts !== [] ? implode(' + ', $parts) : 'Tüm sayfalar tam dolu'
        );
    }

    private function buildDeliveryInfo(Collection $deliveries, Order $order): array
    {
        /** @var OrderItemWorkFormDelivery|null $first */
        $first = $deliveries->first();

        $summaryParts = array_filter([
            $first?->safeDeliveryMethodLabel() ?: $order->delivery_type,
            $first?->recipient_name,
            $first?->tracking_number,
        ]);

        return [
            'delivery_method' => $first?->delivery_method,
            'recipient_name' => $first?->recipient_name,
            'recipient_phone' => $first?->recipient_phone,
            'delivery_document_no' => $first?->delivery_document_no,
            'tracking_number' => $first?->tracking_number,
            'carrier_name' => $first?->carrier_name,
            'delivery_note' => $first?->delivery_note,
            'has_details' => $summaryParts !== [],
            'summary' => $summaryParts !== [] ? implode(' · ', $summaryParts) : 'Teslim bilgisi henüz girilmedi.',
        ];
    }

    private function syncDeliveryPackageSummary(Order $order, User $actor): void
    {
        $packageItems = OrderDeliveryPackageItem::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('order_id', $order->id)
            ->get()
            ->groupBy('order_item_id');

        foreach ($order->deliveries as $delivery) {
            $group = $packageItems->get($delivery->order_item_id, collect());

            $delivery->forceFill([
                'package_count' => $group->count() > 0
                    ? OrderDeliveryPackage::query()
                        ->where('tenant_account_id', $order->tenant_account_id)
                        ->where('order_id', $order->id)
                        ->whereHas('items', fn ($query) => $query->where('order_item_id', $delivery->order_item_id))
                        ->count()
                    : null,
                'packaged_quantity' => $group->count() > 0 ? (int) round((float) $group->sum('quantity')) : null,
                'updated_by' => $actor->id,
            ])->save();
        }
    }

    private function eligibleReadyQuantity(OrderItem $item): float
    {
        $planned = round((float) ($item->delivery?->planned_quantity ?? $item->quantity), 4);

        if ($item->has_print) {
            $productions = $item->workForm?->printProductions ?? collect();

            if ($productions->isNotEmpty()) {
                $completed = $productions
                    ->map(fn (OrderItemPrintProduction $production): float => round((float) $production->completed_quantity, 4))
                    ->min();

                return max(min((float) $completed, $planned), 0.0);
            }

            return max(min(
                round((float) data_get($item->workForm?->production_snapshot, 'completed_quantity', 0), 4),
                $planned
            ), 0.0);
        }

        $procurement = $item->workForm?->procurement;
        $procurementStatus = (string) ($procurement?->procurement_status
            ?? data_get($item->workForm?->procurement_snapshot, 'procurement_status', ''));
        $receivedQuantity = round((float) (
            $procurement?->received_quantity
            ?? data_get($item->workForm?->procurement_snapshot, 'received_quantity', 0)
        ), 4);

        return match ($procurementStatus) {
            OrderItemProcurement::STATUS_NOT_REQUIRED => $planned,
            OrderItemProcurement::STATUS_FULLY_RECEIVED,
            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => $receivedQuantity > 0 ? min($receivedQuantity, $planned) : $planned,
            OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => min($receivedQuantity, $planned),
            default => $procurementStatus === '' ? $planned : min($receivedQuantity, $planned),
        };
    }

    private function isOrderDelivered(Order $order): bool
    {
        return $order->deliveries->isNotEmpty()
            && $order->deliveries->every(fn (OrderItemWorkFormDelivery $delivery): bool => $delivery->isDelivered());
    }

    private function stepRow(string $title, bool $isDone, string $detail): array
    {
        return [
            'title' => $title,
            'status_label' => $isDone ? 'Hazır' : 'Bekliyor',
            'status_tone' => $isDone ? 'green' : 'gray',
            'detail' => $detail,
        ];
    }

    private function formatQuantity(float $quantity, ?string $unit = null): string
    {
        $formatted = number_format($quantity, 4, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function trimDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private function cleanNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function ensurePlanningStorageAvailable(): void
    {
        if ($this->supportsPlanningStorage()) {
            return;
        }

        throw ValidationException::withMessages([
            'packages' => 'Bu ortamda koli planı ve etiket kayıtları henüz hazır değil.',
        ]);
    }
}
