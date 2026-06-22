<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Services\CustomerPortalAccessService;
use App\Services\CustomerPortalQuoteDataBuilder;
use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CustomerPortalQuoteController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $portalAccessService,
        protected TenantAccessService $tenantAccessService,
        protected CustomerPortalQuoteDataBuilder $quoteDataBuilder,
    ) {
    }

    public function index(Request $request): View
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $search = trim((string) $request->query('q', ''));

        $quotes = Order::query()
            ->with(['items:id,order_id,product_name'])
            ->where('tenant_account_id', $portalUser->scopeTenantId())
            ->where('customer_company_id', $portalUser->scopeCompanyId())
            ->quotes()
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

        $approvalHelperEnabled = $tenant
            && $this->tenantAccessService->canAccessFeature($tenant, 'public_quote_approval', 'quote_customer_approval');

        return view('customer-portal.quotes.index', [
            'portalUser' => $portalUser,
            'company' => $portalUser->company,
            'tenant' => $tenant,
            'pageTitle' => 'Tekliflerim',
            'pageHeading' => 'Tekliflerim',
            'portalNav' => $this->portalNav($tenant),
            'approvalHelperEnabled' => $approvalHelperEnabled,
            'search' => $search,
            'quotes' => $quotes->through(fn (Order $quote) => $this->quoteDataBuilder->buildListRow($quote)),
        ]);
    }

    public function show(Request $request, Order $quote): View
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $quote->loadMissing([
            'customer',
            'items.prints.orderItem',
            'items.prints.tenantPrintSetting',
            'latestQuoteApprovalRequest',
        ]);

        if (! $quote->isQuote() || ! $portalUser->canSeeQuote($quote)) {
            abort(404);
        }

        $approvalHelperEnabled = $tenant
            && $this->tenantAccessService->canAccessFeature($tenant, 'public_quote_approval', 'quote_customer_approval');

        return view('customer-portal.quotes.show', [
            'portalUser' => $portalUser,
            'company' => $portalUser->company,
            'tenant' => $tenant,
            'pageTitle' => 'Teklifim',
            'pageHeading' => $quote->document_number,
            'portalNav' => $this->portalNav($tenant),
            'quoteDetail' => $this->quoteDataBuilder->buildDetail(
                $quote,
                $tenant,
                $approvalHelperEnabled,
                $approvalHelperEnabled ? route('customer.portal.quotes.approval.open', $quote) : null
            ),
        ]);
    }

    public function openApproval(Request $request, Order $quote): RedirectResponse
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $quote->loadMissing('latestQuoteApprovalRequest');

        if (! $quote->isQuote() || ! $portalUser->canSeeQuote($quote)) {
            abort(404);
        }

        if (! $tenant || ! $this->tenantAccessService->canAccessFeature($tenant, 'public_quote_approval', 'quote_customer_approval')) {
            abort(404);
        }

        $latestApprovalRequest = $quote->latestQuoteApprovalRequest;

        if (! $latestApprovalRequest || $latestApprovalRequest->isCancelled()) {
            abort(404);
        }

        return redirect()->route('public.quotes.approval.show', ['token' => $latestApprovalRequest->token]);
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
            'active' => 'quotes',
        ];
    }
}
