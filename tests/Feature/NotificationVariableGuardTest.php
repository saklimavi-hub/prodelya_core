<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\DeliveryCreationService;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Notifications\NotificationVariableBuilder;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationVariableGuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_variable_guard_preserves_audience_boundaries_and_sanitizes_forbidden_values(): void
    {
        ['order' => $order, 'workForm' => $workForm] = $this->createOrderWithWorkForm('SP-VAR-001');

        $builder = app(NotificationVariableBuilder::class);
        $templates = app(NotificationTemplateService::class);

        $customerVariables = $builder->buildForWorkForm($workForm, NotificationTemplate::AUDIENCE_CUSTOMER);
        $this->assertArrayNotHasKey('grand_total', $customerVariables);
        $this->assertArrayNotHasKey('balance_due', $customerVariables);
        $this->assertArrayNotHasKey('file_path', $customerVariables);
        $this->assertArrayNotHasKey('physical_path', $customerVariables);

        $internalVariables = $builder->buildForWorkForm($workForm, NotificationTemplate::AUDIENCE_INTERNAL);
        $this->assertArrayHasKey('product_summary', $internalVariables);
        $this->assertArrayNotHasKey('cost', $internalVariables);
        $this->assertArrayNotHasKey('profit', $internalVariables);

        $customerTemplate = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'delivery_completed',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'subject' => '{{order_number}} hazır',
            'body' => 'Toplam: {{grand_total}} | Dosya: {{file_path}} | Takip: {{public_tracking_url}}',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $renderedCustomer = $templates->render($customerTemplate, $customerVariables, NotificationTemplate::AUDIENCE_CUSTOMER);
        $this->assertContains('grand_total', $renderedCustomer['blocked_variables']);
        $this->assertContains('file_path', $renderedCustomer['blocked_variables']);
        $this->assertStringNotContainsString('grand_total', $renderedCustomer['body']);
        $this->assertStringNotContainsString('file_path', $renderedCustomer['body']);
        $this->assertStringContainsString($workForm->public_tracking_token, $renderedCustomer['body']);

        $payment = OrderPayment::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'customer_company_id' => $this->customer->id,
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1500,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => now(),
            'payment_reference' => 'TRX-001',
            'created_by' => $this->adminUser->id,
        ]);

        $financeVariables = $builder->buildForPayment($payment, NotificationTemplate::AUDIENCE_FINANCE);
        $this->assertSame(1500.0, $financeVariables['payment_amount']);
        $this->assertArrayHasKey('balance_due', $financeVariables);
        $this->assertArrayNotHasKey('file_path', $financeVariables);
        $this->assertArrayNotHasKey('physical_path', $financeVariables);

        $financeTemplate = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => NotificationTemplate::CHANNEL_INTERNAL,
            'audience_type' => NotificationTemplate::AUDIENCE_FINANCE,
            'subject' => '{{order_number}} odeme',
            'body' => 'Odeme: {{payment_amount}} {{payment_currency}} / Bakiye: {{balance_due}} / Token: {{token}}',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $renderedFinance = $templates->render($financeTemplate, array_merge($financeVariables, [
            'token' => 'super-secret-token',
        ]), NotificationTemplate::AUDIENCE_FINANCE);

        $this->assertContains('token', $renderedFinance['blocked_variables']);
        $this->assertStringContainsString('1500', $renderedFinance['body']);
        $this->assertStringNotContainsString('super-secret-token', $renderedFinance['body']);
    }

    private function createOrderWithWorkForm(string $documentNumber): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'source_quote_number' => 'TK-' . substr($documentNumber, 3),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => 18000,
            'print_total' => 4500,
            'subtotal' => 22500,
            'vat_total' => 4500,
            'grand_total' => 27000,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Variable Test Ürünü',
            'product_code' => 'VAR-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Variable test kalemi',
            'product_snapshot' => [
                'product_name' => 'Variable Test Ürünü',
                'group_code' => 'HIDDEN-GROUP',
            ],
            'price_snapshot' => [
                'grand_total' => 27000,
            ],
            'catalog_source' => 'tenant_catalog',
            'unit_price' => 181.23,
            'line_total' => 18000,
            'has_print' => false,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery']);

        if (!$workForm->delivery) {
            app(DeliveryCreationService::class)->createForWorkForm($workForm, $this->adminUser);
        }

        return [
            'order' => $order,
            'workForm' => $workForm->fresh(['delivery']),
        ];
    }
}
