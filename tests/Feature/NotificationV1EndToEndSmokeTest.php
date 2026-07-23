<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\DeliveryWorkflowService;
use App\Services\Notifications\NotificationEventService;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\OrderPaymentService;
use App\Services\ProcurementWorkflowService;
use App\Services\QuoteApprovalService;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationV1EndToEndSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Smoke Musteri',
            ],
            [
                'email' => 'smoke-customer@example.test',
                'phone' => '05320007788',
                'mobile' => '05320007788',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_sms_enabled', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');

        $this->enableModuleFeature('notification_center');
        $this->enableModuleFeature('customer_quote_approval', 'customer_quote_approval');
    }

    public function test_notification_v1_end_to_end_smoke_covers_domain_events_and_channels(): void
    {
        $this->createTenantUserWithRole('production.v1@prodelya.local', 'production');
        $this->createTenantUserWithRole('graphic.v1@prodelya.local', 'graphic');
        $this->createTenantUserWithRole('delivery.v1@prodelya.local', 'delivery');
        $this->createTenantUserWithRole('finance.v1@prodelya.local', 'finance');
        $this->createTenantUserWithRole('procurement.v1@prodelya.local', 'supplier_operator');

        $quoteApprovalService = app(QuoteApprovalService::class);
        $quote = $this->createQuote('NTF-V1-QUOTE-001');
        $quoteRequest = $quoteApprovalService->sendToCustomer($quote, [
            'contact_name' => 'Smoke Musteri',
            'contact_email' => 'smoke-customer@example.test',
            'contact_phone' => '05320007788',
        ], $this->adminUser);
        $quoteApprovalService->approve($quoteRequest, 'Onaylandi');

        $this->assertLogExists('quote_sent_to_customer', $quote->id, [
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
            ['channel' => 'whatsapp_link', 'status' => NotificationLog::STATUS_LINK_CREATED],
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
        ]);
        $this->assertLogExists('quote_customer_approved', null, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
        ]);

        $workForm = $this->createConvertedWorkForm('NTF-V1-GRF-001');
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['orderItemPrint', 'workForm'])
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphics['1a'],
            UploadedFile::fake()->image('smoke-graphic.webp'),
            ['visibility' => 'internal', 'note' => 'Graphic smoke'],
            $this->adminUser
        );

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphics['1b'],
            UploadedFile::fake()->image('smoke-graphic-ready.webp'),
            ['visibility' => 'internal', 'note' => 'Graphic ready'],
            $this->adminUser
        );

        $graphicWorkflow = app(OrderItemPrintGraphicWorkflowService::class);
        $graphicWorkflow->markApproved($graphics['1b']->fresh(), $this->adminUser);
        $graphicWorkflow->markProductionReady($graphics['1b']->fresh(), $this->adminUser);

        $this->assertLogExists('graphic_visual_uploaded', $graphics['1a']->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);
        $this->assertLogExists('graphic_production_ready', $graphics['1b']->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);

        [$supplier, $source] = $this->createSupplierWithAccess('NTF-V1-PROC');
        $procurement = $this->createProcurement($supplier, $source, 'NTF-V1-PROC-001');
        $requestService = app(SupplierProcurementRequestService::class);
        $requestRecord = $requestService->createDraftForSupplier($this->tenant, $supplier->id, [$procurement->id], $this->adminUser);
        $requestRecord = $requestService->markRequested($requestRecord->fresh(), $this->adminUser);
        $requestRecord = $requestService->markSupplierOrdered($requestRecord->fresh(), $this->adminUser);
        $requestService->markCompleted($requestRecord->fresh(), $this->adminUser);

        $this->assertLogExists('procurement_request_created', $requestRecord->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ], $requestRecord->getMorphClass());
        $this->assertLogExists('procurement_received', $procurement->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);

        $production = $this->createReadyProduction('NTF-V1-PROD-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'production_unit_name' => 'UV Hattı 1',
                'assigned_to' => $this->adminUser->id,
                'cliche_required' => '0',
                'return_to' => 'show',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $production = $production->fresh();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV Hattı 1',
                'note' => 'Smoke started',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production->fresh()), [
                'action' => 'partial',
                'partial_quantity' => '25',
                'note' => 'Smoke partial',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production->fresh()), [
                'action' => 'completed',
                'note' => 'Smoke completed',
            ])
            ->assertRedirect();

        $this->assertLogExists('production_started', $production->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);
        $this->assertLogExists('production_partially_completed', $production->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);
        $this->assertLogExists('production_completed', $production->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);

        $delivery = $this->createDeliveryRecord('NTF-V1-DLV-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'delivered',
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'Aras Kargo',
                'tracking_number' => 'NTF-V1-TRK-001',
                'recipient_name' => 'Teslim Alan',
                'package_count' => '2',
                'units_per_package' => '50',
                'note' => 'Smoke delivery',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $delivery->fresh()->workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'note' => 'Müşteri teslim belgesi',
                'file' => UploadedFile::fake()->create('smoke-delivery.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $deliveryAttachment = OrderItemWorkFormAttachment::query()->latest('id')->firstOrFail();

        $this->assertLogExists('delivery_completed', $delivery->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
            ['channel' => 'whatsapp_link', 'status' => NotificationLog::STATUS_LINK_CREATED],
        ]);
        $this->assertLogExists('delivery_document_uploaded', $deliveryAttachment->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ], $deliveryAttachment->getMorphClass());

        $financeOrder = $this->createOrderFromQuote('NTF-V1-FIN-001');
        $paymentService = app(OrderPaymentService::class);
        $payment = $paymentService->createPayment($financeOrder, [
            'payment_type' => 'tahsilat',
            'amount' => 1500,
            'currency' => 'TL',
            'payment_method' => 'havale',
            'payment_reference' => 'NTF-V1-PAY-001',
            'paid_at' => '2026-06-18 10:00:00',
        ], $this->adminUser);
        $paymentService->cancelPayment($payment->fresh(), $this->adminUser, 'Smoke cancel');

        $this->assertLogExists('payment_received', $payment->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);
        $this->assertLogExists('payment_cancelled', $payment->id, [
            ['channel' => 'internal', 'status' => NotificationLog::STATUS_SENT],
            ['channel' => 'email', 'status' => NotificationLog::STATUS_PREVIEW],
        ]);

        $smsResult = app(NotificationEventService::class)->dispatchEvent(
            $this->tenant,
            'quote_sent_to_customer',
            $quote->fresh(),
            [
                'audience_type' => 'customer',
                'channels' => ['sms'],
                'recipient_override' => [[
                    'type' => 'phone',
                    'name' => 'SMS Customer',
                    'phone' => '05320007788',
                    'audience_type' => 'customer',
                ]],
                'created_by' => $this->adminUser,
                'related_type' => $quote->getMorphClass(),
                'related_id' => $quote->id,
            ]
        );

        $this->assertContains('sms', $smsResult['channels']);
        $this->assertTrue(NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', 'sms')
            ->where('status', NotificationLog::STATUS_SKIPPED)
            ->exists());

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('notification smoke failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureQuote = $this->createQuote('NTF-V1-FAIL-001');
        $failureRequest = app(QuoteApprovalService::class)->sendToCustomer($failureQuote, [
            'contact_email' => 'failure@example.test',
            'contact_phone' => '05320007788',
        ], $this->adminUser);

        $this->assertNotNull($failureRequest->id);
        $this->assertSame(Order::CUSTOMER_APPROVAL_WAITING, $failureQuote->fresh()->customer_approval_status);
    }

    private function assertLogExists(string $eventKey, ?int $relatedId, array $expectedChannels, ?string $relatedType = null): void
    {
        $logs = NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', $eventKey)
            ->when($relatedId !== null, fn ($query) => $query->where('related_id', $relatedId))
            ->when($relatedType !== null, fn ($query) => $query->where('related_type', $relatedType))
            ->orderBy('id')
            ->get();

        $this->assertNotEmpty($logs, $eventKey . ' için log bekleniyordu.');
        $this->assertTrue($logs->every(fn (NotificationLog $log) => $log->notification_key === $eventKey));
        $this->assertTrue($logs->every(fn (NotificationLog $log) => $log->tenant_account_id === $this->tenant->id));
        $this->assertTrue(
            $logs->contains(fn (NotificationLog $log) => data_get($log->meta_json, 'normalized_event_key') === $eventKey)
        );

        foreach ($expectedChannels as $expected) {
            $this->assertTrue(
                $logs->contains(fn (NotificationLog $log) => $log->channel === $expected['channel'] && $log->status === $expected['status']),
                $eventKey . ' için ' . $expected['channel'] . '/' . $expected['status'] . ' logu bekleniyordu.'
            );
        }
    }

    private function createTenantUserWithRole(string $email, string $roleKey): User
    {
        $user = User::query()->create([
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    private function enableModuleFeature(string $moduleKey, ?string $featureKey = null): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => $moduleKey,
                'feature_key' => $featureKey,
            ],
            ['is_enabled' => true]
        );
    }

    private function createQuote(string $documentNumber): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-18',
            'valid_until' => '2026-06-25',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Smoke Notification Ürünü',
            'product_code' => $documentNumber . '-ITEM',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Smoke notification kalemi',
            'product_snapshot' => [
                'display_name' => 'Smoke Notification Ürünü',
                'group_code' => 'HIDDEN-GROUP',
            ],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [['rate' => 20, 'total' => 240]],
                'pdh_raw' => ['secret' => 'hidden'],
            ],
            'list_price' => 12,
            'discount_rate' => 0,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote->fresh();
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Notification smoke payload',
                'items' => [[
                    'product_name' => 'Notification Smoke Ürün',
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                        ],
                        [
                            'print_type' => 'Serigrafi',
                            'print_option' => 'Gövde',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '12',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }

    private function createSupplierWithAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'granted_at' => now(),
            ]
        );

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber): OrderItemProcurement
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $orderNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $supplier->code . ' Ürün',
                'product_code' => $supplier->code . '-001',
                'supplier_name' => $supplier->name,
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 20,
                'line_total' => 2000,
                'vat_total' => 400,
                'pdh_raw' => ['secret' => 'hidden'],
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order'])->procurement;
    }

    private function createReadyProduction(string $documentNumber): OrderItemPrintProduction
    {
        $workForm = $this->createConvertedWorkForm($documentNumber . '-PRD');
        $production = OrderItemPrintProduction::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $workForm->order->source_quote_id))
            ->with(['workForm.procurement', 'orderItemPrint.graphicOperation'])
            ->latest('id')
            ->firstOrFail();

        $graphic = $production->orderItemPrint?->graphicOperation;
        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image('production-ready-' . $production->id . '.jpg'),
            ['note' => 'Production ready', 'visibility' => 'internal'],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm?->procurement;
        $this->assertNotNull($procurement);
        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm?->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'procurement_status_label' => 'Tamamı Geldi',
                    'public_status_label' => 'Ürün üretime hazır',
                    'received_quantity' => 100,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();

        return $production->fresh(['workForm', 'orderItemPrint.graphicOperation', 'orderItem', 'order']);
    }

    private function createDeliveryRecord(string $documentNumber): OrderItemWorkFormDelivery
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Delivery Smoke Ürünü',
            'product_code' => $documentNumber . '-ITEM',
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'Delivery Smoke Ürünü',
                'product_code' => $documentNumber . '-ITEM',
                'group_code' => 'HIDDEN-GROUP',
            ],
            'price_snapshot' => [
                'unit_price' => 55,
                'line_total' => 5500,
                'vat_total' => 1100,
                'grand_total' => 6600,
                'pdh_raw' => ['secret' => 'hidden'],
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 70,
            'discount_rate' => 5,
            'unit_price' => 55,
            'line_total' => 5500,
            'has_print' => true,
            'print_total' => 1500,
            'status' => 'pending',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'print_quantity' => 100,
            'note' => 'Delivery smoke baskı testi',
            'status' => 'draft',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $delivery = OrderItemWorkFormDelivery::query()
            ->with(['workForm', 'order', 'order.customer.contacts', 'orderItem'])
            ->latest('id')
            ->firstOrFail();

        $workForm = $delivery->workForm->fresh(['procurement', 'printProductions']);

        foreach ($workForm->printProductions as $production) {
            $production->forceFill([
                'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                'completed_quantity' => (float) $production->planned_quantity,
                'remaining_quantity' => 0,
            ])->save();
        }

        $workForm->forceFill([
            'production_snapshot' => array_merge(
                is_array($workForm->production_snapshot) ? $workForm->production_snapshot : [],
                [
                    'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                    'production_status_label' => 'Tamamlandı',
                    'completed_quantity' => 100,
                    'remaining_quantity' => 0,
                    'public_status_label' => 'Üretim tamamlandı',
                ]
            ),
        ])->save();

        $workForm->procurement?->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'received_quantity' => 100,
            'remaining_quantity' => 0,
        ])->save();

        $workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'procurement_status_label' => 'Tamamı Geldi',
                    'received_quantity' => 100,
                    'remaining_quantity' => 0,
                ]
            ),
        ])->save();

        return $delivery->fresh(['workForm', 'order', 'order.customer.contacts', 'orderItem']);
    }

    private function createOrderFromQuote(string $documentNumber): Order
    {
        $quote = $this->createQuote($documentNumber . '-QUOTE');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail()
            ->fresh(['payments', 'workForms', 'deliveries.workForm.attachments', 'customer']);
    }
}
