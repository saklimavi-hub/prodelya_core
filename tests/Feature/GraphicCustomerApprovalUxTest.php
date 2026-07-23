<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\GraphicApprovalRequest;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphicCustomerApprovalUxTest extends TestCase
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
            'panel_subdomain' => 'graphic-ux-guarded',
            'slug' => 'graphic-ux-guarded',
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
    }

    public function test_graphic_show_displays_prominent_customer_approval_card_and_safe_empty_state(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('GUX-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']));

        $response->assertOk();
        $response->assertSee('Müşteri Onayı');
        $response->assertSee('Henüz müşteri onay gönderimi yapılmadı.');
        $response->assertSee('Müşteri Onayına Gönder');
        $response->assertDontSee('Müşteri görünür grafik dosyası yok.');
        $response->assertDontSee('Public route henüz yok');
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('pdh_raw', false);
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('grand_total', false);
    }

    public function test_graphic_show_displays_sent_viewed_and_revision_states_without_token_leak(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('GUX-002');

        $request = app(GraphicApprovalRequestService::class)->createRequest(
            $context['graphic'],
            $context['customerVisibleGraphic'],
            [],
            $this->adminUser
        );

        $viewedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']->fresh()));

        $viewedResponse->assertOk();
        $viewedResponse->assertSee('Gönderilen Dosya');
        $viewedResponse->assertSee('Onay Linkini Aç');
        $viewedResponse->assertSee('Tekrar Gönder');
        $viewedResponse->assertSee('Müşteri Onayı');
        $viewedResponse->assertDontSee($request->token, false);

        $request->forceFill([
            'status' => GraphicApprovalRequest::STATUS_VIEWED,
            'viewed_at' => now(),
        ])->save();

        $viewedStateResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']->fresh()));

        $viewedStateResponse->assertOk();
        $viewedStateResponse->assertSee('Görüntülendi');

        app(GraphicApprovalRequestService::class)->requestRevision($request->fresh(), 'Logo biraz yukari alin.');

        $revisionResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']->fresh()));

        $revisionResponse->assertOk();
        $revisionResponse->assertSee('Revize İstendi');
        $revisionResponse->assertSee('Logo biraz yukari alin.');
        $revisionResponse->assertSee('graphic-step-panel-revision', false);
    }

    public function test_graphic_show_displays_approved_state_without_auto_production_ready_and_list_shows_customer_approval(): void
    {
        $this->enableGraphicCustomerApproval();
        $context = $this->createGraphicContext('GUX-003');

        $request = app(GraphicApprovalRequestService::class)->createRequest(
            $context['graphic'],
            $context['customerVisibleGraphic'],
            [],
            $this->adminUser
        );

        app(GraphicApprovalRequestService::class)->approve($request, []);

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']->fresh()));

        $showResponse->assertOk();
        $showResponse->assertSee('Onaylandı');
        $showResponse->assertSee('Görsel müşteri tarafından onaylandı. Üretime göndermeden önce son kontrol yapılmalı.');

        $this->assertNotSame(
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            $context['graphic']->fresh()->status
        );

        $indexResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $indexResponse->assertOk();
        $indexResponse->assertOk();
        $indexResponse->assertSee('Onaylandı');
        $indexResponse->assertSee('Onay var, üretime hazırlık kararı ayrı verilir.');
    }

    public function test_feature_guard_hides_customer_approval_card_when_feature_is_closed(): void
    {
        $context = $this->createGraphicContext('GUX-004');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $context['workForm']));

        $response->assertOk();
        $response->assertDontSee('Müşteri Onayı');
        $response->assertDontSee('Müşteri Onayına Gönder');
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

        $customerVisibleGraphic = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/customer-visible-' . $productCode . '.png',
            'file_name' => 'customer-visible-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteri onay görseli',
            'sort_order' => 1,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $customerVisibleGraphic->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(),
            'customerVisibleGraphic' => $customerVisibleGraphic->fresh(),
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
                'notes' => 'Graphic customer approval ux payload',
                'items' => [[
                    'product_name' => 'Graphic UX Ürünü ' . $productCode,
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
