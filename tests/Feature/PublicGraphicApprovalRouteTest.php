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

class PublicGraphicApprovalRouteTest extends TestCase
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
            'panel_subdomain' => 'public-graphic-guarded',
            'slug' => 'public-graphic-guarded',
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

        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        $this->enableGraphicApproval();
    }

    public function test_valid_token_show_marks_viewed_and_public_url_is_resolved(): void
    {
        ['request' => $approvalRequest, 'graphic' => $graphic] = $this->createApprovalRequest('PGAR-001');

        $this->assertSame(GraphicApprovalRequest::STATUS_WAITING, $approvalRequest->status);

        $response = $this->get(route('public.graphics.approval.show', ['token' => $approvalRequest->token]));

        $response->assertOk();
        $response->assertSee('Grafik Onayı');
        $response->assertSee($graphic->orderItem->product_name);
        $response->assertSee('Grafiği Onayla');
        $this->assertSame(GraphicApprovalRequest::STATUS_VIEWED, $approvalRequest->fresh()->status);
        $this->assertSame(route('public.graphics.approval.show', ['token' => $approvalRequest->token]), $approvalRequest->fresh()->publicUrl());
    }

    public function test_approve_and_revision_actions_work_and_do_not_auto_mark_production_ready(): void
    {
        ['request' => $approveRequest, 'graphic' => $approveGraphic] = $this->createApprovalRequest('PGAR-002');

        $approveResponse = $this->post(route('public.graphics.approval.approve', ['token' => $approveRequest->token]), [
            'customer_note' => 'Uygundur',
        ]);

        $approveResponse->assertRedirect(route('public.graphics.approval.show', ['token' => $approveRequest->token]));
        $approveResponse->assertSessionHas('success', 'Grafik onayınız alınmıştır.');
        $this->assertSame(GraphicApprovalRequest::STATUS_APPROVED, $approveRequest->fresh()->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED, $approveGraphic->fresh()->customer_approval_status);
        $this->assertSame(OrderItemPrintGraphic::STATUS_APPROVED, $approveGraphic->fresh()->status);
        $this->assertNotSame(OrderItemPrintGraphic::STATUS_PRODUCTION_READY, $approveGraphic->fresh()->status);
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'graphic_customer_approved')->exists());

        ['request' => $revisionRequest, 'graphic' => $revisionGraphic] = $this->createApprovalRequest('PGAR-003');

        $this->post(route('public.graphics.approval.revision', ['token' => $revisionRequest->token]), [
            'customer_note' => 'ab',
        ])->assertSessionHasErrors('customer_note');

        $revisionResponse = $this->post(route('public.graphics.approval.revision', ['token' => $revisionRequest->token]), [
            'customer_note' => 'Logo biraz yukari alin.',
        ]);

        $revisionResponse->assertRedirect(route('public.graphics.approval.show', ['token' => $revisionRequest->token]));
        $revisionResponse->assertSessionHas('success', 'Revize talebiniz iletilmiştir.');
        $this->assertSame(GraphicApprovalRequest::STATUS_REVISION_REQUESTED, $revisionRequest->fresh()->status);
        $this->assertSame(OrderItemPrintGraphic::STATUS_REVISION_REQUESTED, $revisionGraphic->fresh()->status);
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'graphic_revision_requested')->exists());
    }

    public function test_invalid_cancelled_processed_expired_and_feature_disabled_tokens_are_safely_closed(): void
    {
        $this->get(route('public.graphics.approval.show', ['token' => 'gecersiz-token']))
            ->assertNotFound();

        ['request' => $cancelledRequest] = $this->createApprovalRequest('PGAR-004');
        $cancelledRequest->forceFill(['status' => GraphicApprovalRequest::STATUS_CANCELLED])->save();

        $this->get(route('public.graphics.approval.show', ['token' => $cancelledRequest->token]))
            ->assertNotFound();

        ['request' => $approvedRequest] = $this->createApprovalRequest('PGAR-005');
        app(GraphicApprovalRequestService::class)->approve($approvedRequest, []);

        $approvedResponse = $this->followingRedirects()
            ->post(route('public.graphics.approval.approve', ['token' => $approvedRequest->token]), []);

        $approvedResponse->assertOk();
        $approvedResponse->assertSee('Bu grafik daha önce onaylanmış.');

        ['request' => $revisionedRequest] = $this->createApprovalRequest('PGAR-006');
        app(GraphicApprovalRequestService::class)->requestRevision($revisionedRequest, 'Revize gerekli');

        $revisionedResponse = $this->followingRedirects()
            ->post(route('public.graphics.approval.revision', ['token' => $revisionedRequest->token]), [
                'customer_note' => 'Tekrar deneme',
            ]);

        $revisionedResponse->assertOk();
        $revisionedResponse->assertSee('Bu grafik için revize talebiniz iletilmiş.');

        ['request' => $expiredRequest] = $this->createApprovalRequest('PGAR-007', [
            'expires_at' => now()->subMinute(),
        ]);

        $expiredResponse = $this->get(route('public.graphics.approval.show', ['token' => $expiredRequest->token]));
        $expiredResponse->assertOk();
        $expiredResponse->assertSee('Bu grafik onay bağlantısının süresi dolmuş.');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'graphic_customer_approval')
            ->delete();

        ['request' => $featureClosedRequest] = $this->createApprovalRequest('PGAR-008');

        $this->get(route('public.graphics.approval.show', ['token' => $featureClosedRequest->token]))
            ->assertNotFound();

        $this->post(route('public.graphics.approval.approve', ['token' => $featureClosedRequest->token]), [])
            ->assertNotFound();
    }

    public function test_notification_failures_do_not_break_public_actions(): void
    {
        ['request' => $approvalRequest] = $this->createApprovalRequest('PGAR-009');

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new RuntimeException('notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $response = $this->followingRedirects()
            ->post(route('public.graphics.approval.approve', ['token' => $approvalRequest->token]), []);

        $response->assertOk();
        $response->assertSee('Grafik onayınız alınmıştır.');
        $this->assertSame(GraphicApprovalRequest::STATUS_APPROVED, $approvalRequest->fresh()->status);
    }

    private function enableGraphicApproval(): void
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

    private function createApprovalRequest(string $productCode, array $requestData = []): array
    {
        $workForm = $this->createConvertedWorkForm($productCode);
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['orderItem', 'orderItemPrint', 'workForm', 'tenant'])
            ->orderBy('sequence_code')
            ->firstOrFail();

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/graphic-' . strtolower($productCode) . '.png',
            'file_name' => 'graphic-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteri onay görseli',
            'sort_order' => 1,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $attachment->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $approvalRequest = app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh(),
            $attachment,
            $requestData,
            $this->adminUser
        );

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(['orderItem', 'orderItemPrint', 'workForm']),
            'attachment' => $attachment->fresh(),
            'request' => $approvalRequest->fresh(),
        ];
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
                'notes' => 'Public graphic approval payload',
                'items' => [[
                    'product_name' => 'Public Graphic Approval Ürünü ' . $productCode,
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '100',
                        'print_unit_price' => '10',
                    ]],
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
