<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\GraphicApprovalRequest;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AdminGraphicCustomerApprovalActionTest extends TestCase
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

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'graphic-guarded',
            'slug' => 'graphic-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Ayse Musteri',
            ],
            [
                'email' => 'ayse@example.test',
                'phone' => '05320000000',
                'mobile' => '05320000000',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
    }

    public function test_feature_guard_controls_visibility_and_send_route_access(): void
    {
        ['workForm' => $workForm, 'graphic' => $graphic] = $this->createGraphicContext('AGCA-001');

        $disabledResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $disabledResponse->assertOk();
        $disabledResponse->assertDontSee('Müşteri Onayı');
        $disabledResponse->assertDontSee('Müşteri Onayına Gönder');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $graphic), [
                'attachment_id' => 1,
            ])
            ->assertForbidden();

        $this->enableGraphicCustomerApproval();

        $enabledResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $enabledResponse->assertOk();
        $enabledResponse->assertSee('Müşteri Onayı');
        $enabledResponse->assertSee('Müşteri Onayına Gönder');
    }

    public function test_show_lists_only_customer_visible_eligible_attachments_without_sensitive_fields(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('AGCA-002');
        $approvalRequest = app(GraphicApprovalRequestService::class)->createRequest(
            $context['graphic'],
            $context['customerVisibleGraphic'],
            [],
            $this->adminUser
        );
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant Show',
            'legal_name' => 'Other Tenant Show Ltd.',
            'slug' => 'other-tenant-show-' . strtolower($context['graphic']->sequence_code ?: 'graphic'),
            'panel_subdomain' => 'other-tenant-show-' . strtolower($context['graphic']->sequence_code ?: 'graphic'),
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
        $context['foreignTenantAttachment']->forceFill([
            'tenant_account_id' => $otherTenant->id,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']));

        $response->assertOk();
        $response->assertSee('customer-visible-AGCA-002.png');
        $response->assertSee('approval-visible-AGCA-002.pdf');
        $response->assertDontSee('internal-only-AGCA-002.png');
        $response->assertDontSee('foreign-tenant-AGCA-002.png');
        $response->assertSee('Onay Linkini Aç');
        $response->assertDontSee($approvalRequest->token);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('storage/app', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('pdh_raw', false);
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('print_total', false);
    }

    public function test_send_action_creates_request_cancels_previous_open_request_and_emits_notification(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('AGCA-003');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $context['graphic']), [
                'attachment_id' => $context['customerVisibleGraphic']->id,
            ]);

        $response->assertRedirect(route('admin.graphics.show', $context['workForm']));
        $response->assertSessionHas('success');

        $request = GraphicApprovalRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($context['customerVisibleGraphic']->id, $request->attachment_id);
        $this->assertSame(GraphicApprovalRequest::STATUS_WAITING, $request->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING, $context['graphic']->fresh()->customer_approval_status);
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'graphic_customer_approval_requested')->where('related_id', $request->id)->exists());

        $secondResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $context['graphic']->fresh()), [
                'attachment_id' => $context['customerVisibleApproval']->id,
            ]);

        $secondResponse->assertRedirect(route('admin.graphics.show', $context['workForm']));

        $latest = GraphicApprovalRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($context['customerVisibleApproval']->id, $latest->attachment_id);
        $this->assertSame(GraphicApprovalRequest::STATUS_CANCELLED, $request->fresh()->status);

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']->fresh()));

        $showResponse->assertOk();
        $showResponse->assertDontSee($latest->token);
    }

    public function test_send_action_rejects_internal_mismatched_and_other_tenant_attachments(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('AGCA-004');

        $internalResponse = $this->actingAs($this->adminUser)
            ->from(route('admin.graphics.show', $context['workForm']))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $context['graphic']), [
                'attachment_id' => $context['internalAttachment']->id,
            ]);

        $internalResponse->assertRedirect(route('admin.graphics.show', $context['workForm']));
        $internalResponse->assertSessionHasErrors('attachment_id');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $context['graphic']), [
                'attachment_id' => $context['otherGraphicAttachment']->id,
            ])
            ->assertForbidden();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-graphic-approval',
            'panel_subdomain' => 'other-tenant-graphic-approval',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $context['foreignTenantAttachment']->forceFill([
            'tenant_account_id' => $otherTenant->id,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $context['graphic']), [
                'attachment_id' => $context['foreignTenantAttachment']->id,
            ])
            ->assertForbidden();
    }

    public function test_notification_failure_does_not_break_send_action_or_public_tracking(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('AGCA-005');

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new RuntimeException('notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.graphics.customer-approval.send', $context['graphic']), [
                'attachment_id' => $context['customerVisibleGraphic']->id,
            ]);

        $response->assertRedirect(route('admin.graphics.show', $context['workForm']));
        $this->assertTrue(GraphicApprovalRequest::query()->exists());

        $publicResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $context['workForm']->fresh()->public_tracking_token));

        $publicResponse->assertOk();
        $publicResponse->assertDontSee('notification_logs', false);
    }

    private function enableGraphicCustomerApproval(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => 'public_graphic_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function createGraphicContext(string $productCode): array
    {
        $workForm = $this->createConvertedWorkForm($productCode);
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->values();

        $graphic = $graphics->firstOrFail();
        $otherGraphic = $graphics->skip(1)->first() ?: $graphic;

        $customerVisibleGraphic = $this->createAttachment($workForm, $graphic, 'graphic_visual', 'customer_visible', 'customer-visible-' . $productCode . '.png');
        $customerVisibleApproval = $this->createAttachment($workForm, $graphic, 'customer_approval', 'customer_visible', 'approval-visible-' . $productCode . '.pdf', 'application/pdf');
        $internalAttachment = $this->createAttachment($workForm, $graphic, 'graphic_visual', 'internal', 'internal-only-' . $productCode . '.png');
        $otherGraphicAttachment = $this->createAttachment($workForm, $otherGraphic, 'graphic_visual', 'customer_visible', 'other-graphic-' . $productCode . '.png');
        $foreignTenantAttachment = $this->createAttachment($workForm, $graphic, 'graphic_visual', 'customer_visible', 'foreign-tenant-' . $productCode . '.png');

        $graphic->forceFill([
            'latest_attachment_id' => $customerVisibleGraphic->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(),
            'customerVisibleGraphic' => $customerVisibleGraphic,
            'customerVisibleApproval' => $customerVisibleApproval,
            'internalAttachment' => $internalAttachment,
            'otherGraphicAttachment' => $otherGraphicAttachment,
            'foreignTenantAttachment' => $foreignTenantAttachment,
        ];
    }

    private function createAttachment(
        OrderItemWorkForm $workForm,
        OrderItemPrintGraphic $graphic,
        string $attachmentType,
        string $visibility,
        string $fileName,
        string $mimeType = 'image/png',
    ): OrderItemWorkFormAttachment {
        return OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => $attachmentType,
            'visibility' => $visibility,
            'file_path' => 'work-forms/' . $workForm->id . '/' . $fileName,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteri onay dosyası',
            'sort_order' => 1,
        ]);
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
                'notes' => 'Admin graphic approval action payload',
                'items' => [[
                    'product_name' => 'Graphic Approval Admin Ürünü ' . $productCode,
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
}
