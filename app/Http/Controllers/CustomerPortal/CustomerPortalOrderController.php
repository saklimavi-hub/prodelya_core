<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Services\CustomerPortalAccessService;
use App\Services\CustomerPortalFileDataBuilder;
use App\Services\CustomerPortalOrderDataBuilder;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CustomerPortalOrderController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $portalAccessService,
        protected CustomerPortalOrderDataBuilder $orderDataBuilder,
        protected CustomerPortalFileDataBuilder $fileDataBuilder,
    ) {
    }

    public function index(Request $request): View
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $search = trim((string) $request->query('q', ''));

        $orders = Order::query()
            ->with([
                'items:id,order_id,product_name',
                'workForms:id,tenant_account_id,order_id,status,public_tracking_token,delivery_snapshot,procurement_snapshot,production_snapshot,graphic_snapshot',
            ])
            ->where('tenant_account_id', $portalUser->scopeTenantId())
            ->where('customer_company_id', $portalUser->scopeCompanyId())
            ->orders()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('document_number', 'like', '%' . $search . '%')
                        ->orWhereHas('items', function ($itemQuery) use ($search) {
                            $itemQuery->where('product_name', 'like', '%' . $search . '%');
                        });
                });
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('customer-portal.orders.index', [
            'portalUser' => $portalUser,
            'company' => $portalUser->company,
            'tenant' => $tenant,
            'pageTitle' => 'Siparişlerim',
            'pageHeading' => 'Siparişlerim',
            'portalNav' => $this->portalNav($tenant),
            'search' => $search,
            'orders' => $orders->through(fn (Order $order) => $this->orderDataBuilder->buildListRow($order)),
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $order->loadMissing([
            'customer',
            'items.prints.orderItem',
            'items.prints.tenantPrintSetting',
            'workForms.order',
            'workForms.attachments',
        ]);

        if ($order->isQuote() || ! $portalUser->canSeeOrder($order)) {
            abort(404);
        }

        $trackingHelperUrls = $order->workForms
            ->filter(fn (OrderItemWorkForm $workForm) => filled($workForm->public_tracking_token) && $portalUser->canSeeWorkForm($workForm))
            ->mapWithKeys(fn (OrderItemWorkForm $workForm) => [
                $workForm->id => route('customer.portal.orders.tracking.open', [
                    'order' => $order->id,
                    'workForm' => $workForm->id,
                ]),
            ])
            ->all();

        $filesEnabled = $tenant ? $this->portalAccessService->portalVisibleFilesEnabled($tenant) : false;

        $visibleAttachments = $filesEnabled
            ? $order->workForms
                ->flatMap(function (OrderItemWorkForm $workForm) {
                    return $workForm->attachments
                        ->where('visibility', 'customer_visible')
                        ->values();
                })
                ->map(fn ($attachment) => $this->fileDataBuilder->buildOrderAttachmentRow(
                    $attachment,
                    route('customer.portal.files.show', $attachment->id)
                ))
                ->values()
                ->all()
            : [];

        return view('customer-portal.orders.show', [
            'portalUser' => $portalUser,
            'company' => $portalUser->company,
            'tenant' => $tenant,
            'pageTitle' => 'Siparişim',
            'pageHeading' => $order->document_number,
            'portalNav' => $this->portalNav($tenant),
            'orderDetail' => $this->orderDataBuilder->buildDetail($order, $trackingHelperUrls),
            'filesEnabled' => $filesEnabled,
            'visibleAttachments' => $visibleAttachments,
        ]);
    }

    public function openTracking(Request $request, Order $order, OrderItemWorkForm $workForm): RedirectResponse
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();

        $workForm->loadMissing('order');

        if (
            $order->isQuote()
            || ! $portalUser->canSeeOrder($order)
            || ! $portalUser->canSeeWorkForm($workForm)
            || (int) $workForm->order_id !== (int) $order->id
            || ! filled($workForm->public_tracking_token)
        ) {
            abort(404);
        }

        return redirect()->route('public.work-forms.track', ['token' => $workForm->public_tracking_token]);
    }

    private function portalNav($tenant): array
    {
        $quotesEnabled = $tenant ? $this->portalAccessService->portalQuotesEnabled($tenant) : false;
        $ordersEnabled = $tenant ? $this->portalAccessService->portalOrdersEnabled($tenant) : false;
        $filesEnabled = $tenant ? $this->portalAccessService->portalVisibleFilesEnabled($tenant) : false;

        return [
            'quotes_enabled' => $quotesEnabled,
            'orders_enabled' => $ordersEnabled,
            'files_enabled' => $filesEnabled,
            'active' => 'orders',
        ];
    }
}
