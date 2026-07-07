<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Services\FinanceSummaryService;
use App\Services\OrderFinanceSummaryService;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    private const FINANCE_PERMISSIONS = [
        'view_order_finance_summary',
        'view_payment_details',
        'manage_payments',
        'mark_payments_received',
    ];

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected FinanceSummaryService $financeSummaryService,
        protected OrderFinanceSummaryService $orderFinanceSummaryService,
        protected OrderCurrentAccountDebitSyncService $orderCurrentAccountDebitSyncService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->resolveAuthorizedTenant($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'payment_status' => ['nullable', 'string', 'max:60'],
            'invoice_status' => ['nullable', Rule::in(['fis', 'fatura'])],
            'currency' => ['nullable', Rule::in(['TL', 'USD', 'EUR'])],
            'delivery_warning' => ['nullable', Rule::in(array_keys(OrderItemWorkFormDelivery::financialWarningLabels()))],
            'limit' => ['nullable', Rule::in(['25', '50', '100', '250'])],
        ]);

        $orders = Order::query()
            ->orders()
            ->where('tenant_account_id', $tenant->id)
            ->with(['customer', 'payments'])
            ->latest('id')
            ->get();

        $rows = $orders->map(function (Order $order): array {
            return [
                'order' => $order,
                'summary' => $this->financeSummaryService->summarizeOrder($order),
            ];
        });

        $filtered = $this->applyFilters($rows, $filters);
        $limit = (int) ($filters['limit'] ?? 50);

        return view('admin.finance.index', [
            'tenant' => $tenant,
            'filters' => $filters,
            'rows' => $filtered->take($limit)->values(),
            'summaryCards' => $this->buildSummaryCards($filtered),
            'paymentStatusLabels' => $this->paymentStatusLabels(),
            'invoiceStatusLabels' => $this->invoiceStatusLabels(),
            'deliveryWarningLabels' => OrderItemWorkFormDelivery::financialWarningLabels(),
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        $tenant = $this->resolveAuthorizedTenant($request);

        if ($order->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $order->loadMissing([
            'customer',
            'creator',
            'payments.creator',
            'payments.updater',
            'workForms.delivery',
            'deliveries.workForm',
        ]);

        $this->orderCurrentAccountDebitSyncService->syncOrder(
            $order->fresh(['customer.companyRoles', 'payments']),
            $request->user()
        );

        $summary = $this->financeSummaryService->summarizeOrder($order);
        $financeOverview = $this->orderFinanceSummaryService->summarize($order->fresh([
            'customer.companyRoles',
            'payments',
            'procurements',
            'printProductions',
        ]));
        $workForm = $order->workForms->sortBy('id')->first();
        $delivery = $order->deliveries->sortBy('id')->first();
        $receivableDebitTransaction = $this->orderCurrentAccountDebitSyncService->findExistingTransactionForOrder($order);
        $customerCurrentAccount = $this->orderCurrentAccountDebitSyncService->resolveCurrentAccountForOrder($order);

        return view('admin.finance.show', [
            'order' => $order,
            'summary' => $summary,
            'financeOverview' => $financeOverview,
            'payments' => $order->payments->sortByDesc(function (OrderPayment $payment): int {
                return optional($payment->paid_at ?? $payment->created_at)?->getTimestamp() ?? 0;
            })->values(),
            'workForm' => $workForm,
            'delivery' => $delivery,
            'paymentTypeLabels' => OrderPayment::paymentTypeLabels(),
            'paymentMethodLabels' => OrderPayment::paymentMethodLabels(),
            'canManagePayments' => $request->user()?->hasPermissionInTenant('manage_payments', $tenant->id) ?? false,
            'canMarkPaymentsReceived' => $request->user()?->hasPermissionInTenant('mark_payments_received', $tenant->id) ?? false,
            'receivableDebitTransaction' => $receivableDebitTransaction,
            'customerCurrentAccount' => $customerCurrentAccount,
        ]);
    }

    private function resolveAuthorizedTenant(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $user = $request->user();

        abort_unless(
            $tenant && $user && $user->hasAnyPermissionInTenant(self::FINANCE_PERMISSIONS, $tenant->id),
            403
        );

        return $tenant;
    }

    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $paymentStatus = trim((string) ($filters['payment_status'] ?? ''));
        $invoiceStatus = trim((string) ($filters['invoice_status'] ?? ''));
        $currency = trim((string) ($filters['currency'] ?? ''));
        $deliveryWarning = trim((string) ($filters['delivery_warning'] ?? ''));

        return $rows->filter(function (array $row) use (
            $query,
            $paymentStatus,
            $invoiceStatus,
            $currency,
            $deliveryWarning
        ): bool {
            /** @var Order $order */
            $order = $row['order'];
            $summary = $row['summary'];

            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $order->document_number,
                    $order->source_quote_number,
                    $order->customer?->legal_name,
                ])));

                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            if ($paymentStatus !== '' && data_get($summary, 'payment_status') !== $paymentStatus) {
                return false;
            }

            if ($invoiceStatus !== '' && $order->invoice_status !== $invoiceStatus) {
                return false;
            }

            if ($currency !== '' && (string) ($order->currency ?: 'TL') !== $currency) {
                return false;
            }

            if ($deliveryWarning !== '' && data_get($summary, 'delivery_financial_warning') !== $deliveryWarning) {
                return false;
            }

            return true;
        })->values();
    }

    private function buildSummaryCards(Collection $rows): array
    {
        $singleCurrency = $this->singleCurrency($rows);
        $sumField = function (Collection $collection, string $field) use ($singleCurrency): ?string {
            if ($singleCurrency === null) {
                return null;
            }

            $amount = round($collection->sum(fn (array $row): float => (float) data_get($row, 'summary.' . $field, 0)), 2);

            return $this->formatMoney($amount, $singleCurrency);
        };

        return [
            [
                'label' => 'Ödeme Bekleyen',
                'count' => $rows->where('summary.payment_status', FinanceSummaryService::STATUS_PAYMENT_PENDING)->count(),
                'amount' => $sumField($rows->where('summary.payment_status', FinanceSummaryService::STATUS_PAYMENT_PENDING), 'balance_due'),
            ],
            [
                'label' => 'Kısmi Ödeme',
                'count' => $rows->where('summary.payment_status', FinanceSummaryService::STATUS_PARTIAL_PAYMENT)->count(),
                'amount' => $sumField($rows->where('summary.payment_status', FinanceSummaryService::STATUS_PARTIAL_PAYMENT), 'balance_due'),
            ],
            [
                'label' => 'Ödendi',
                'count' => $rows->where('summary.payment_status', FinanceSummaryService::STATUS_PAID)->count(),
                'amount' => $sumField($rows->where('summary.payment_status', FinanceSummaryService::STATUS_PAID), 'net_paid_total'),
            ],
            [
                'label' => 'Vade Bekleyen',
                'count' => $rows->where('summary.payment_status', FinanceSummaryService::STATUS_DUE_PENDING)->count(),
                'amount' => $sumField($rows->where('summary.payment_status', FinanceSummaryService::STATUS_DUE_PENDING), 'balance_due'),
            ],
            [
                'label' => 'Tahsilat Uyarısı',
                'count' => $rows->where('summary.payment_status', FinanceSummaryService::STATUS_COLLECTION_WARNING)->count(),
                'amount' => $sumField($rows->where('summary.payment_status', FinanceSummaryService::STATUS_COLLECTION_WARNING), 'balance_due'),
            ],
            [
                'label' => 'Fatura Kesilecek / Fatura',
                'count' => $rows->where('order.invoice_status', 'fatura')->count(),
                'amount' => $sumField($rows->where('order.invoice_status', 'fatura'), 'grand_total'),
            ],
            [
                'label' => 'Toplam Kalan',
                'count' => $rows->count(),
                'amount' => $sumField($rows, 'balance_due'),
            ],
        ];
    }

    private function singleCurrency(Collection $rows): ?string
    {
        $currencies = $rows
            ->map(fn (array $row): string => (string) data_get($row, 'summary.currency', 'TL'))
            ->filter()
            ->unique()
            ->values();

        return $currencies->count() === 1 ? $currencies->first() : null;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }

    private function paymentStatusLabels(): array
    {
        return [
            FinanceSummaryService::STATUS_PAYMENT_PENDING => 'Ödeme Bekliyor',
            FinanceSummaryService::STATUS_PARTIAL_PAYMENT => 'Kısmi Ödeme',
            FinanceSummaryService::STATUS_PAID => 'Ödendi',
            FinanceSummaryService::STATUS_OVERPAID => 'Fazla Ödeme',
            FinanceSummaryService::STATUS_DUE_PENDING => 'Vade Bekliyor',
            FinanceSummaryService::STATUS_COLLECTION_WARNING => 'Tahsilat Uyarısı',
            FinanceSummaryService::STATUS_CANCELLED => 'İptal',
        ];
    }

    private function invoiceStatusLabels(): array
    {
        return [
            'fis' => 'Fiş',
            'fatura' => 'Fatura',
        ];
    }
}
