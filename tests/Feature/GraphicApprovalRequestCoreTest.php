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
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class GraphicApprovalRequestCoreTest extends TestCase
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
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
    }

    public function test_create_request_pins_attachment_cancels_previous_open_request_and_dispatches_safe_customer_notifications(): void
    {
        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment] = $this->createGraphicContext('GAR-001');

        $request = $service->createRequest($graphic, $attachment, [], $this->adminUser);

        $this->assertSame(64, strlen($request->token));
        $this->assertSame($attachment->id, $request->attachment_id);
        $this->assertSame(GraphicApprovalRequest::STATUS_WAITING, $request->status);
        $this->assertSame('Ayse Musteri', $request->contact_name);
        $this->assertSame('ayse@example.test', $request->contact_email);
        $this->assertSame('05320000000', $request->contact_phone);
        $this->assertSame(
            route('public.graphics.approval.show', ['token' => $request->token]),
            $request->publicUrl()
        );

        $graphic = $graphic->fresh(['latestApprovalRequest', 'openApprovalRequest', 'workForm']);
        $this->assertSame(OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING, $graphic->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING, $graphic->customer_approval_status);
        $this->assertSame($request->id, $graphic->latestApprovalRequest?->id);
        $this->assertSame($request->id, $graphic->openApprovalRequest?->id);
        $this->assertSame('musteri_onayi_bekliyor', data_get($graphic->workForm->fresh()->graphic_snapshot, 'status'));

        $logs = NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'graphic_customer_approval_requested')
            ->where('related_id', $request->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'whatsapp_link' && $log->status === NotificationLog::STATUS_LINK_CREATED));
        $this->assertStringNotContainsString((string) $request->token, json_encode($logs->firstWhere('channel', 'email')?->meta_json, JSON_UNESCAPED_UNICODE));

        $secondRequest = $service->createRequest($graphic->fresh(), $attachment->fresh(), [
            'contact_name' => 'Yeni Kontak',
        ], $this->adminUser);

        $this->assertSame(GraphicApprovalRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame('replaced_by_new_send', $request->fresh()->cancellation_reason);
        $this->assertSame(GraphicApprovalRequest::STATUS_WAITING, $secondRequest->status);
        $this->assertSame('Yeni Kontak', $secondRequest->contact_name);
    }

    public function test_attachment_eligibility_rejects_internal_and_mismatched_files(): void
    {
        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment, 'otherGraphic' => $otherGraphic] = $this->createGraphicContext('GAR-002');

        $internalAttachment = $attachment->replicate();
        $internalAttachment->visibility = 'internal';
        $internalAttachment->file_name = 'internal-graphic.png';
        $internalAttachment->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Müşteri onayına gönderilecek görsel müşteri görünür olmalıdır.');
        $service->assertAttachmentEligible($graphic, $internalAttachment);

        $mismatchedAttachment = $attachment->replicate();
        $mismatchedAttachment->order_item_print_graphic_id = $otherGraphic->id;
        $mismatchedAttachment->order_item_print_id = $otherGraphic->order_item_print_id;
        $mismatchedAttachment->file_name = 'mismatch-graphic.png';
        $mismatchedAttachment->save();

        try {
            $service->assertAttachmentEligible($graphic, $mismatchedAttachment);
            $this->fail('Mismatched attachment should fail eligibility check.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Grafik görseli seçilen operasyon ile eşleşmiyor.', $exception->getMessage());
        }
    }

    public function test_create_request_falls_back_to_company_email_when_primary_contact_email_is_missing(): void
    {
        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment] = $this->createGraphicContext('GAR-002B');

        $this->customer->forceFill(['email' => 'firma@example.test'])->save();
        $this->customer->contacts()->update(['email' => null, 'mobile' => null, 'phone' => null]);

        $request = $service->createRequest($graphic->fresh(['order.customer.contacts', 'workForm']), $attachment->fresh(), [], $this->adminUser);

        $this->assertSame('firma@example.test', $request->contact_email);
        $this->assertSame('company_record', data_get($request->meta_json, 'recipient_source'));

        $emailLog = NotificationLog::query()
            ->where('notification_key', 'graphic_customer_approval_requested')
            ->where('related_id', $request->id)
            ->where('channel', NotificationLog::CHANNEL_EMAIL)
            ->latest('id')
            ->first();

        $this->assertNotNull($emailLog);
        $this->assertSame('firma@example.test', $emailLog->recipient_email);
    }

    public function test_mark_viewed_is_idempotent_and_approve_updates_request_graphic_and_logs(): void
    {
        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment] = $this->createGraphicContext('GAR-003');

        $request = $service->createRequest($graphic, $attachment, [], $this->adminUser);
        $viewed = $service->markViewed($request);

        $this->assertSame(GraphicApprovalRequest::STATUS_VIEWED, $viewed->status);
        $this->assertNotNull($viewed->viewed_at);
        $this->assertSame(GraphicApprovalRequest::STATUS_VIEWED, $service->markViewed($viewed)->status);

        $approved = $service->approve($viewed, ['customer_note' => 'Uygundur']);

        $this->assertSame(GraphicApprovalRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame('Uygundur', $approved->customer_note);
        $this->assertSame(OrderItemPrintGraphic::STATUS_APPROVED, $approved->graphic->fresh()->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED, $approved->graphic->fresh()->customer_approval_status);
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'graphic_customer_approved')->exists());
    }

    public function test_request_revision_requires_note_and_updates_request_graphic_and_logs(): void
    {
        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment] = $this->createGraphicContext('GAR-004');

        $request = $service->createRequest($graphic, $attachment, [], $this->adminUser);

        try {
            $service->requestRevision($request, '   ');
            $this->fail('Blank revision note should not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Revize isteği için not gerekli.', $exception->getMessage());
        }

        $revisioned = $service->requestRevision($request->fresh(), 'Logo hizasi duzelsin');

        $this->assertSame(GraphicApprovalRequest::STATUS_REVISION_REQUESTED, $revisioned->status);
        $this->assertSame('Logo hizasi duzelsin', $revisioned->customer_note);
        $this->assertSame(OrderItemPrintGraphic::STATUS_REVISION_REQUESTED, $revisioned->graphic->fresh()->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED, $revisioned->graphic->fresh()->customer_approval_status);
        $this->assertSame('Logo hizasi duzelsin', $revisioned->graphic->fresh()->customer_note);
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'graphic_revision_requested')->exists());
    }

    public function test_expired_request_is_marked_and_cannot_be_approved(): void
    {
        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment] = $this->createGraphicContext('GAR-005');

        $request = $service->createRequest($graphic, $attachment, [
            'expires_at' => now()->subMinute(),
        ], $this->adminUser);

        $expired = $service->markViewed($request);
        $this->assertSame(GraphicApprovalRequest::STATUS_EXPIRED, $expired->status);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Süresi dolan grafik onay isteği yanıtlanamaz.');
        $service->approve($expired->fresh(), []);
    }

    public function test_notification_failures_do_not_rollback_create_or_approve(): void
    {
        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new RuntimeException('notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $service = app(GraphicApprovalRequestService::class);
        ['graphic' => $graphic, 'attachment' => $attachment] = $this->createGraphicContext('GAR-006');

        $request = $service->createRequest($graphic, $attachment, [], $this->adminUser);

        $this->assertInstanceOf(GraphicApprovalRequest::class, $request);
        $this->assertSame(GraphicApprovalRequest::STATUS_WAITING, $request->status);
        $this->assertSame(OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING, $request->graphic->fresh()->status);

        $approved = $service->approve($service->markViewed($request), []);

        $this->assertSame(GraphicApprovalRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame(OrderItemPrintGraphic::STATUS_APPROVED, $approved->graphic->fresh()->status);
    }

    private function createGraphicContext(string $productCode): array
    {
        $workForm = $this->createConvertedWorkForm($productCode);
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['workForm', 'order.customer.contacts', 'orderItem', 'orderItemPrint.tenantPrintSetting'])
            ->orderBy('sequence_code')
            ->get()
            ->values();

        $graphic = $graphics->firstOrFail();
        $otherGraphic = $graphics->skip(1)->first() ?: $graphic;

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/customer-visible-' . Str::lower($productCode) . '.png',
            'file_name' => 'customer-visible-' . $productCode . '.png',
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

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(['workForm', 'order.customer.contacts', 'orderItem', 'orderItemPrint.tenantPrintSetting']),
            'attachment' => $attachment->fresh(),
            'otherGraphic' => $otherGraphic->fresh(),
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
                'notes' => 'Graphic approval request payload',
                'items' => [[
                    'product_name' => 'Graphic Approval Urunu ' . $productCode,
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
