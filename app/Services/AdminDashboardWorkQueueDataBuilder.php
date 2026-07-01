<?php

namespace App\Services;

use App\Models\GraphicApprovalRequest;
use App\Models\CurrentAccount;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSetting;
use App\Models\UserRole;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class AdminDashboardWorkQueueDataBuilder
{
    public function __construct(
        protected TenantAccessService $tenantAccessService,
        protected ProductionReadinessResolver $productionReadinessResolver,
        protected TenantUsageService $tenantUsageService,
        protected TenantSubscriptionStatusService $subscriptionStatusService,
        protected TenantCompanyProfileService $tenantCompanyProfileService,
    ) {
    }

    public function build(TenantAccount $tenant, ?User $user = null): array
    {
        $cards = collect();
        $queueItems = collect();
        $activeOrderCount = Order::query()
            ->where('tenant_account_id', $tenant->id)
            ->orders()
            ->active()
            ->count();
        $operationalPendingTotal = 0;

        $quotesEnabled = $this->tenantAccessService->canAccessModule($tenant, 'order_flow');
        $graphicsEnabled = $this->tenantAccessService->canAccessModule($tenant, 'graphics');
        $graphicApprovalEnabled = $this->tenantAccessService->canAccessModule($tenant, 'graphic_customer_approval')
            && $this->tenantAccessService->canAccessFeature($tenant, 'public_graphic_approval', 'graphic_customer_approval');
        $procurementEnabled = $this->tenantAccessService->canAccessModule($tenant, 'procurement');
        $productionEnabled = $this->tenantAccessService->canAccessModule($tenant, 'production');
        $deliveryEnabled = $this->tenantAccessService->canAccessModule($tenant, 'delivery');
        $notificationEnabled = $this->tenantAccessService->canAccessModule($tenant, 'notification_center')
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_logs', 'notification_center');
        $subscription = $this->subscriptionStatusService->getStatus($tenant);
        $usageWarnings = collect($this->tenantUsageService->warningItems($tenant));
        $catalogSummary = $this->buildCatalogSummary($tenant);
        $readinessChecklist = $this->buildReadinessChecklist($tenant, $subscription, $quotesEnabled, $catalogSummary, $user);

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
            $graphicQueueQuery = OrderItemPrintGraphic::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('status', [
                    OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
                    OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
                ]);

            $this->applyOperationalContextScope($graphicQueueQuery);

            $graphicQueue = $graphicQueueQuery
                ->count();

            $operationalPendingTotal += $graphicQueue;

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
            $graphicApprovalQueueQuery = GraphicApprovalRequest::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('status', [
                    GraphicApprovalRequest::STATUS_WAITING,
                    GraphicApprovalRequest::STATUS_VIEWED,
                ]);

            $this->applyOperationalContextScope($graphicApprovalQueueQuery);

            $graphicApprovalQueue = $graphicApprovalQueueQuery
                ->count();

            $operationalPendingTotal += $graphicApprovalQueue;

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
            $procurementQueueQuery = OrderItemProcurement::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('procurement_status', [
                    OrderItemProcurement::STATUS_PENDING,
                    OrderItemProcurement::STATUS_REQUEST_CREATED,
                    OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                    OrderItemProcurement::STATUS_CUSTOMER_WAITING,
                ]);

            $this->applyOperationalContextScope($procurementQueueQuery);

            $procurementQueue = $procurementQueueQuery
                ->count();

            $operationalPendingTotal += $procurementQueue;

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
            $pendingProductionsQuery = OrderItemPrintProduction::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('production_status', OrderItemPrintProduction::STATUS_PENDING);

            $this->applyOperationalContextScope($pendingProductionsQuery);

            $pendingProductions = $pendingProductionsQuery
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

            $operationalPendingTotal += $pendingProductions->count();

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
            $deliveryQueueQuery = OrderItemWorkFormDelivery::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereNotIn('delivery_status', [
                    OrderItemWorkFormDelivery::STATUS_DELIVERED,
                    OrderItemWorkFormDelivery::STATUS_CANCELLED,
                ]);

            $this->applyOperationalContextScope($deliveryQueueQuery);

            $deliveryQueue = $deliveryQueueQuery
                ->count();

            $operationalPendingTotal += $deliveryQueue;

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
            'today_actions' => $this->buildTodayActions($cards, $tenant, $user),
            'quick_start' => $this->buildQuickStart($tenant, $quotesEnabled, $catalogSummary, $user),
            'readiness_checklist' => $readinessChecklist,
            'package_summary' => $this->buildPackageSummary($tenant, $subscription, $usageWarnings, $user),
            'catalog_summary' => $catalogSummary,
            'tenant_summary' => [
                'name' => $tenant->name,
                'locale' => $tenant->default_locale,
                'currency' => $tenant->default_currency,
            ],
            'queue_summary' => [
                'active_orders' => $activeOrderCount,
                'pending_actions_total' => $operationalPendingTotal,
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

        return collect($waiting)->concat($convertible)->values();
    }

    private function buildGraphicQueueItems(TenantAccount $tenant): Collection
    {
        $query = OrderItemPrintGraphic::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('status', [
                OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
                OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
            ]);

        $this->applyOperationalContextScope($query);

        return $query
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
        $query = GraphicApprovalRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('status', [
                GraphicApprovalRequest::STATUS_WAITING,
                GraphicApprovalRequest::STATUS_VIEWED,
            ]);

        $this->applyOperationalContextScope($query);

        return $query
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
        $query = OrderItemProcurement::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('procurement_status', [
                OrderItemProcurement::STATUS_PENDING,
                OrderItemProcurement::STATUS_REQUEST_CREATED,
                OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                OrderItemProcurement::STATUS_CUSTOMER_WAITING,
            ]);

        $this->applyOperationalContextScope($query);

        return $query
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
        $query = OrderItemWorkFormDelivery::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereNotIn('delivery_status', [
                OrderItemWorkFormDelivery::STATUS_DELIVERED,
                OrderItemWorkFormDelivery::STATUS_CANCELLED,
            ]);

        $this->applyOperationalContextScope($query);

        return $query
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

    private function applyOperationalContextScope(Builder $query): void
    {
        $query->where(function (Builder $contextQuery): void {
            $contextQuery->whereHas('order', fn (Builder $orderQuery) => $this->applyActiveOperationalOrderScope($orderQuery))
                ->orWhereHas('workForm', function (Builder $workFormQuery): void {
                    $workFormQuery->where('status', 'active')
                        ->whereHas('order', fn (Builder $orderQuery) => $this->applyActiveOperationalOrderScope($orderQuery));
                });
        });
    }

    private function applyActiveOperationalOrderScope(Builder $query): void
    {
        $query->orders()
            ->active();
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

        if ($this->tenantAccessService->canAccessModule($tenant, 'advanced_catalog')
            && $this->tenantAccessService->canAccessFeature($tenant, 'product_variants', 'advanced_catalog')) {
            $links[] = [
                'label' => 'Kataloğu Aç',
                'url' => route('admin.catalog.index'),
            ];
        }

        if ($this->tenantAccessService->canAccessModule($tenant, 'notification_center')
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_logs', 'notification_center')) {
            $links[] = [
                'label' => 'Bildirim Logları',
                'url' => route('admin.notifications.logs.index'),
            ];
        }

        return $links;
    }

    private function buildTodayActions(Collection $cards, TenantAccount $tenant, ?User $user = null): array
    {
        $cardMap = $cards->keyBy('title');
        $actions = [
            [
                'title' => 'Bekleyen Teklifler',
                'count' => (int) ($cardMap['Onay Bekleyen Teklifler']['count'] ?? 0),
                'note' => 'Müşteri yanıtı bekleyen teklifleri takip edin.',
                'url' => route('admin.promotion-quotes.index', ['status' => 'waiting']),
                'tone' => 'blue',
            ],
            [
                'title' => 'Siparişe Dönüşecekler',
                'count' => (int) ($cardMap['Siparişe Çevrilebilir Teklifler']['count'] ?? 0),
                'note' => 'Onay almış teklifleri siparişe çevirin.',
                'url' => route('admin.promotion-quotes.index', ['status' => 'approved']),
                'tone' => 'green',
            ],
            [
                'title' => 'Grafik Bekleyen İşler',
                'count' => (int) ($cardMap['Grafik Bekleyen İşler']['count'] ?? 0),
                'note' => 'Görsel veya revize bekleyen baskıları kapatın.',
                'url' => route('admin.graphics.index'),
                'tone' => 'amber',
            ],
            [
                'title' => 'Tedarik Bekleyen İşler',
                'count' => (int) ($cardMap['Tedarik Bekleyen İşler']['count'] ?? 0),
                'note' => 'Talep ve tedarik kuyruğunu gözden geçirin.',
                'url' => route('admin.procurements.index', ['receipt_state' => 'bekliyor']),
                'tone' => 'amber',
            ],
            [
                'title' => 'Üretim Bekleyen İşler',
                'count' => (int) ($cardMap['Üretime Hazır / Bloklu Üretimler']['count'] ?? 0),
                'note' => 'Bloklu üretimleri ve setup eksiklerini açın.',
                'url' => route('admin.productions.index', ['pool' => 'preparation']),
                'tone' => 'red',
            ],
            [
                'title' => 'Teslimat Bekleyen İşler',
                'count' => (int) ($cardMap['Teslimat Bekleyen İşler']['count'] ?? 0),
                'note' => 'Sevkiyat ve teslim takibini tamamlayın.',
                'url' => route('admin.deliveries.index'),
                'tone' => 'green',
            ],
        ];

        if ($user && $user->hasAnyPermissionInTenant([
            'view_order_finance_summary',
            'view_payment_details',
            'manage_payments',
            'mark_payments_received',
        ], $tenant->id) && $this->tenantAccessService->canAccessModule($tenant, 'finance')) {
            $actions[] = [
                'title' => 'Tahsilat ve Finans',
                'count' => null,
                'note' => 'Yetkili kullanıcı olarak finans özetini kontrol edin.',
                'url' => route('admin.finance.index'),
                'tone' => 'purple',
            ];
        }

        return $actions;
    }

    private function buildQuickStart(TenantAccount $tenant, bool $quotesEnabled, array $catalogSummary, ?User $user = null): array
    {
        $customerReadiness = $this->customerAccountReadiness($tenant);
        $quoteCreationReadiness = $this->quoteCreationReadiness($tenant, $quotesEnabled, $catalogSummary, $user);
        $orderConversionReadiness = $this->orderConversionReadiness($tenant, $quotesEnabled);

        return [
            [
                'title' => 'Firma bilgilerini tamamla',
                'description' => 'Abone Firma adı, iletişim ve adres bilgilerini netleştirin.',
                'status' => $this->boolStatus($this->companyProfileReady($tenant), 'Eksik'),
                'url' => route('admin.settings.company-profile.edit'),
            ],
            [
                'title' => 'Müşteri / Cari ekle',
                'description' => 'İlk tekliften önce en az bir müşteri ve cari kart açın.',
                'status' => $customerReadiness,
                'url' => route('admin.current-accounts.index'),
            ],
            [
                'title' => 'Katalog durumunu kontrol et',
                'description' => 'Teklifte kullanılabilir ürün sayısını gözden geçirin.',
                'status' => $catalogSummary['status_label'],
                'url' => route('admin.catalog.index'),
            ],
            [
                'title' => 'İlk teklifini oluştur',
                'description' => 'Teklif akışını müşteri ve katalog seçimiyle başlatın.',
                'status' => $quoteCreationReadiness,
                'url' => route('admin.promotion-quotes.create'),
            ],
            [
                'title' => 'Müşteriye gönder ve onay al',
                'description' => 'Teklif onayı ve müşteri yanıtı akışını kullanın.',
                'status' => $quotesEnabled ? 'Hazır' : 'Pakette Yok',
                'url' => route('admin.promotion-quotes.index'),
            ],
            [
                'title' => 'Siparişe çevir',
                'description' => 'Onaylanan teklifleri sipariş ve operasyona taşıyın.',
                'status' => $orderConversionReadiness,
                'url' => route('admin.orders.index'),
            ],
        ];
    }

    private function buildReadinessChecklist(
        TenantAccount $tenant,
        array $subscription,
        bool $quotesEnabled,
        array $catalogSummary,
        ?User $user = null
    ): array {
        $settings = $tenant->settings()->pluck('value', 'key')->toArray();
        $panelReady = filled($tenant->panel_subdomain);
        $portalModuleEnabled = $this->tenantAccessService->canAccessModule($tenant, 'customer_portal');
        $portalEnabled = (bool) TenantSetting::getValue($tenant->id, 'portal_enabled', false);
        $smtpReady = filled($settings['smtp_from_email'] ?? null) || filled($settings['smtp_host'] ?? null);
        $whatsappReady = filled($settings['whatsapp_test_phone'] ?? null) || filled($settings['whatsapp_default_signature'] ?? null);
        $userCount = UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->distinct('user_id')
            ->count('user_id');
        $customerReadiness = $this->customerAccountReadiness($tenant);
        $quoteCreationReadiness = $this->quoteCreationReadiness($tenant, $quotesEnabled, $catalogSummary, $user);

        return [
            $this->readinessItem('Firma bilgileri', $this->companyProfileReady($tenant) ? 'Hazır' : 'Eksik', route('admin.settings.company-profile.edit')),
            $this->readinessItem('Panel adresi', $panelReady ? 'Hazır' : 'Eksik', route('admin.settings')),
            $this->readinessItem('Paket durumu', ($subscription['is_active'] || $subscription['is_trial']) ? 'Hazır' : 'Kontrol Gerekir', Route::has('admin.my-package.index') ? route('admin.my-package.index') : route('admin.settings')),
            $this->readinessItem('Müşteri / Cari', $customerReadiness, route('admin.current-accounts.index')),
            $this->readinessItem('Katalog / Product Data Hub', $catalogSummary['status_label'], route('admin.catalog.index')),
            $this->readinessItem('Teklif Oluşturma', $quoteCreationReadiness, route('admin.promotion-quotes.create')),
            $this->readinessItem('Müşteri portalı', $portalEnabled ? 'Hazır' : ($portalModuleEnabled ? 'Kontrol Gerekir' : 'Pakette Yok'), route('admin.settings')),
            $this->readinessItem('SMTP ayarı', $this->tenantAccessService->canAccessFeature($tenant, 'smtp_settings', 'notification_center') ? ($smtpReady ? 'Hazır' : 'Kontrol Gerekir') : 'Pakette Yok', Route::has('admin.settings.notifications.smtp') ? route('admin.settings.notifications.smtp') : route('admin.settings')),
            $this->readinessItem('WhatsApp link ayarı', $this->tenantAccessService->canAccessFeature($tenant, 'whatsapp_links', 'notification_center') ? ($whatsappReady ? 'Hazır' : 'Kontrol Gerekir') : 'Pakette Yok', Route::has('admin.settings.notifications.whatsapp') ? route('admin.settings.notifications.whatsapp') : route('admin.settings')),
            $this->readinessItem('Kullanıcı ve roller', $userCount > 1 ? 'Hazır' : 'Kontrol Gerekir', route('admin.users.index')),
        ];
    }

    private function buildPackageSummary(TenantAccount $tenant, array $subscription, Collection $usageWarnings, ?User $user = null): array
    {
        $canManagePackage = $user && $user->hasPermissionInTenant('manage_users', $tenant->id);

        return [
            'package_label' => (string) Str::of((string) ($tenant->package_key ?: 'core'))->replace(['_', '-'], ' ')->headline(),
            'subscription_label' => $subscription['label'] ?? 'Bilinmiyor',
            'warning_label' => $subscription['warning_label'] ?? null,
            'usage_warning_count' => $usageWarnings->count(),
            'usage_items' => $usageWarnings->take(3)->map(fn (array $item) => [
                'label' => $item['label'],
                'status' => $item['status'] === 'exceeded' ? 'Limit aşıldı' : 'Kontrol Gerekir',
            ])->values()->all(),
            'package_url' => $canManagePackage && Route::has('admin.my-package.index') ? route('admin.my-package.index') : null,
            'request_url' => $canManagePackage && Route::has('admin.upgrade-requests.index') ? route('admin.upgrade-requests.index') : null,
        ];
    }

    private function buildCatalogSummary(TenantAccount $tenant): array
    {
        $flatVisibleProducts = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('visible_in_catalog', true)
            ->where(function (Builder $query): void {
                $query->whereNull('meta')
                    ->orWhere(function (Builder $inner): void {
                        $inner->where('meta', 'not like', '%"is_parent":true%')
                            ->where('meta', 'not like', '%"is_parent": true%')
                            ->where('meta', 'not like', '%"is_sellable":false%')
                            ->where('meta', 'not like', '%"is_sellable": false%');
                    });
            })
            ->whereDoesntHave('variants', function (Builder $query): void {
                $query->where('is_active', true)
                    ->where('visible_in_catalog', true);
            });

        $variantVisibleRows = DB::table('tenant_catalog_product_variants as tcpv')
            ->join('tenant_catalog_products as tcp', 'tcp.id', '=', 'tcpv.tenant_catalog_product_id')
            ->where('tcp.tenant_account_id', $tenant->id)
            ->where('tcpv.tenant_account_id', $tenant->id)
            ->where('tcp.is_active', true)
            ->where('tcp.visible_in_catalog', true)
            ->where('tcpv.is_active', true)
            ->where('tcpv.visible_in_catalog', true);

        $flatWarningQuery = (clone $flatVisibleProducts)
            ->whereRaw($this->catalogAttentionSql(
                'tenant_catalog_products.meta',
                'tenant_catalog_products.standard_category_id',
                'tenant_catalog_products.display_price',
                $this->catalogEffectiveStockSql('tenant_catalog_products')
            ));

        $variantWarningQuery = (clone $variantVisibleRows)
            ->whereRaw($this->catalogAttentionSql(
                'COALESCE(tcpv.meta, tcp.meta)',
                'tcp.standard_category_id',
                'COALESCE(tcpv.display_price, tcp.display_price)',
                $this->catalogVariantEffectiveStockSql()
            ));

        $total = (clone $flatVisibleProducts)->count() + (clone $variantVisibleRows)->count();
        $quoteReady = (clone $flatVisibleProducts)->where('visible_in_quote', true)->count()
            + (clone $variantVisibleRows)->whereRaw($this->variantQuoteVisibleSql() . ' = 1')->count();
        $needsReview = (clone $flatWarningQuery)->count() + (clone $variantWarningQuery)->count();
        $supplierFeeds = $tenant->supplierAccesses()->active()->count();
        $moduleEnabled = $this->tenantAccessService->canAccessModule($tenant, 'advanced_catalog');

        $statusLabel = ! $moduleEnabled
            ? 'Pakette Yok'
            : ($quoteReady > 0 ? 'Hazır' : ($total > 0 || $supplierFeeds > 0 ? 'Kontrol Gerekir' : 'Sonraki Faz'));

        return [
            'status_label' => $statusLabel,
            'total_products' => $total,
            'quote_ready_products' => $quoteReady,
            'needs_review_count' => $needsReview,
            'supplier_feed_count' => $supplierFeeds,
            'count_basis' => 'sellable_rows',
            'catalog_url' => $moduleEnabled ? route('admin.catalog.index') : null,
            'quote_url' => $this->tenantAccessService->canAccessModule($tenant, 'order_flow') ? route('admin.promotion-quotes.create') : null,
        ];
    }

    private function catalogEffectiveStockSql(string $table): string
    {
        return "CASE WHEN COALESCE({$table}.local_stock_priority, 1) = 1 AND COALESCE({$table}.local_stock_quantity, 0) > 0 THEN COALESCE({$table}.local_stock_quantity, 0) WHEN COALESCE({$table}.supplier_stock_quantity, 0) > 0 THEN COALESCE({$table}.supplier_stock_quantity, 0) ELSE COALESCE({$table}.stock_quantity, 0) END";
    }

    private function catalogVariantEffectiveStockSql(): string
    {
        return "CASE WHEN COALESCE(tcp.local_stock_priority, 1) = 1 AND COALESCE(tcpv.local_stock_quantity, 0) > 0 THEN COALESCE(tcpv.local_stock_quantity, 0) WHEN COALESCE(tcpv.supplier_stock_quantity, 0) > 0 THEN COALESCE(tcpv.supplier_stock_quantity, 0) ELSE COALESCE(tcpv.stock_quantity, 0) END";
    }

    private function variantQuoteVisibleSql(): string
    {
        return "CASE WHEN COALESCE(tcpv.meta, '') LIKE '%\"quote_search_visible\":true%' OR COALESCE(tcpv.meta, '') LIKE '%\"quote_search_visible\": true%' THEN 1 WHEN COALESCE(tcpv.meta, '') LIKE '%\"quote_search_visible\":false%' OR COALESCE(tcpv.meta, '') LIKE '%\"quote_search_visible\": false%' THEN 0 ELSE COALESCE(tcp.visible_in_quote, 0) END";
    }

    private function catalogAttentionSql(
        string $metaColumn,
        string $categoryColumn,
        string $priceColumn,
        string $effectiveStockSql
    ): string {
        $flagSql = implode(' OR ', [
            $this->jsonTrueSql($metaColumn, 'supplier_warning_flag'),
            $this->jsonTrueSql($metaColumn, 'net_price_warning'),
            $this->jsonTrueSql($metaColumn, 'warning_sellable'),
            $this->jsonTrueSql($metaColumn, 'warning_flag'),
            $this->jsonTrueSql($metaColumn, 'price_warning'),
            $this->jsonTrueSql($metaColumn, 'stock_warning'),
            $this->jsonTrueSql($metaColumn, 'review_flag'),
            $this->jsonTrueSql($metaColumn, 'attention_flag'),
        ]);

        $categoryAttentionSql = implode(' OR ', [
            "{$categoryColumn} IS NULL",
            "(" . $this->jsonTrueSql($metaColumn, 'category_missing_warning') . " AND {$categoryColumn} IS NULL)",
            "({$metaColumn} LIKE '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%' AND {$categoryColumn} IS NULL)",
            "({$metaColumn} LIKE '%\"category_status\":\"unmapped\"%' AND {$categoryColumn} IS NULL)",
            "({$metaColumn} LIKE '%\"category_status\": \"unmapped\"%' AND {$categoryColumn} IS NULL)",
        ]);

        return '(' . implode(' OR ', [
            "{$priceColumn} IS NULL",
            $categoryAttentionSql,
            $flagSql,
            "{$effectiveStockSql} <= 0",
        ]) . ')';
    }

    private function jsonTrueSql(string $column, string $key): string
    {
        return "({$column} LIKE '%\"{$key}\":true%' OR {$column} LIKE '%\"{$key}\": true%')";
    }

    private function customerAccountReadiness(TenantAccount $tenant): string
    {
        $activeCompanies = $tenant->companies()->active()->count();
        $activeCurrentAccounts = $tenant->currentAccounts()
            ->where('status', CurrentAccount::STATUS_ACTIVE)
            ->count();

        if ($activeCompanies > 0 || $activeCurrentAccounts > 0) {
            return 'Hazır';
        }

        return 'Eksik';
    }

    private function quoteCreationReadiness(
        TenantAccount $tenant,
        bool $quotesEnabled,
        array $catalogSummary,
        ?User $user = null
    ): string {
        if (! $quotesEnabled) {
            return 'Pakette Yok';
        }

        if ($this->customerAccountReadiness($tenant) !== 'Hazır') {
            return 'Eksik';
        }

        if (! Route::has('admin.promotion-quotes.create')) {
            return 'Kontrol Gerekir';
        }

        if (($catalogSummary['quote_ready_products'] ?? 0) <= 0) {
            return 'Kontrol Gerekir';
        }

        return 'Hazır';
    }

    private function orderConversionReadiness(TenantAccount $tenant, bool $quotesEnabled): string
    {
        if (! $quotesEnabled) {
            return 'Pakette Yok';
        }

        $convertibleQuotes = Order::query()
            ->where('tenant_account_id', $tenant->id)
            ->quotes()
            ->where(function (Builder $query): void {
                $query->where('customer_approval_status', Order::CUSTOMER_APPROVAL_APPROVED)
                    ->orWhere('status', 'approved');
            })
            ->where('workflow_status', '!=', 'quote_converted')
            ->whereDoesntHave('convertedOrders')
            ->count();

        return $convertibleQuotes > 0 ? 'Hazır' : 'Kontrol Gerekir';
    }

    private function readinessItem(string $label, string $status, string $url): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'url' => $url,
        ];
    }

    private function companyProfileReady(TenantAccount $tenant): bool
    {
        $profile = $this->tenantCompanyProfileService->getProfile($tenant);

        return filled($profile['display_name'] ?? null)
            && (filled($profile['email'] ?? null) || filled($profile['phone'] ?? null));
    }

    private function boolStatus(bool $ready, string $fallback = 'Kontrol Gerekir'): string
    {
        return $ready ? 'Hazır' : $fallback;
    }
}
