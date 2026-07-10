<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkForm;
use App\Models\OrderDeliveryLabelBatch;
use App\Models\Company;
use App\Models\TenantAccount;
use App\Services\DeliveryWorkflowService;
use App\Services\NumberGenerationService;
use App\Services\OrderQuoteDraftCloneService;
use App\Services\OrderDeliveryPlanningService;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\OrderListSummaryService;
use App\Services\OrderShowSummaryService;
use App\Services\Notifications\NotificationEventService;
use App\Services\TenantResolver;
use App\Services\UsageLimitGuardService;
use App\Services\WorkFormCreationService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class OrderController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected NumberGenerationService $numberGenerationService,
        protected WorkFormCreationService $workFormCreationService,
        protected OrderCurrentAccountDebitSyncService $orderCurrentAccountDebitSyncService,
        protected OrderListSummaryService $orderListSummaryService,
        protected OrderShowSummaryService $orderShowSummaryService,
        protected OrderDeliveryPlanningService $orderDeliveryPlanningService,
        protected DeliveryWorkflowService $deliveryWorkflowService,
        protected UsageLimitGuardService $usageLimitGuardService,
        protected NotificationEventService $notificationEventService,
        protected OrderQuoteDraftCloneService $orderQuoteDraftCloneService
    ) {
        // TODO: Add middleware for orders
        // $this->middleware('permission:manage_orders');
    }

    private function humanizeConversionException(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'no column named source_quote_number')) {
            return 'Siparise donusturme basarisiz oldu. Veritabani semasi guncel degil; orders.source_quote_number alani eksik. Migration calistirilmali.';
        }

        if (str_contains($message, 'no column named product_total')) {
            return 'Siparise donusturme basarisiz oldu. Veritabani semasi guncel degil; orders.product_total alani eksik. Migration calistirilmali.';
        }

        if (str_contains($message, 'no column named print_total')) {
            return 'Siparise donusturme basarisiz oldu. Veritabani semasi guncel degil; orders.print_total alani eksik. Migration calistirilmali.';
        }

        if (str_contains($message, 'no column named vat_breakdown_json')) {
            return 'Siparise donusturme basarisiz oldu. Veritabani semasi guncel degil; orders.vat_breakdown_json alani eksik. Migration calistirilmali.';
        }

        if ($exception instanceof QueryException) {
            return 'Siparise donusturme sirasinda veritabani kaynakli bir hata olustu.';
        }

        return 'Siparise donusturme sirasinda hata olustu.';
    }

    private function buildQuoteFinancialSnapshot(Order $quote): array
    {
        $productTotal = 0.0;
        $printTotal = 0.0;
        $vatBreakdown = [];

        foreach ($quote->items as $item) {
            $priceSnapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];

            $productTotal += (float) data_get($priceSnapshot, 'product_total', $item->line_total ?? 0);

            $itemPrintTotal = (float) data_get($priceSnapshot, 'print_total', $item->print_total ?? 0);
            if ($itemPrintTotal === 0.0 && $item->relationLoaded('prints')) {
                $itemPrintTotal = (float) $item->prints->sum('print_total');
            }
            $printTotal += $itemPrintTotal;

            foreach ((array) data_get($priceSnapshot, 'vat_breakdown', []) as $slice) {
                $rate = round((float) ($slice['rate'] ?? 0), 4);
                $scope = (string) ($slice['scope'] ?? 'general');
                $key = $rate . '|' . $scope;

                if (!isset($vatBreakdown[$key])) {
                    $vatBreakdown[$key] = [
                        'rate' => $rate,
                        'total' => 0.0,
                        'scope' => $scope,
                    ];
                }

                $vatBreakdown[$key]['total'] += (float) ($slice['total'] ?? 0);
            }
        }

        return [
            'product_total' => round($productTotal, 2),
            'print_total' => round($printTotal, 2),
            'vat_breakdown_json' => array_values(array_map(static function (array $slice): array {
                $slice['total'] = round((float) $slice['total'], 2);
                return $slice;
            }, $vatBreakdown)),
        ];
    }

    /**
     * Display a listing of orders
     */
    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant) {
            abort(403);
        }

        $user = $request->user();
        $canViewFinancialData = $user?->canViewFinancialData($tenant->id) ?? false;

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'customer_company_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'selected_order_id' => ['nullable', 'integer'],
        ]);

        $status = (string) ($validated['filter'] ?? $validated['status'] ?? 'open');
        if (!$canViewFinancialData && $status === 'payment_pending') {
            $status = 'open';
        }

        $query = Order::query()
            ->orders()
            ->where('tenant_account_id', $tenant->id)
            ->with([
                'customer:id,legal_name,name',
                'sourceQuote:id,document_number',
                'workForms:id,order_id,work_form_number,graphic_snapshot',
                'procurements:id,order_id,work_form_id,procurement_status,requires_procurement,fulfillment_source,remaining_quantity,received_quantity',
                'printProductions:id,order_id,work_form_id,production_status,qc_status,remaining_quantity,completed_quantity',
                'deliveries:id,order_id,work_form_id,delivery_status,remaining_quantity,delivered_quantity,financial_warning',
                'payments:id,order_id,amount,payment_type,payment_method,paid_at,due_date,cancelled_at',
            ])
            ->orderByDesc('created_at');

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($innerQuery) use ($search) {
                $innerQuery
                    ->where('document_number', 'like', '%' . $search . '%')
                    ->orWhere('source_quote_number', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where('legal_name', 'like', '%' . $search . '%')
                            ->orWhere('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if (!empty($validated['customer_company_id'])) {
            $query->where('customer_company_id', (int) $validated['customer_company_id']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $orders = $query->get();
        $allRows = $this->orderListSummaryService->buildRows($orders, $canViewFinancialData);
        $rows = $this->orderListSummaryService->filterRows($allRows, $status, $canViewFinancialData);
        $activeRows = $this->orderListSummaryService->filterRows($allRows, 'open', $canViewFinancialData);

        $customers = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('id', Order::query()
                ->orders()
                ->where('tenant_account_id', $tenant->id)
                ->whereNotNull('customer_company_id')
                ->select('customer_company_id'))
            ->orderBy('legal_name')
            ->get(['id', 'legal_name']);

        $statusOptions = collect([
            ['value' => 'open', 'label' => 'Aktif Siparişler'],
            ['value' => 'completed', 'label' => 'Tamamlanan Siparişler'],
            ['value' => 'all', 'label' => 'Tümü'],
            ['value' => 'in_operation', 'label' => 'Operasyonda'],
            ['value' => 'delivery_pending', 'label' => 'Teslimat Bekleyen'],
            ['value' => 'payment_pending', 'label' => 'Ödeme Bekleyen', 'requires_finance' => true],
            ['value' => 'problem', 'label' => 'İptal / Problemli'],
        ])->filter(fn (array $option) => $canViewFinancialData || !($option['requires_finance'] ?? false))->values();

        $summary = [
            'total' => $allRows->count(),
            'completed' => $allRows->where('is_completed', true)->count(),
            'open' => $activeRows->count(),
            'problem' => $allRows->filter(fn (array $row) => $row['is_cancelled'] || $row['is_problematic'])->count(),
        ];
        $tabCounts = [
            'open' => $this->orderListSummaryService->filterRows($allRows, 'open', $canViewFinancialData)->count(),
            'completed' => $this->orderListSummaryService->filterRows($allRows, 'completed', $canViewFinancialData)->count(),
            'all' => $allRows->count(),
            'in_operation' => $this->orderListSummaryService->filterRows($allRows, 'in_operation', $canViewFinancialData)->count(),
            'delivery_pending' => $this->orderListSummaryService->filterRows($allRows, 'delivery_pending', $canViewFinancialData)->count(),
            'payment_pending' => $canViewFinancialData
                ? $this->orderListSummaryService->filterRows($allRows, 'payment_pending', $canViewFinancialData)->count()
                : 0,
        ];

        $selectedOrderId = (int) ($validated['selected_order_id'] ?? 0);
        $selectedRow = $selectedOrderId > 0
            ? $rows->first(fn (array $row) => (int) $row['order']->id === $selectedOrderId)
            : null;

        if ($selectedRow === null) {
            $selectedRow = $rows->first();
            $selectedOrderId = $selectedRow ? (int) $selectedRow['order']->id : 0;
        }

        return view('admin.orders.index', [
            'rows' => $rows,
            'filters' => [
                'search' => $validated['search'] ?? '',
                'status' => $status,
                'filter' => $status,
                'customer_company_id' => $validated['customer_company_id'] ?? '',
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
                'selected_order_id' => $selectedOrderId ?: '',
            ],
            'customers' => $customers,
            'statusOptions' => $statusOptions,
            'canViewFinancialData' => $canViewFinancialData,
            'summary' => $summary,
            'selectedRow' => $selectedRow,
            'selectedOrderId' => $selectedOrderId,
            'queueRows' => $activeRows,
            'tabCounts' => $tabCounts,
        ]);
    }

    /**
     * Show the form for creating a new order
     */
    public function create(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        if (!$tenant) {
            abort(403);
        }

        return redirect()
            ->route('admin.orders.index')
            ->with('info', 'Sipariş oluşturma işlemi tekliften siparişe çevirme akışıyla yapılır.');
    }

    /**
     * Store a newly created order
     */
    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        if (!$tenant) {
            abort(403);
        }

        return redirect()
            ->route('admin.orders.index')
            ->withErrors(['order' => 'Manuel sipariş oluşturma bu aşamada aktif değildir. Siparişler tekliften siparişe çevirme akışıyla oluşturulur.']);
    }

    /**
     * Display the specified order
     */
    public function show(Request $request, Order $order): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $order->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        $order->loadMissing([
            'customer',
            'items.prints.subcontractorCompany',
            'items.prints.production',
            'items.procurement',
            'items.workForm',
            'items.delivery',
            'workForms',
            'workForms.activityLogs.creator',
            'procurements',
            'printProductions',
            'deliveries',
            'payments',
            'sourceQuote:id,document_number',
        ]);

        if ($this->orderDeliveryPlanningService->supportsPlanningStorage()) {
            $order->loadMissing([
                'items.deliveryPackageItems',
                'deliveryPackages.items.orderItem',
                'deliveryLabelBatches',
            ]);
        }

        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        if ($canViewFinancialData) {
            $this->orderCurrentAccountDebitSyncService->syncOrder(
                $order->fresh(['customer.companyRoles', 'payments']),
                $request->user()
            );
        }

        $screen = $this->orderShowSummaryService->build($order, $canViewFinancialData);
        $receivableDebitTransaction = $canViewFinancialData
            ? $this->orderCurrentAccountDebitSyncService->findExistingTransactionForOrder($order)
            : null;
        $customerCurrentAccount = $canViewFinancialData
            ? $this->orderCurrentAccountDebitSyncService->resolveCurrentAccountForOrder($order)
            : null;
        $orderTabs = [
            'genel' => 'Genel Özet',
            'is-formu' => 'İş Formu',
            'grafik' => 'Grafik',
            'tedarik' => 'Tedarik',
            'uretim' => 'Üretim',
            'teslimat' => 'Teslimat',
            'finans' => 'Finans',
            'gecmis' => 'Geçmiş',
        ];
        $requestedTab = (string) $request->query('tab', 'genel');
        $activeOrderTab = array_key_exists($requestedTab, $orderTabs) ? $requestedTab : 'genel';
        $deliveryTab = $this->orderDeliveryPlanningService->buildContext($order);

        return view('admin.orders.show', [
            'order' => $order,
            'tenant' => $tenant,
            'overview' => $screen['overview'],
            'moduleCards' => $screen['module_cards'],
            'itemRows' => $screen['item_rows'],
            'financialDataVisible' => $canViewFinancialData,
            'financeSummary' => $screen['finance'],
            'financeOverview' => $screen['finance_overview'],
            'receivableDebitTransaction' => $receivableDebitTransaction,
            'customerCurrentAccount' => $customerCurrentAccount,
            'orderTabs' => $orderTabs,
            'activeOrderTab' => $activeOrderTab,
            'deliveryTab' => $deliveryTab,
        ]);
    }

    public function createRevisionDraft(Request $request, Order $order): RedirectResponse
    {
        return $this->createCopiedQuoteDraft($request, $order, Order::COPY_TYPE_REVISION);
    }

    public function createRepeatOrderDraft(Request $request, Order $order): RedirectResponse
    {
        return $this->createCopiedQuoteDraft($request, $order, Order::COPY_TYPE_REPEAT_ORDER);
    }

    public function storeDeliveryPackages(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $order->tenant_account_id !== (int) $tenant->id || $order->document_type !== 'order') {
            abort(403);
        }

        $validated = $request->validate([
            'packages' => ['required', 'array', 'min:1'],
            'packages.*.package_label' => ['nullable', 'string', 'max:120'],
            'packages.*.package_type' => ['nullable', 'string', 'max:40'],
            'packages.*.notes' => ['nullable', 'string', 'max:500'],
            'packages.*.items' => ['required', 'array', 'min:1'],
            'packages.*.items.*.order_item_id' => ['required', 'integer'],
            'packages.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->orderDeliveryPlanningService->storePackages($order, $validated['packages'], $request->user());

        return redirect()
            ->to(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']))
            ->with('success', 'Koli planı kaydedildi.');
    }

    public function storeDeliveryLabels(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $order->tenant_account_id !== (int) $tenant->id || $order->document_type !== 'order') {
            abort(403);
        }

        $validated = $request->validate([
            'template_type' => ['required', 'string', 'max:40'],
            'roll_width_mm' => ['nullable', 'numeric', 'gt:0'],
            'roll_height_mm' => ['nullable', 'numeric', 'gt:0'],
            'roll_gap_mm' => ['nullable', 'numeric', 'gte:0'],
        ]);

        $this->orderDeliveryPlanningService->createLabelBatch($order, $validated, $request->user());

        return redirect()
            ->to(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']))
            ->with('success', 'Etiket partisi hazırlandı.');
    }

    public function updateDeliveryInfo(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $order->tenant_account_id !== (int) $tenant->id || $order->document_type !== 'order') {
            abort(403);
        }

        $validated = $request->validate([
            'delivery_type' => ['nullable', 'string', 'max:120'],
            'delivery_method' => ['nullable', 'string', 'max:40'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'delivery_document_no' => ['nullable', 'string', 'max:160'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'delivery_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->orderDeliveryPlanningService->updateDeliveryInfo($order, $validated, $request->user());

        return redirect()
            ->to(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']))
            ->with('success', 'Teslim bilgisi kaydedildi.');
    }

    public function completeDelivery(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $order->tenant_account_id !== (int) $tenant->id || $order->document_type !== 'order') {
            abort(403);
        }

        $validated = $request->validate([
            'delivery_method' => ['nullable', 'string', 'max:40'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'delivery_document_no' => ['nullable', 'string', 'max:160'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'delivery_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->orderDeliveryPlanningService->completeDelivery($order, $validated, $request->user());

        return redirect()
            ->to(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']))
            ->with('success', 'Teslimat tamamlandı. Sipariş operasyon akışından çıkarıldı.');
    }

    public function printDeliveryLabels(Request $request, Order $order): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $order->tenant_account_id !== (int) $tenant->id || $order->document_type !== 'order') {
            abort(403);
        }

        $batchId = (int) $request->query('batch', 0);
        $batch = $batchId > 0 && $this->orderDeliveryPlanningService->supportsPlanningStorage()
            ? OrderDeliveryLabelBatch::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('order_id', $order->id)
                ->whereKey($batchId)
                ->firstOrFail()
            : null;

        return view('admin.orders.delivery-labels-print', $this->orderDeliveryPlanningService->buildPrintData($order, $batch));
    }

    public function openTracking(Request $request, Order $order, OrderItemWorkForm $workForm): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || (int) $order->tenant_account_id !== (int) $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        $workForm->loadMissing('order');

        if (
            (int) $workForm->tenant_account_id !== (int) $tenant->id
            || (int) $workForm->order_id !== (int) $order->id
            || !filled($workForm->public_tracking_token)
        ) {
            abort(404);
        }

        return redirect()->route('public.work-forms.track', ['token' => $workForm->public_tracking_token]);
    }

    private function normalizeStatusLabel(mixed $status, string $fallback = 'Bekliyor'): string
    {
        $value = trim((string) ($status ?? ''));

        if ($value === '') {
            return $fallback;
        }

        $normalized = str_replace('_', ' ', $value);

        return ucfirst($normalized);
    }

    private function formatQuantity(mixed $quantity, ?string $unit = null): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    /**
     * Show the form for editing the specified order
     */
    public function edit(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        if (!$tenant || $order->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('info', 'Sipariş düzenleme ileride revizyon akışıyla yönetilecektir.');
    }

    /**
     * Update the specified order
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        if (!$tenant || $order->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->withErrors(['order' => 'Sipariş düzenleme bu aşamada aktif değildir. Değişiklikler ileride revizyon akışıyla yönetilecektir.']);
    }

    /**
     * Remove the specified order
     */
    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        if (!$tenant || $order->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        return redirect()
            ->route('admin.orders.index')
            ->withErrors(['order' => 'Sipariş silme bu aşamada aktif değildir.']);
    }

    /**
     * Convert quote to order
     */
    public function convertFromQuote(Request $request, Order $quote): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erisim yetkiniz yok.');
        }

        if (!$quote->isQuote()) {
            return redirect()
                ->route('admin.orders.index')
                ->withErrors(['error' => 'Bu kayıt teklif değil.']);
        }

        $this->usageLimitGuardService->assertCanCreate($tenant, 'orders');

        $existingOrder = $quote->convertedOrders()
            ->where('document_type', 'order')
            ->latest('id')
            ->first();

        if ($existingOrder) {
            return redirect()
                ->route('admin.orders.show', $existingOrder)
                ->with('info', 'Bu teklif daha önce siparişe dönüştürüldü.');
        }

        $quote->loadMissing(['items.prints']);

        DB::beginTransaction();

        try {
            $documentNumber = $this->numberGenerationService->generateNumber($tenant->id, 'order');
            $financialSnapshot = $this->buildQuoteFinancialSnapshot($quote);

            $order = Order::create([
                'tenant_account_id' => $quote->tenant_account_id,
                'order_family' => $quote->order_family,
                'order_mode' => $quote->order_mode,
                'document_type' => 'order',
                'document_number' => $documentNumber,
                'source_quote_id' => $quote->id,
                'source_quote_number' => $quote->document_number,
                'customer_company_id' => $quote->customer_company_id,
                'status' => 'pending',
                'workflow_status' => 'order_created',
                'quote_date' => $quote->quote_date,
                'valid_until' => $quote->valid_until,
                'invoice_status' => $quote->invoice_status,
                'delivery_type' => $quote->delivery_type,
                'delivery_type_id' => $quote->delivery_type_id,
                'show_print_price_details_to_customer' => $quote->shouldShowPrintPriceDetailsToCustomer(),
                'notes' => $quote->notes,
                'currency' => $quote->currency,
                'subtotal' => $quote->subtotal,
                'vat_total' => $quote->vat_total,
                'grand_total' => $quote->grand_total,
                'product_total' => $financialSnapshot['product_total'],
                'print_total' => $financialSnapshot['print_total'],
                'vat_breakdown_json' => $financialSnapshot['vat_breakdown_json'],
                'created_by' => Auth::id(),
            ]);

            foreach ($quote->items as $quoteItem) {
                $newItem = OrderItem::create([
                    'tenant_account_id' => $quoteItem->tenant_account_id,
                    'order_id' => $order->id,
                    'tenant_catalog_product_id' => $quoteItem->tenant_catalog_product_id,
                    'tenant_catalog_product_variant_id' => $quoteItem->tenant_catalog_product_variant_id,
                    'standard_product_id' => $quoteItem->standard_product_id,
                    'standard_product_variant_id' => $quoteItem->standard_product_variant_id,
                    'item_type' => $quoteItem->item_type,
                    'product_source' => $quoteItem->product_source,
                    'product_name' => $quoteItem->product_name,
                    'product_code' => $quoteItem->product_code,
                    'supplier_id' => $quoteItem->supplier_id,
                    'supplier_source_id' => $quoteItem->supplier_source_id,
                    'quantity' => $quoteItem->quantity,
                    'unit' => $quoteItem->unit,
                    'description' => $quoteItem->description,
                    'product_snapshot' => $quoteItem->product_snapshot,
                    'price_snapshot' => $quoteItem->price_snapshot,
                    'stock_snapshot' => $quoteItem->stock_snapshot,
                    'catalog_source' => $quoteItem->catalog_source,
                    'list_price' => $quoteItem->list_price,
                    'discount_rate' => $quoteItem->discount_rate,
                    'unit_price' => $quoteItem->unit_price,
                    'line_total' => $quoteItem->line_total,
                    'has_print' => $quoteItem->has_print,
                    'print_total' => $quoteItem->print_total,
                    'status' => $quoteItem->status ?? 'pending',
                ]);

            foreach ($quoteItem->prints as $quotePrint) {
                    OrderItemPrint::create([
                        'tenant_account_id' => $quotePrint->tenant_account_id,
                        'order_id' => $order->id,
                        'order_item_id' => $newItem->id,
                        'tenant_print_setting_id' => $quotePrint->tenant_print_setting_id,
                        'standard_print_type_id' => $quotePrint->standard_print_type_id,
                        'tenant_print_option_id' => $quotePrint->tenant_print_option_id,
                        'print_type' => $quotePrint->print_type,
                        'print_option' => $quotePrint->print_option,
                        'print_location' => $quotePrint->print_location,
                        'production_type' => $quotePrint->production_type,
                        'subcontractor_company_id' => $quotePrint->subcontractor_company_id,
                        'print_color' => $quotePrint->print_color,
                        'print_size' => $quotePrint->print_size,
                        'cliche_status' => $quotePrint->cliche_status,
                        'setup_pricing_enabled' => $quotePrint->setup_pricing_enabled,
                        'setup_type' => $quotePrint->setup_type,
                        'setup_status' => $quotePrint->setup_status,
                        'setup_total_amount' => $quotePrint->setup_total_amount,
                        'setup_distribution_quantity' => $quotePrint->setup_distribution_quantity,
                        'setup_unit_amount' => $quotePrint->setup_unit_amount,
                        'base_print_unit_price' => $quotePrint->base_print_unit_price,
                        'print_quantity' => $quotePrint->print_quantity,
                        'print_unit_price' => $quotePrint->print_unit_price,
                        'print_total' => $quotePrint->print_total,
                        'note' => $quotePrint->note,
                        'production_note' => $quotePrint->production_note,
                        'status' => $quotePrint->status ?? 'pending',
                    ]);
                }
            }

            $order->loadMissing([
                'customer.contacts',
                'customer.addresses',
                'items.prints.subcontractorCompany',
                'items.tenantCatalogProductVariant.catalogProduct',
                'items.tenantCatalogProduct',
                'items.legacySupplierCompany',
            ]);

            $this->workFormCreationService->createForOrder($order, Auth::user());
            $this->orderCurrentAccountDebitSyncService->syncOrder($order->fresh(['customer.companyRoles', 'payments']), Auth::user());

            $quote->update([
                'workflow_status' => 'quote_converted',
            ]);

            DB::commit();

            try {
                $this->notificationEventService->dispatchEvent(
                    $tenant,
                    'quote_converted_to_order',
                    $order->fresh(['customer.contacts', 'items', 'workForms']),
                    [
                        'audience_type' => 'tenant_admin',
                        'channels' => ['internal'],
                        'created_by' => $request->user(),
                        'related_type' => $order->getMorphClass(),
                        'related_id' => $order->id,
                        'context' => [
                            'status_label' => $order->fresh()->status,
                        ],
                    ]
                );
            } catch (\Throwable) {
                // Notification failures must not break quote conversion.
            }

            return redirect()
                ->route('admin.orders.show', $order)
                ->with('success', 'Teklif başarıyla siparişe dönüştürüldü.');
        } catch (\Throwable $exception) {
            DB::rollBack();

            \Log::error('Quote conversion failed', [
                'quote_id' => $quote->id,
                'tenant_id' => $tenant->id,
                'exception' => $exception,
            ]);

            return redirect()
                ->route('admin.promotion-quotes.show', $quote)
                ->withErrors(['error' => $this->humanizeConversionException($exception)]);
        }
    }

    private function createCopiedQuoteDraft(Request $request, Order $order, string $copyType): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $order->tenant_account_id !== (int) $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        if (! ($request->user()?->hasPermissionInTenant('create_quotes', $tenant->id) ?? false)) {
            abort(403);
        }

        $this->usageLimitGuardService->assertCanCreate($tenant, 'orders');

        $quote = $copyType === Order::COPY_TYPE_REVISION
            ? $this->orderQuoteDraftCloneService->createRevisionDraft($order, $request->user())
            : $this->orderQuoteDraftCloneService->createRepeatOrderDraft($order, $request->user());

        $successMessage = $copyType === Order::COPY_TYPE_REVISION
            ? ($quote->copyTypeLabel() . ' taslağı oluşturuldu. Orijinal sipariş değiştirilmedi.')
            : 'Tekrar sipariş için yeni teklif taslağı oluşturuldu. Eski sipariş değiştirilmedi.';

        return redirect()
            ->route('admin.promotion-quotes.edit', $quote)
            ->with('success', $successMessage);
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $order->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        if ($order->document_type !== 'order') {
            abort(404);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->withErrors(['order' => 'Sipariş durumunu bu ekrandan değiştirme akışı henüz aktif değildir. Operasyon durumları ilgili modül ekranlarından yönetilir.']);
    }

    /**
     * Get order statistics
     */
    private function getOrderStats(): array
    {
        // Demo statistics
        return [
            'total' => 156,
            'pending' => 23,
            'in_production' => 45,
            'ready_for_delivery' => 12,
            'completed' => 76,
        ];
    }

    private function getCustomers(): Collection
    {
        try {
            return Company::query()
                ->whereHas('companyRoles', function ($query) {
                    $query->where('role_key', 'customer');
                })
                ->limit(10)
                ->get();
        } catch (\Throwable $exception) {
            return collect();
        }
    }
}
