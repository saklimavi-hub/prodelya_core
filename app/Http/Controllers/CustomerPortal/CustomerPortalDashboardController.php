<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Services\CustomerPortalAccessService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerPortalDashboardController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $portalAccessService
    ) {
    }

    public function index(Request $request): View
    {
        /** @var CustomerPortalUser $user */
        $user = Auth::guard('customer_portal')->user();
        /** @var TenantAccount|null $tenant */
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $company = $user->company;
        $quotesEnabled = $tenant ? $this->portalAccessService->portalQuotesEnabled($tenant) : false;
        $ordersEnabled = $tenant ? $this->portalAccessService->portalOrdersEnabled($tenant) : false;
        $filesEnabled = $tenant ? $this->portalAccessService->portalVisibleFilesEnabled($tenant) : false;

        $quoteBaseQuery = Order::query()
            ->where('tenant_account_id', $user->scopeTenantId())
            ->where('customer_company_id', $user->scopeCompanyId())
            ->quotes();

        $orderBaseQuery = Order::query()
            ->where('tenant_account_id', $user->scopeTenantId())
            ->where('customer_company_id', $user->scopeCompanyId())
            ->orders();

        $trackingBaseQuery = OrderItemWorkForm::query()
            ->where('tenant_account_id', $user->scopeTenantId())
            ->whereHas('order', function ($query) use ($user) {
                $query->where('customer_company_id', $user->scopeCompanyId());
            });

        $dashboard = [
            'counts' => [
                'open_quotes' => $quotesEnabled
                    ? (clone $quoteBaseQuery)
                        ->whereNotIn('customer_approval_status', [
                            Order::CUSTOMER_APPROVAL_APPROVED,
                            Order::CUSTOMER_APPROVAL_REJECTED,
                            Order::CUSTOMER_APPROVAL_CANCELLED,
                            Order::CUSTOMER_APPROVAL_EXPIRED,
                        ])
                        ->count()
                    : null,
                'pending_quotes' => $quotesEnabled
                    ? (clone $quoteBaseQuery)
                        ->whereIn('customer_approval_status', [
                            Order::CUSTOMER_APPROVAL_WAITING,
                            'viewed',
                        ])
                        ->count()
                    : null,
                'active_orders' => $ordersEnabled
                    ? (clone $orderBaseQuery)
                        ->whereNotIn('status', ['cancelled', 'completed'])
                        ->count()
                    : null,
                'delivery_waiting' => $ordersEnabled
                    ? (clone $trackingBaseQuery)
                        ->where(function ($query) {
                            $query
                                ->whereNull('delivery_snapshot')
                                ->orWhere('delivery_snapshot', 'like', '%teslimat_bekliyor%')
                                ->orWhere('delivery_snapshot', 'like', '%yolda%')
                                ->orWhere('delivery_snapshot', 'like', '%hazirlaniyor%');
                        })
                        ->count()
                    : null,
                'customer_visible_files' => OrderItemWorkFormAttachment::query()
                    ->where('tenant_account_id', $user->scopeTenantId())
                    ->where('visibility', 'customer_visible')
                    ->whereHas('order', function ($query) use ($user) {
                        $query->where('customer_company_id', $user->scopeCompanyId());
                    })
                    ->count(),
                'tracking_ready' => (clone $trackingBaseQuery)
                    ->whereNotNull('public_tracking_token')
                    ->count(),
            ],
            'recent_quotes' => $quotesEnabled
                ? (clone $quoteBaseQuery)
                    ->with(['items' => fn ($query) => $query->select('id', 'order_id', 'product_name')])
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Order $quote) => [
                        'id' => $quote->id,
                        'document_number' => $quote->document_number,
                        'date' => optional($quote->quote_date)->format('d.m.Y') ?: '-',
                        'status_label' => $quote->quoteDisplayStatusLabel(),
                        'approval_status_label' => $quote->safeCustomerApprovalStatusLabel(),
                        'valid_until' => optional($quote->valid_until)->format('d.m.Y') ?: '-',
                        'product_summary' => $this->productSummary($quote),
                        'grand_total' => number_format((float) $quote->grand_total, 2, ',', '.') . ' ' . ($quote->currency ?: 'TL'),
                    ])
                : collect(),
            'recent_orders' => $ordersEnabled
                ? (clone $orderBaseQuery)
                    ->with(['items' => fn ($query) => $query->select('id', 'order_id', 'product_name')])
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Order $order) => [
                        'id' => $order->id,
                        'document_number' => $order->document_number,
                        'date' => optional($order->created_at)->format('d.m.Y') ?: '-',
                        'status_label' => $this->humanizeStatus((string) $order->status),
                        'product_summary' => $this->productSummary($order),
                        'delivery_status_label' => $this->humanizeStatus((string) $order->status) === 'Completed'
                            ? 'Teslim edildi'
                            : 'Sipariş takibi açık',
                    ])
                : collect(),
            'recent_tracking_links' => (clone $trackingBaseQuery)
                ->with('order:id,document_number')
                ->latest('id')
                ->limit(5)
                ->get()
                ->filter(fn (OrderItemWorkForm $workForm) => filled($workForm->public_tracking_token))
                ->map(fn (OrderItemWorkForm $workForm) => [
                    'work_form_number' => $workForm->work_form_number,
                    'order_number' => $workForm->order?->document_number ?: data_get($workForm->order_snapshot, 'document_number', '-'),
                    'product_name' => data_get($workForm->product_snapshot, 'product_name', '-'),
                    'status_label' => data_get($workForm->delivery_snapshot, 'public_status_label')
                        ?: data_get($workForm->production_snapshot, 'public_status_label')
                        ?: data_get($workForm->procurement_snapshot, 'public_status_label')
                        ?: 'Takip edilebilir',
                    'tracking_url' => $ordersEnabled
                        ? route('customer.portal.orders.tracking.open', [
                            'order' => $workForm->order_id,
                            'workForm' => $workForm->id,
                        ])
                        : null,
                ])
                ->values(),
            'recent_files' => $filesEnabled
                ? OrderItemWorkFormAttachment::query()
                    ->with(['order:id,document_number,customer_company_id', 'workForm:id,product_snapshot,work_form_number'])
                    ->where('tenant_account_id', $user->scopeTenantId())
                    ->where('visibility', 'customer_visible')
                    ->whereHas('order', function ($query) use ($user) {
                        $query->where('customer_company_id', $user->scopeCompanyId());
                    })
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (OrderItemWorkFormAttachment $attachment) => [
                        'file_name' => $attachment->file_name ?: 'Dosya',
                        'order_number' => $attachment->order?->document_number ?: '-',
                        'work_form_number' => $attachment->workForm?->work_form_number ?: '-',
                        'created_at' => optional($attachment->created_at)->format('d.m.Y H:i') ?: '-',
                        'show_url' => route('customer.portal.files.show', $attachment->id),
                    ])
                : collect(),
        ];

        return view('customer-portal.dashboard', [
            'portalUser' => $user,
            'company' => $company,
            'tenant' => $tenant,
            'pageTitle' => 'Müşteri Portalı',
            'pageHeading' => 'Merhaba',
            'dashboard' => $dashboard,
            'portalNav' => [
                'quotes_enabled' => $quotesEnabled,
                'orders_enabled' => $ordersEnabled,
                'files_enabled' => $filesEnabled,
                'active' => 'dashboard',
            ],
            'sections' => [
                'quotes_enabled' => $quotesEnabled,
                'orders_enabled' => $ordersEnabled,
                'files_enabled' => $filesEnabled,
            ],
        ]);
    }

    private function productSummary(Order $order): string
    {
        $firstProductName = trim((string) $order->items->first()?->product_name);

        if ($firstProductName === '') {
            return 'Ürün özeti paylaşılacak.';
        }

        $remainingCount = max(0, $order->items->count() - 1);

        if ($remainingCount === 0) {
            return $firstProductName;
        }

        return $firstProductName . ' +' . $remainingCount . ' kalem';
    }

    private function humanizeStatus(string $status): string
    {
        $normalized = trim($status);

        if ($normalized === '') {
            return '-';
        }

        return Str::headline(str_replace('_', ' ', $normalized));
    }
}
