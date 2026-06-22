<?php

namespace App\Services;

use App\Models\GraphicApprovalRequest;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Collection;

class AdminDashboardWorkQueueDataBuilder
{
    public function __construct(
        protected TenantAccessService $tenantAccessService,
        protected ProductionReadinessResolver $productionReadinessResolver,
    ) {
    }

    public function build(TenantAccount $tenant, ?User $user = null): array
    {
        $cards = collect();
        $queueItems = collect();

        $quotesEnabled = $this->tenantAccessService->canAccessModule($tenant, 'order_flow');
        $graphicsEnabled = $this->tenantAccessService->canAccessModule($tenant, 'graphics');
        $graphicApprovalEnabled = $this->tenantAccessService->canAccessModule($tenant, 'graphic_customer_approval')
            && $this->tenantAccessService->canAccessFeature($tenant, 'public_graphic_approval', 'graphic_customer_approval');
        $procurementEnabled = $this->tenantAccessService->canAccessModule($tenant, 'procurement');
        $productionEnabled = $this->tenantAccessService->canAccessModule($tenant, 'production');
        $deliveryEnabled = $this->tenantAccessService->canAccessModule($tenant, 'delivery');
        $notificationEnabled = $this->tenantAccessService->canAccessModule($tenant, 'notification_center')
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_logs', 'notification_center');

        if ($quotesEnabled) {
            $waitingQuotes = Order::query()
                ->where('tenant_account_id', $tenant->id)
                ->quotes()
                ->where('customer_approval_status', Order::CUSTOMER_APPROVAL_WAITING)
                ->count();

            $convertibleQuotes = Order::query()
                ->where('tenant_account_id', $tenant->id)
                ->quotes()
                ->where(function ($query) {
                    $query->where('customer_approval_status', Order::CUSTOMER_APPROVAL_APPROVED)
                        ->orWhere('status', 'approved');
                })
                ->where('workflow_status', '!=', 'quote_converted')
                ->whereDoesntHave('convertedOrders')
                ->count();

            $cards->push([
                'title' => 'Onay Bekleyen Teklifler',
                'count' => $waitingQuotes,
                'description' => 'Müşteri yanıtı bekleyen teklifler.',
                'tone' => 'blue',
                'cta_label' => 'Tekliflere Git',
                'cta_url' => route('admin.promotion-quotes.index', ['status' => 'waiting']),
            ]);

            $cards->push([
                'title' => 'Siparişe Çevrilebilir Teklifler',
                'count' => $convertibleQuotes,
                'description' => 'Onay almış ve siparişe hazır teklifler.',
                'tone' => 'green',
                'cta_label' => 'Siparişe Çevirilecekler',
                'cta_url' => route('admin.promotion-quotes.index', ['status' => 'approved']),
            ]);

            $queueItems = $queueItems->merge($this->buildQuoteQueueItems($tenant));
        }

        if ($graphicsEnabled) {
            $graphicQueue = OrderItemPrintGraphic::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('status', [
                    OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
                    OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
                ])
                ->count();

            $cards->push([
                'title' => 'Grafik Bekleyen İşler',
                'count' => $graphicQueue,
                'description' => 'Görsel bekleyen veya revize isteyen baskılar.',
                'tone' => 'amber',
                'cta_label' => 'Grafik İşleri',
                'cta_url' => route('admin.graphics.index'),
            ]);

            $queueItems = $queueItems->merge($this->buildGraphicQueueItems($tenant));
        }

        if ($graphicApprovalEnabled) {
            $graphicApprovalQueue = GraphicApprovalRequest::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('status', [
                    GraphicApprovalRequest::STATUS_WAITING,
                    GraphicApprovalRequest::STATUS_VIEWED,
                ])
                ->count();

            $cards->push([
                'title' => 'Müşteri Grafik Onayı Bekleyenler',
                'count' => $graphicApprovalQueue,
                'description' => 'Müşteride açık grafik onayları.',
                'tone' => 'purple',
                'cta_label' => 'Grafik Onayları',
                'cta_url' => route('admin.graphics.index', ['approval_status' => 'waiting']),
            ]);

            $queueItems = $queueItems->merge($this->buildGraphicApprovalQueueItems($tenant));
        }

        if ($procurementEnabled) {
            $procurementQueue = OrderItemProcurement::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('procurement_status', [
                    OrderItemProcurement::STATUS_PENDING,
                    OrderItemProcurement::STATUS_REQUEST_CREATED,
                    OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                    OrderItemProcurement::STATUS_CUSTOMER_WAITING,
                ])
                ->count();

            $cards->push([
                'title' => 'Tedarik Bekleyen İşler',
                'count' => $procurementQueue,
                'description' => 'Talep açılacak veya tedarik tamamlanacak kalemler.',
                'tone' => 'amber',
                'cta_label' => 'Tedarik',
                'cta_url' => route('admin.procurements.index', ['receipt_state' => 'bekliyor']),
            ]);

            $queueItems = $queueItems->merge($this->buildProcurementQueueItems($tenant));
        }

        if ($productionEnabled) {
            $pendingProductions = OrderItemPrintProduction::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('production_status', OrderItemPrintProduction::STATUS_PENDING)
                ->with([
                    'order.customer',
                    'orderItem',
                    'orderItemPrint.graphicOperation.latestAttachment',
                    'orderItemPrint.setupRequirements',
                    'graphicOperation.latestAttachment',
                    'workForm.procurement',
                    'workForm.attachments',
                ])
                ->latest('id')
                ->get();

            $readyCount = 0;
            $blockedCount = 0;

            foreach ($pendingProductions as $production) {
                $readiness = $this->productionReadinessResolver->resolve($production);

                if ($readiness['can_start']) {
                    $readyCount++;
                } else {
                    $blockedCount++;
                }
            }

            $cards->push([
                'title' => 'Üretime Hazır / Bloklu Üretimler',
                'count' => $blockedCount,
                'description' => 'Hazır: ' . $readyCount . ' · Bloklu: ' . $blockedCount,
                'tone' => $blockedCount > 0 ? 'red' : 'green',
                'cta_label' => 'Üretim',
                'cta_url' => route('admin.productions.index', ['pool' => 'preparation']),
            ]);

            $queueItems = $queueItems->merge($this->buildProductionQueueItems($pendingProductions));
        }

        if ($deliveryEnabled) {
            $deliveryQueue = OrderItemWorkFormDelivery::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereNotIn('delivery_status', [
                    OrderItemWorkFormDelivery::STATUS_DELIVERED,
                    OrderItemWorkFormDelivery::STATUS_CANCELLED,
                ])
                ->count();

            $cards->push([
                'title' => 'Teslimat Bekleyen İşler',
                'count' => $deliveryQueue,
                'description' => 'Teslimata hazırlanacak veya tamamlanacak gönderiler.',
                'tone' => 'green',
                'cta_label' => 'Teslimat',
                'cta_url' => route('admin.deliveries.index'),
            ]);

            $queueItems = $queueItems->merge($this->buildDeliveryQueueItems($tenant));
        }

        if ($notificationEnabled) {
            $failedNotifications = NotificationLog::query()
                ->forTenant($tenant->id)
                ->where('status', NotificationLog::STATUS_FAILED)
                ->count();

            $cards->push([
                'title' => 'Başarısız Bildirimler',
                'count' => $failedNotifications,
                'description' => 'İncelenmesi gereken bildirim hataları.',
                'tone' => $failedNotifications > 0 ? 'red' : 'gray',
                'cta_label' => 'Bildirim Logları',
                'cta_url' => route('admin.notifications.logs.index', ['status' => NotificationLog::STATUS_FAILED]),
            ]);
        }

        $queueItems = $queueItems
            ->sortBy([
                ['priority', 'asc'],
                ['sort_at', 'desc'],
            ])
            ->take(10)
            ->values();

        return [
            'cards' => $cards->values()->all(),
            'queue_items' => $queueItems->all(),
            'quick_links' => $this->quickLinks($tenant),
            'tenant_summary' => [
                'name' => $tenant->name,
                'locale' => $tenant->default_locale,
                'currency' => $tenant->default_currency,
            ],
            'queue_summary' => [
                'total_cards' => $cards->count(),
                'blocked_items' => $queueItems->where('bucket', 'blocked')->count(),
                'awaiting_customer' => $queueItems->where('bucket', 'customer')->count(),
            ],
        ];
    }

    private function buildQuoteQueueItems(TenantAccount $tenant): Collection
    {
        $waiting = Order::query()
            ->where('tenant_account_id', $tenant->id)
            ->quotes()
            ->where('customer_approval_status', Order::CUSTOMER_APPROVAL_WAITING)
            ->with('customer:id,legal_name')
            ->latest('last_sent_at')
            ->take(4)
            ->get()
            ->map(fn (Order $quote) => [
                'kind' => 'Teklif',
                'document_number' => $quote->document_number,
                'customer_name' => $quote->customer?->legal_name ?: '-',
                'summary' => 'Müşteri onayı bekliyor',
                'status' => $quote->quoteDisplayStatusLabel(),
                'cta_label' => 'Aç',
                'cta_url' => route('admin.promotion-quotes.show', $quote),
                'bucket' => 'customer',
                'bucket_label' => 'Müşteri Yanıtı',
                'bucket_tone' => 'blue',
                'priority' => 10,
                'sort_at' => optional($quote->last_sent_at ?? $quote->updated_at)->timestamp ?? 0,
            ]);

        $convertible = Order::query()
            ->where('tenant_account_id', $tenant->id)
            ->quotes()
            ->where(function ($query) {
                $query->where('customer_approval_status', Order::CUSTOMER_APPROVAL_APPROVED)
                    ->orWhere('status', 'approved');
            })
            ->where('workflow_status', '!=', 'quote_converted')
            ->whereDoesntHave('convertedOrders')
            ->with('customer:id,legal_name')
            ->latest('approved_at')
            ->take(2)
            ->get()
            ->map(fn (Order $quote) => [
                'kind' => 'Teklif',
                'document_number' => $quote->document_number,
                'customer_name' => $quote->customer?->legal_name ?: '-',
                'summary' => 'Siparişe çevrilmeyi bekliyor',
                'status' => 'Onaylandı',
                'cta_label' => 'Aç',
                'cta_url' => route('admin.promotion-quotes.show', $quote),
                'bucket' => 'today',
                'bucket_label' => 'Bugün',
                'bucket_tone' => 'green',
                'priority' => 20,
                'sort_at' => optional($quote->approved_at ?? $quote->updated_at)->timestamp ?? 0,
            ]);

        return $waiting->merge($convertible);
    }

    private function buildGraphicQueueItems(TenantAccount $tenant): Collection
    {
        return OrderItemPrintGraphic::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('status', [
                OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
                OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
            ])
            ->with([
                'order.customer:id,legal_name',
                'orderItem:id,product_name',
                'orderItemPrint:id,print_type,print_option',
                'workForm:id,work_form_number',
            ])
            ->latest('updated_at')
            ->take(3)
            ->get()
            ->map(fn (OrderItemPrintGraphic $graphic) => [
                'kind' => 'Grafik',
                'document_number' => ($graphic->order?->document_number ?: '-') . ' / ' . ($graphic->sequence_code ?: '-'),
                'customer_name' => $graphic->order?->customer?->legal_name ?: '-',
                'summary' => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED
                    ? 'Revize istendi'
                    : 'Grafik görseli bekleniyor',
                'status' => $graphic->safeStatusLabel(),
                'cta_label' => 'Grafiğe Git',
                'cta_url' => route('admin.graphics.show', $graphic->workForm),
                'bucket' => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED ? 'urgent' : 'blocked',
                'bucket_label' => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED ? 'Acil' : 'Bloklu',
                'bucket_tone' => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED ? 'red' : 'amber',
                'priority' => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED ? 30 : 40,
                'sort_at' => optional($graphic->updated_at)->timestamp ?? 0,
            ]);
    }

    private function buildGraphicApprovalQueueItems(TenantAccount $tenant): Collection
    {
        return GraphicApprovalRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('status', [
                GraphicApprovalRequest::STATUS_WAITING,
                GraphicApprovalRequest::STATUS_VIEWED,
            ])
            ->with([
                'order.customer:id,legal_name',
                'graphic.workForm:id,work_form_number',
                'graphic.orderItem:id,product_name',
                'graphic.orderItemPrint:id,print_type,print_option',
            ])
            ->latest('created_at')
            ->take(3)
            ->get()
            ->map(fn (GraphicApprovalRequest $request) => [
                'kind' => 'Grafik Onayı',
                'document_number' => ($request->order?->document_number ?: '-') . ' / ' . ($request->graphic?->sequence_code ?: '-'),
                'customer_name' => $request->order?->customer?->legal_name ?: '-',
                'summary' => $request->isViewed()
                    ? 'Müşteri bağlantıyı görüntüledi'
                    : 'Müşteri onayı bekleniyor',
                'status' => $request->safeStatusLabel(),
                'cta_label' => 'Grafiğe Git',
                'cta_url' => route('admin.graphics.show', $request->graphic?->workForm),
                'bucket' => 'customer',
                'bucket_label' => 'Müşteri Yanıtı',
                'bucket_tone' => 'purple',
                'priority' => 25,
                'sort_at' => optional($request->created_at)->timestamp ?? 0,
            ]);
    }

    private function buildProcurementQueueItems(TenantAccount $tenant): Collection
    {
        return OrderItemProcurement::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('procurement_status', [
                OrderItemProcurement::STATUS_PENDING,
                OrderItemProcurement::STATUS_REQUEST_CREATED,
                OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                OrderItemProcurement::STATUS_CUSTOMER_WAITING,
            ])
            ->with([
                'order.customer:id,legal_name',
                'orderItem:id,product_name',
            ])
            ->latest('updated_at')
            ->take(3)
            ->get()
            ->map(fn (OrderItemProcurement $procurement) => [
                'kind' => 'Tedarik',
                'document_number' => $procurement->order?->document_number ?: '-',
                'customer_name' => $procurement->order?->customer?->legal_name ?: '-',
                'summary' => match ($procurement->procurement_status) {
                    OrderItemProcurement::STATUS_PENDING => 'Talep hazırlanmalı',
                    OrderItemProcurement::STATUS_REQUEST_CREATED => 'Sipariş verilmeli',
                    OrderItemProcurement::STATUS_SUPPLIER_ORDERED => 'Ürün gelişi bekleniyor',
                    OrderItemProcurement::STATUS_CUSTOMER_WAITING => 'Müşteri ürünü bekleniyor',
                    default => 'Tedarik kontrol edilmeli',
                },
                'status' => $procurement->safeStatusLabel(),
                'cta_label' => 'Tedarik Aç',
                'cta_url' => route('admin.procurements.show', $procurement),
                'bucket' => 'today',
                'bucket_label' => 'Bugün',
                'bucket_tone' => 'amber',
                'priority' => 50,
                'sort_at' => optional($procurement->updated_at)->timestamp ?? 0,
            ]);
    }

    private function buildProductionQueueItems(Collection $pendingProductions): Collection
    {
        return $pendingProductions
            ->map(function (OrderItemPrintProduction $production): array {
                $readiness = $this->productionReadinessResolver->resolve($production);

                return [
                    'kind' => 'Üretim',
                    'document_number' => ($production->order?->document_number ?: '-') . ' / ' . (($production->production_snapshot['print_sequence'] ?? null) ?: '-'),
                    'customer_name' => $production->order?->customer?->legal_name ?: '-',
                    'summary' => $readiness['blocking_reason_label'] ?: 'Üretime hazır',
                    'status' => $readiness['readiness_label'] ?? $production->safeStatusLabel(),
                    'cta_label' => 'Üretim Aç',
                    'cta_url' => route('admin.productions.show', $production),
                    'bucket' => $readiness['can_start'] ? 'today' : 'blocked',
                    'bucket_label' => $readiness['can_start'] ? 'Bugün' : 'Bloklu',
                    'bucket_tone' => $readiness['can_start'] ? 'green' : 'red',
                    'priority' => $readiness['can_start'] ? 70 : 60,
                    'sort_at' => optional($production->updated_at)->timestamp ?? 0,
                ];
            })
            ->sortBy([
                ['priority', 'asc'],
                ['sort_at', 'desc'],
            ])
            ->take(3)
            ->values();
    }

    private function buildDeliveryQueueItems(TenantAccount $tenant): Collection
    {
        return OrderItemWorkFormDelivery::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereNotIn('delivery_status', [
                OrderItemWorkFormDelivery::STATUS_DELIVERED,
                OrderItemWorkFormDelivery::STATUS_CANCELLED,
            ])
            ->with([
                'order.customer:id,legal_name',
                'workForm:id,work_form_number',
            ])
            ->latest('updated_at')
            ->take(3)
            ->get()
            ->map(fn (OrderItemWorkFormDelivery $delivery) => [
                'kind' => 'Teslimat',
                'document_number' => $delivery->order?->document_number ?: '-',
                'customer_name' => $delivery->order?->customer?->legal_name ?: '-',
                'summary' => match ($delivery->delivery_status) {
                    OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED => 'Kalan teslimat tamamlanmalı',
                    OrderItemWorkFormDelivery::STATUS_READY => 'Sevkiyata hazır',
                    OrderItemWorkFormDelivery::STATUS_SHIPPED,
                    OrderItemWorkFormDelivery::STATUS_COURIER_OUT => 'Teslimat takibi sürüyor',
                    default => 'Teslimat bekliyor',
                },
                'status' => $delivery->safeStatusLabel(),
                'cta_label' => 'Teslimata Git',
                'cta_url' => route('admin.deliveries.show', $delivery),
                'bucket' => 'today',
                'bucket_label' => 'Bugün',
                'bucket_tone' => 'green',
                'priority' => 80,
                'sort_at' => optional($delivery->updated_at)->timestamp ?? 0,
            ]);
    }

    private function quickLinks(TenantAccount $tenant): array
    {
        $links = [
            [
                'label' => 'Yeni Teklif',
                'url' => route('admin.promotion-quotes.create'),
            ],
            [
                'label' => 'Baskı Ayarları',
                'url' => route('admin.settings.print-settings.index'),
            ],
        ];

        if ($this->tenantAccessService->canAccessModule($tenant, 'notification_center')
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_logs', 'notification_center')) {
            $links[] = [
                'label' => 'Bildirim Logları',
                'url' => route('admin.notifications.logs.index'),
            ];
        }

        if ($this->tenantAccessService->canAccessModule($tenant, 'product_data_hub')) {
            $links[] = [
                'label' => 'Data Hub Kontrol',
                'url' => route('admin.product-data-hub.index'),
            ];
        }

        return $links;
    }
}
