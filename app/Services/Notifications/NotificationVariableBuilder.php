<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\SupplierProcurementRequest;
use App\Models\TenantAccount;
use App\Services\FinanceSummaryService;

class NotificationVariableBuilder
{
    public function __construct(
        protected FinanceSummaryService $financeSummaryService,
    ) {}

    public function buildForOrder(Order $order, string $audienceType = NotificationTemplate::AUDIENCE_CUSTOMER): array
    {
        $order->loadMissing(['customer.contacts', 'items', 'workForms']);

        $firstItem = $order->items->first();
        $firstWorkForm = $order->workForms->first();
        $quoteNumber = $order->isQuote() ? $order->document_number : $order->source_quote_number;
        $orderNumber = $order->isQuote() ? null : $order->document_number;
        $base = [
            'customer_name' => $order->customer?->getPrimaryContact()?->name ?: $order->customer?->legal_name,
            'order_number' => $orderNumber,
            'quote_number' => $quoteNumber,
            'product_summary' => $firstItem?->product_name,
            'status_label' => $this->humanize((string) $order->status),
            'work_form_number' => $firstWorkForm?->work_form_number,
            'public_tracking_url' => $firstWorkForm?->public_tracking_token
                ? route('public.work-forms.track', $firstWorkForm->public_tracking_token)
                : null,
            'delivery_status' => data_get($firstWorkForm?->delivery_snapshot, 'delivery_status_label'),
            'delivery_date' => null,
            'public_quote_url' => null,
            'payment_warning_label' => null,
            'assigned_user_name' => $order->creator?->name,
        ];

        if (in_array($audienceType, [
            NotificationTemplate::AUDIENCE_INTERNAL,
            NotificationTemplate::AUDIENCE_ADMIN,
            NotificationTemplate::AUDIENCE_SALES_OWNER,
        ], true) && $firstWorkForm) {
            $base['pdf_url'] = route('admin.work-forms.pdf', $firstWorkForm);
        }

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForWorkForm(OrderItemWorkForm $workForm, string $audienceType = NotificationTemplate::AUDIENCE_CUSTOMER): array
    {
        $workForm->loadMissing(['order.customer.contacts', 'orderItem', 'delivery']);

        $base = [
            'customer_name' => $workForm->order?->customer?->getPrimaryContact()?->name ?: $workForm->order?->customer?->legal_name,
            'order_number' => $workForm->order?->document_number,
            'quote_number' => $workForm->order?->source_quote_number,
            'work_form_number' => $workForm->work_form_number,
            'product_summary' => $workForm->orderItem?->product_name,
            'status_label' => data_get($workForm->delivery_snapshot, 'delivery_status_label')
                ?: data_get($workForm->production_snapshot, 'production_status_label')
                ?: $this->humanize((string) $workForm->status),
            'public_tracking_url' => route('public.work-forms.track', $workForm->public_tracking_token),
            'public_quote_url' => null,
            'delivery_status' => data_get($workForm->delivery_snapshot, 'delivery_status_label'),
            'delivery_date' => optional($workForm->delivery?->updated_at)->toDateString(),
            'payment_warning_label' => in_array($audienceType, [
                NotificationTemplate::AUDIENCE_INTERNAL,
                NotificationTemplate::AUDIENCE_FINANCE,
                NotificationTemplate::AUDIENCE_ADMIN,
                NotificationTemplate::AUDIENCE_SALES_OWNER,
            ], true)
                ? data_get($workForm->delivery_snapshot, 'financial_warning_label')
                : null,
            'assigned_user_name' => $workForm->order?->creator?->name,
            'internal_note' => null,
        ];

        if (in_array($audienceType, [
            NotificationTemplate::AUDIENCE_INTERNAL,
            NotificationTemplate::AUDIENCE_FINANCE,
            NotificationTemplate::AUDIENCE_ADMIN,
            NotificationTemplate::AUDIENCE_SALES_OWNER,
        ], true)) {
            $base['pdf_url'] = route('admin.work-forms.pdf', $workForm);
        }

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForDelivery(OrderItemWorkFormDelivery $delivery, string $audienceType = NotificationTemplate::AUDIENCE_CUSTOMER): array
    {
        $delivery->loadMissing(['workForm.order.customer.contacts', 'workForm.orderItem']);

        $workForm = $delivery->workForm;
        $base = [
            'customer_name' => $workForm?->order?->customer?->getPrimaryContact()?->name ?: $workForm?->order?->customer?->legal_name,
            'order_number' => $workForm?->order?->document_number,
            'quote_number' => $workForm?->order?->source_quote_number,
            'work_form_number' => $workForm?->work_form_number,
            'product_summary' => $workForm?->orderItem?->product_name,
            'product_name' => $workForm?->orderItem?->product_name,
            'status_label' => $delivery->safeStatusLabel(),
            'public_tracking_url' => $workForm?->public_tracking_token
                ? route('public.work-forms.track', $workForm->public_tracking_token)
                : null,
            'public_quote_url' => null,
            'delivery_status' => $delivery->safeStatusLabel(),
            'delivery_date' => optional($delivery->updated_at)->toDateString(),
            'delivery_method' => $delivery->safeDeliveryMethodLabel(),
            'tracking_number' => $delivery->tracking_number,
            'recipient_name' => $delivery->recipient_name,
            'delivered_quantity' => $this->stringNumber((float) $delivery->delivered_quantity),
            'remaining_quantity' => $this->stringNumber((float) $delivery->remaining_quantity),
            'package_count' => $delivery->package_count ? (string) $delivery->package_count : null,
            'units_per_package' => $delivery->units_per_package ? (string) $delivery->units_per_package : null,
            'payment_warning_label' => in_array($audienceType, [
                NotificationTemplate::AUDIENCE_INTERNAL,
                NotificationTemplate::AUDIENCE_FINANCE,
                NotificationTemplate::AUDIENCE_ADMIN,
                NotificationTemplate::AUDIENCE_SALES_OWNER,
            ], true)
                ? $delivery->safeFinancialWarningLabel()
                : null,
            'assigned_user_name' => $workForm?->order?->creator?->name,
            'internal_note' => null,
        ];

        if (in_array($audienceType, [
            NotificationTemplate::AUDIENCE_INTERNAL,
            NotificationTemplate::AUDIENCE_FINANCE,
            NotificationTemplate::AUDIENCE_ADMIN,
            NotificationTemplate::AUDIENCE_SALES_OWNER,
        ], true) && $workForm) {
            $base['pdf_url'] = route('admin.work-forms.pdf', $workForm);
        }

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForPayment(OrderPayment $payment, string $audienceType = NotificationTemplate::AUDIENCE_FINANCE): array
    {
        $payment->loadMissing(['order.customer.contacts']);
        $summary = $this->financeSummaryService->summarizeOrder($payment->order);

        $base = [
            'customer_name' => $payment->order?->customer?->getPrimaryContact()?->name ?: $payment->order?->customer?->legal_name,
            'order_number' => $payment->order?->document_number,
            'quote_number' => $payment->order?->source_quote_number,
            'status_label' => data_get($summary, 'payment_status_label'),
            'payment_warning_label' => data_get($summary, 'delivery_financial_warning_label'),
            'payment_type_label' => $payment->safePaymentTypeLabel(),
            'payment_method' => $payment->safePaymentMethodLabel(),
            'payment_method_label' => $payment->safePaymentMethodLabel(),
            'payment_amount' => round((float) $payment->amount, 2),
            'payment_currency' => $payment->currency,
            'payment_reference' => $payment->payment_reference,
            'paid_at' => optional($payment->paid_at)?->toAtomString(),
            'due_date' => optional($payment->due_date)?->toAtomString(),
            'subtotal' => (float) data_get($summary, 'subtotal', 0),
            'vat_total' => (float) data_get($summary, 'vat_total', 0),
            'grand_total' => (float) data_get($summary, 'grand_total', 0),
            'paid_total' => (float) data_get($summary, 'net_paid_total', 0),
            'balance_due' => (float) data_get($summary, 'balance_due', 0),
        ];

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForGraphic(OrderItemPrintGraphic $graphic, string $audienceType = NotificationTemplate::AUDIENCE_INTERNAL): array
    {
        $graphic->loadMissing([
            'order.customer.contacts',
            'orderItem',
            'workForm.delivery',
            'orderItemPrint.tenantPrintSetting',
            'creator',
            'updater',
        ]);

        $workForm = $graphic->workForm;
        $print = $graphic->orderItemPrint;
        $productName = $graphic->orderItem?->product_name;

        $base = [
            'customer_name' => $graphic->order?->customer?->getPrimaryContact()?->name ?: $graphic->order?->customer?->legal_name,
            'order_number' => $graphic->order?->isQuote() ? null : $graphic->order?->document_number,
            'quote_number' => $graphic->order?->isQuote() ? $graphic->order?->document_number : $graphic->order?->source_quote_number,
            'work_form_number' => $workForm?->work_form_number,
            'product_summary' => $productName,
            'product_name' => $productName,
            'print_label' => $this->resolvePrintLabel($graphic),
            'graphic_status' => $graphic->safeStatusLabel(),
            'status_label' => $graphic->safeStatusLabel(),
            'public_tracking_url' => $workForm?->public_tracking_token
                ? route('public.work-forms.track', $workForm->public_tracking_token)
                : null,
            'public_quote_url' => null,
            'public_graphic_approval_url' => null,
            'delivery_status' => $workForm?->delivery?->safeStatusLabel(),
            'delivery_date' => optional($workForm?->delivery?->updated_at)->toDateString(),
            'assigned_user_name' => $graphic->updater?->name ?: $graphic->creator?->name ?: $graphic->order?->creator?->name,
            'internal_note' => null,
        ];

        if (in_array($audienceType, [
            NotificationTemplate::AUDIENCE_INTERNAL,
            NotificationTemplate::AUDIENCE_FINANCE,
            NotificationTemplate::AUDIENCE_ADMIN,
            NotificationTemplate::AUDIENCE_SALES_OWNER,
        ], true) && $workForm) {
            $base['pdf_url'] = route('admin.work-forms.pdf', $workForm);
        }

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForProcurement(OrderItemProcurement $procurement, string $audienceType = NotificationTemplate::AUDIENCE_INTERNAL): array
    {
        $procurement->loadMissing([
            'order.customer.contacts',
            'orderItem',
            'workForm',
            'supplier',
            'supplierSource.supplier',
            'creator',
            'updater',
        ]);

        $snapshot = is_array($procurement->snapshot) ? $procurement->snapshot : [];

        $base = [
            'customer_name' => $procurement->order?->customer?->getPrimaryContact()?->name ?: $procurement->order?->customer?->legal_name,
            'order_number' => $procurement->order?->document_number ?: data_get($snapshot, 'order_number'),
            'quote_number' => $procurement->order?->source_quote_number,
            'work_form_number' => $procurement->workForm?->work_form_number ?: data_get($snapshot, 'work_form_number'),
            'product_summary' => $procurement->orderItem?->product_name ?: data_get($snapshot, 'product_name'),
            'product_name' => $procurement->orderItem?->product_name ?: data_get($snapshot, 'product_name'),
            'product_code' => $procurement->orderItem?->product_code ?: data_get($snapshot, 'product_code'),
            'supplier_name' => $procurement->supplier?->name ?: data_get($snapshot, 'supplier_name'),
            'procurement_number' => null,
            'requested_quantity' => $this->stringNumber((float) $procurement->requested_quantity),
            'received_quantity' => $this->stringNumber((float) $procurement->received_quantity),
            'remaining_quantity' => $this->stringNumber((float) $procurement->remaining_quantity),
            'status_label' => $procurement->safeStatusLabel(),
            'assigned_user_name' => $procurement->updater?->name ?: $procurement->creator?->name ?: $procurement->order?->creator?->name,
            'internal_note' => null,
        ];

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForProduction(OrderItemPrintProduction $production, string $audienceType = NotificationTemplate::AUDIENCE_INTERNAL): array
    {
        $production->loadMissing([
            'order.customer.contacts',
            'orderItem',
            'workForm',
            'orderItemPrint',
            'productionCompany',
            'creator',
            'updater',
            'assignedUser',
        ]);

        $base = [
            'customer_name' => $production->order?->customer?->getPrimaryContact()?->name ?: $production->order?->customer?->legal_name,
            'order_number' => $production->order?->isQuote() ? null : $production->order?->document_number,
            'quote_number' => $production->order?->isQuote() ? $production->order?->document_number : $production->order?->source_quote_number,
            'work_form_number' => $production->workForm?->work_form_number,
            'product_summary' => $production->orderItem?->product_name,
            'product_name' => $production->orderItem?->product_name,
            'print_label' => $this->resolveProductionPrintLabel($production),
            'production_status' => $production->safeStatusLabel(),
            'production_type_label' => $production->safeProductionTypeLabel(),
            'planned_quantity' => $this->stringNumber((float) $production->planned_quantity),
            'completed_quantity' => $this->stringNumber((float) $production->completed_quantity),
            'remaining_quantity' => $this->stringNumber((float) $production->remaining_quantity),
            'status_label' => $production->safeStatusLabel(),
            'assigned_user_name' => $production->assignedUser?->name
                ?: $production->updater?->name
                ?: $production->creator?->name
                ?: $production->order?->creator?->name,
            'internal_note' => filled($production->issue_note) ? trim((string) $production->issue_note) : null,
        ];

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForSupplierProcurementRequest(SupplierProcurementRequest $request, string $audienceType = NotificationTemplate::AUDIENCE_INTERNAL): array
    {
        $request->loadMissing([
            'supplier',
            'items.procurement.order.customer.contacts',
            'items.procurement.orderItem',
            'items.procurement.workForm',
            'creator',
            'updater',
        ]);

        $firstItem = $request->items->first();
        $procurement = $firstItem?->procurement;
        $snapshot = is_array($procurement?->snapshot) ? $procurement->snapshot : [];

        $base = [
            'customer_name' => $procurement?->order?->customer?->getPrimaryContact()?->name ?: $procurement?->order?->customer?->legal_name,
            'order_number' => $procurement?->order?->document_number ?: data_get($snapshot, 'order_number'),
            'quote_number' => $procurement?->order?->source_quote_number,
            'work_form_number' => $procurement?->workForm?->work_form_number ?: data_get($snapshot, 'work_form_number'),
            'product_summary' => $firstItem?->product_name ?: data_get($snapshot, 'product_name'),
            'product_name' => $firstItem?->product_name ?: data_get($snapshot, 'product_name'),
            'product_code' => $firstItem?->product_code ?: data_get($snapshot, 'product_code'),
            'supplier_name' => $request->supplier?->name,
            'procurement_number' => $request->request_number,
            'requested_quantity' => $this->stringNumber((float) $request->items->sum('requested_quantity')),
            'received_quantity' => $this->stringNumber((float) $request->items->sum('received_quantity')),
            'remaining_quantity' => $this->stringNumber((float) $request->items->sum('remaining_quantity')),
            'status_label' => $request->safeStatusLabel(),
            'assigned_user_name' => $request->updater?->name ?: $request->creator?->name,
            'internal_note' => null,
        ];

        return $this->filterForAudience($base, $audienceType);
    }

    public function buildForSource(mixed $source, string $audienceType = NotificationTemplate::AUDIENCE_CUSTOMER): array
    {
        return match (true) {
            $source instanceof Order => $this->buildForOrder($source, $audienceType),
            $source instanceof OrderItemPrintGraphic => $this->buildForGraphic(
                $source,
                $audienceType === NotificationTemplate::AUDIENCE_CUSTOMER
                    ? NotificationTemplate::AUDIENCE_INTERNAL
                    : $audienceType
            ),
            $source instanceof OrderItemPrintProduction => $this->buildForProduction(
                $source,
                $audienceType === NotificationTemplate::AUDIENCE_CUSTOMER
                    ? NotificationTemplate::AUDIENCE_INTERNAL
                    : $audienceType
            ),
            $source instanceof OrderItemProcurement => $this->buildForProcurement(
                $source,
                $audienceType === NotificationTemplate::AUDIENCE_CUSTOMER
                    ? NotificationTemplate::AUDIENCE_INTERNAL
                    : $audienceType
            ),
            $source instanceof SupplierProcurementRequest => $this->buildForSupplierProcurementRequest(
                $source,
                $audienceType === NotificationTemplate::AUDIENCE_CUSTOMER
                    ? NotificationTemplate::AUDIENCE_INTERNAL
                    : $audienceType
            ),
            $source instanceof OrderItemWorkForm => $this->buildForWorkForm($source, $audienceType),
            $source instanceof OrderItemWorkFormDelivery => $this->buildForDelivery($source, $audienceType),
            $source instanceof OrderPayment => $this->buildForPayment(
                $source,
                $audienceType === NotificationTemplate::AUDIENCE_CUSTOMER
                    ? NotificationTemplate::AUDIENCE_FINANCE
                    : $audienceType
            ),
            $source instanceof TenantAccount => [],
            default => [],
        };
    }

    public function publicSafeVariables(array $variables): array
    {
        return $this->filterForAudience($variables, NotificationTemplate::AUDIENCE_CUSTOMER);
    }

    public function internalSafeVariables(array $variables): array
    {
        return $this->filterForAudience($variables, NotificationTemplate::AUDIENCE_INTERNAL);
    }

    public function financeSafeVariables(array $variables): array
    {
        return $this->filterForAudience($variables, NotificationTemplate::AUDIENCE_FINANCE);
    }

    private function filterForAudience(array $variables, string $audienceType): array
    {
        $allowed = array_flip(match ($audienceType) {
            NotificationTemplate::AUDIENCE_CUSTOMER => [
                'customer_name',
                'order_number',
                'quote_number',
                'work_form_number',
                'product_summary',
                'product_name',
                'print_label',
                'graphic_status',
                'production_status',
                'production_type_label',
                'delivery_method',
                'tracking_number',
                'recipient_name',
                'product_code',
                'supplier_name',
                'procurement_number',
                'requested_quantity',
                'received_quantity',
                'remaining_quantity',
                'planned_quantity',
                'completed_quantity',
                'package_count',
                'units_per_package',
                'delivered_quantity',
                'status_label',
                'public_tracking_url',
                'public_quote_url',
                'public_graphic_approval_url',
                'delivery_status',
                'delivery_date',
            ],
            NotificationTemplate::AUDIENCE_FINANCE => [
                'customer_name',
                'order_number',
                'quote_number',
                'status_label',
                'payment_warning_label',
                'payment_type_label',
                'payment_method',
                'payment_method_label',
                'payment_amount',
                'payment_currency',
                'payment_reference',
                'paid_at',
                'due_date',
                'subtotal',
                'vat_total',
                'grand_total',
                'paid_total',
                'balance_due',
            ],
            default => [
                'customer_name',
                'order_number',
                'quote_number',
                'work_form_number',
                'product_summary',
                'product_name',
                'print_label',
                'graphic_status',
                'production_status',
                'production_type_label',
                'delivery_method',
                'tracking_number',
                'recipient_name',
                'product_code',
                'supplier_name',
                'procurement_number',
                'requested_quantity',
                'received_quantity',
                'remaining_quantity',
                'planned_quantity',
                'completed_quantity',
                'package_count',
                'units_per_package',
                'delivered_quantity',
                'status_label',
                'internal_note',
                'assigned_user_name',
            ],
        });

        return collect($variables)
            ->filter(fn ($value, $key) => array_key_exists($key, $allowed) && $value !== null && $value !== '')
            ->all();
    }

    private function humanize(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '-';
        }

        return ucfirst(str_replace('_', ' ', $value));
    }

    private function resolvePrintLabel(OrderItemPrintGraphic $graphic): ?string
    {
        $print = $graphic->orderItemPrint;

        if (!$print) {
            return $graphic->sequence_code ?: null;
        }

        $parts = array_filter([
            $graphic->sequence_code,
            $print->displayPrintType(),
            $print->print_option,
        ], fn ($value) => filled($value));

        return empty($parts) ? null : implode(' ', $parts);
    }

    private function resolveProductionPrintLabel(OrderItemPrintProduction $production): ?string
    {
        $print = $production->orderItemPrint;

        if (!$print) {
            return null;
        }

        $parts = array_filter([
            $print->sequence_code ?? null,
            method_exists($print, 'displayPrintType') ? $print->displayPrintType() : ($print->print_type ?? null),
            $print->print_option,
        ], fn ($value) => filled($value));

        return empty($parts) ? null : implode(' ', $parts);
    }

    private function stringNumber(float $value): ?string
    {
        return $value > 0 ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') : null;
    }
}
