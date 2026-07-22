<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\ProductionWorkflowService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicWorkFormTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_public_tracking_screen_opens_without_auth_and_shows_public_safe_work_form_data(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $response->assertOk();
        $response->assertSee($workForm->work_form_number);
        $response->assertSee(data_get($workForm->order_snapshot, 'document_number'));
        $response->assertSee(data_get($workForm->product_snapshot, 'product_name'));
        $response->assertSee('100 Adet');
        $response->assertSee('Sipariş Takibi');
        $response->assertSee('Müşteri Takip Ekranı');
        $response->assertSee('Siparişiniz şu aşamada');
        $response->assertSee('Ürününüz hazırlanıyor');
        $response->assertSee('Üretim ve Teslimat');
        $response->assertSee('Üretim bekliyor');
        $response->assertSee('Sipariş Süreci');
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('line_total', false);
        $response->assertDontSee('print_total', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('KDV');
        $response->assertDontSee('İskonto');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('supplier_stock_quantity', false);
        $response->assertDontSee('local_stock_quantity', false);
        $response->assertDontSee('purchase_cost', false);
        $response->assertDontSee('Tedarikçi Referans Stoğu');
    }

    public function test_invalid_or_cancelled_public_tracking_token_returns_not_found(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', 'gecersiz-token'))
            ->assertNotFound();

        $workForm->forceFill(['status' => 'cancelled'])->save();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertNotFound();
    }

    public function test_public_tracking_shows_only_customer_visible_attachments(): void
    {
        $workForm = $this->createConvertedWorkForm();

        Storage::disk('public')->put('work-forms/public-visible.jpg', 'visible-image-content');
        Storage::disk('public')->put('work-forms/public-production.jpg', 'visible-production-image');
        Storage::disk('public')->put('work-forms/internal-only.jpg', 'internal-image-content');

        $publicAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_photo',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/public-visible.jpg',
            'file_name' => 'musteri-teslim.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'sort_order' => 1,
        ]);

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'production_photo',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/public-production.jpg',
            'file_name' => 'musteri-uretim.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'sort_order' => 2,
        ]);

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'production_photo',
            'visibility' => 'internal',
            'file_path' => 'work-forms/internal-only.jpg',
            'file_name' => 'ic-uretim.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'sort_order' => 3,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $response->assertOk();
        $response->assertSee('musteri-teslim.jpg');
        $response->assertSee('musteri-uretim.jpg');
        $response->assertSee('Müşteriye Açık');
        $response->assertSee('dosya/' . $publicAttachment->id, false);
        $response->assertDontSee($workForm->public_tracking_token);
        $response->assertDontSee('ic-uretim.jpg');
        $response->assertDontSee('work-forms/public-visible.jpg');
        $response->assertDontSee('work-forms/internal-only.jpg');
    }

    public function test_public_tracking_uses_customer_safe_production_labels_for_internal_qc_and_completed_states(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $production = $workForm->printProductions()->firstOrFail();
        $this->prepareProductionForStart($production, 'public-track-ready.jpg');
        $workflow = app(ProductionWorkflowService::class);

        $workflow->updateAssignment($production->fresh(), [
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_unit_name' => 'UV Hattı 1',
            'assigned_to' => $this->adminUser->id,
        ], $this->adminUser, 'Operatör seçildi');
        $workflow->assignInternal($production->fresh(), $this->adminUser, 'UV Hattı 1');
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->fresh()->public_tracking_token));
        $response->assertOk();
        $response->assertSee('Üretimde');

        $workflow->markQcStarted($production->fresh(), $this->adminUser);
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->fresh()->public_tracking_token));
        $response->assertOk();
        $response->assertSee('Kalite kontrolde');

        $workflow->markCompleted($production->fresh(), $this->adminUser);
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->fresh()->public_tracking_token));
        $response->assertOk();
        $response->assertSee('Üretim tamamlandı');
        $response->assertDontSee('İç kalite kontrol notu');
    }

    public function test_public_tracking_shows_only_customer_visible_activity_logs(): void
    {
        $workForm = $this->createConvertedWorkForm();

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'delivery_photo_added',
            'note' => 'Müşteriye açık teslim fotoğrafı paylaşıldı',
            'visibility' => 'customer_visible',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'production_photo_added',
            'note' => 'İç kalite kontrol notu',
            'visibility' => 'internal',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $response->assertOk();
        $response->assertSee('Teslimat fotoğrafı eklendi');
        $response->assertSee('Müşteriye açık teslim fotoğrafı paylaşıldı');
        $response->assertDontSee('Üretim fotoğrafı eklendi');
        $response->assertDontSee('İç kalite kontrol notu');
    }

    public function test_admin_work_form_show_displays_public_tracking_link(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $response->assertOk();
        $response->assertSee('Müşteri Takip Linki');
        $response->assertSee(route('public.work-forms.track', $workForm->public_tracking_token), false);
    }

    public function test_customer_visible_image_attachment_opens_via_secure_public_endpoint(): void
    {
        $workForm = $this->createConvertedWorkForm();
        Storage::disk('public')->put('work-forms/customer-visible-image.jpg', 'customer-visible-image-content');

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_photo',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/customer-visible-image.jpg',
            'file_name' => 'teslim-fotografi.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $attachment->id,
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertSee('customer-visible-image-content');
    }

    public function test_customer_visible_pdf_attachment_opens_via_secure_public_endpoint(): void
    {
        $workForm = $this->createConvertedWorkForm();
        Storage::disk('public')->put('work-forms/customer-visible-document.pdf', 'customer-visible-pdf-content');

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_document',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/customer-visible-document.pdf',
            'file_name' => 'teslim-belgesi.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 'public',
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $attachment->id,
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertSee('customer-visible-pdf-content');
    }

    public function test_internal_attachment_or_mismatched_token_cannot_be_opened_publicly(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $otherWorkForm = $this->createConvertedWorkForm();
        Storage::disk('public')->put('work-forms/internal-attachment.jpg', 'internal-secret-content');
        Storage::disk('public')->put('work-forms/public-attachment.jpg', 'public-cross-check-content');

        $internalAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'production_photo',
            'visibility' => 'internal',
            'file_path' => 'work-forms/internal-attachment.jpg',
            'file_name' => 'internal.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
        ]);

        $publicAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_photo',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/public-attachment.jpg',
            'file_name' => 'public.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalAttachment->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $otherWorkForm->public_tracking_token,
                'attachment' => $publicAttachment->id,
            ]))
            ->assertNotFound();
    }

    public function test_invalid_token_or_cancelled_work_form_attachment_cannot_be_opened(): void
    {
        $workForm = $this->createConvertedWorkForm();
        Storage::disk('public')->put('work-forms/cancelled-public.jpg', 'cancelled-public-content');

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_photo',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/cancelled-public.jpg',
            'file_name' => 'cancelled.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => 'gecersiz-token',
                'attachment' => $attachment->id,
            ]))
            ->assertNotFound();

        $workForm->forceFill(['status' => 'cancelled'])->save();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $attachment->id,
            ]))
            ->assertNotFound();
    }

    private function createConvertedWorkForm(): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Public tracking payload',
                'items' => [
                    [
                        'product_name' => 'Public Tracking Ürün',
                        'product_code' => 'PUBLIC-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '9.80',
                        'discount_rate' => '35',
                        'unit_price' => '6.37',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '1',
                        'prints' => [
                            [
                                'print_type' => 'UV Baskı',
                                'print_option' => 'Tek taraf baskılı',
                                'production_type' => 'İç üretim',
                                'print_quantity' => '100',
                                'print_unit_price' => '4',
                                'note' => 'Müşteri logosu',
                            ],
                            [
                                'print_type' => 'Sıcak Baskı',
                                'print_option' => 'Gövde baskı',
                                'production_type' => 'Dış üretim / Fason',
                                'subcontractor_company_id' => $partner->id,
                                'print_quantity' => '100',
                                'print_unit_price' => '6',
                                'note' => 'İsim baskı',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return OrderItemWorkForm::query()->latest('id')->firstOrFail();
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $production = $production->fresh(['workForm.procurement', 'orderItemPrint.graphicOperation']);

        /** @var OrderItemPrintGraphic $graphic */
        $graphic = $production->orderItemPrint?->graphicOperation;
        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Public tracking final görseli',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm?->procurement;
        $this->assertNotNull($procurement);

        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
        ])->save();

        $production->workForm?->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'public_status_label' => 'Ürün üretime hazır',
                ]
            ),
        ])->save();
    }
}
