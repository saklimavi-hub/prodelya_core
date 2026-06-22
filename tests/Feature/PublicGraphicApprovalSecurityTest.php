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
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicGraphicApprovalSecurityTest extends TestCase
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

    public function test_public_show_uses_request_attachment_and_hides_sensitive_fields(): void
    {
        $context = $this->createApprovalContext('PGAS-001');

        $context['graphic']->forceFill([
            'latest_attachment_id' => $context['latestAttachment']->id,
        ])->save();

        $response = $this->get(route('public.graphics.approval.show', ['token' => $context['request']->token]));

        $response->assertOk();
        $response->assertSee('request-attachment-PGAS-001.png');
        $response->assertDontSee('latest-attachment-PGAS-001.png');
        $response->assertDontSee($context['request']->token);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('storage/app', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('pdh_raw', false);
        $response->assertDontSee('purchase_total', false);
        $response->assertDontSee('subcontractor_cost', false);
        $response->assertDontSee('setup_cost', false);
        $response->assertDontSee('balance_due', false);
        $response->assertDontSee('notification_logs', false);
        $response->assertDontSee('KDV', false);
        $response->assertDontSee('Toplam', false);
    }

    public function test_internal_or_mismatched_attachments_are_not_publicly_rendered_and_public_tracking_still_works(): void
    {
        $internalContext = $this->createApprovalContext('PGAS-002');
        $internalContext['request']->attachment()->update(['visibility' => 'internal']);

        $this->get(route('public.graphics.approval.show', ['token' => $internalContext['request']->token]))
            ->assertNotFound();

        $differentGraphicContext = $this->createApprovalContext('PGAS-003');
        $differentGraphicContext['request']->forceFill([
            'attachment_id' => $differentGraphicContext['otherGraphicAttachment']->id,
        ])->save();

        $this->get(route('public.graphics.approval.show', ['token' => $differentGraphicContext['request']->token]))
            ->assertNotFound();

        $foreignTenantContext = $this->createApprovalContext('PGAS-004');
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-public-graphic',
            'panel_subdomain' => 'other-tenant-public-graphic',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $foreignTenantContext['request']->attachment()->update(['tenant_account_id' => $otherTenant->id]);

        $this->get(route('public.graphics.approval.show', ['token' => $foreignTenantContext['request']->token]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $internalContext['workForm']->fresh()->public_tracking_token))
            ->assertOk();
    }

    private function createApprovalContext(string $productCode): array
    {
        $workForm = $this->createConvertedWorkForm($productCode);
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->values();

        $graphic = $graphics->firstOrFail();
        $otherGraphic = $graphics->skip(1)->first() ?: $graphic;

        $requestAttachment = $this->createAttachment($workForm, $graphic, 'request-attachment-' . $productCode . '.png');
        $latestAttachment = $this->createAttachment($workForm, $graphic, 'latest-attachment-' . $productCode . '.png');
        $otherGraphicAttachment = $this->createAttachment($workForm, $otherGraphic, 'other-graphic-' . $productCode . '.png');

        $graphic->forceFill([
            'latest_attachment_id' => $requestAttachment->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $approvalRequest = app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh(),
            $requestAttachment,
            [],
            $this->adminUser
        );

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(),
            'request' => $approvalRequest->fresh(),
            'requestAttachment' => $requestAttachment->fresh(),
            'latestAttachment' => $latestAttachment->fresh(),
            'otherGraphicAttachment' => $otherGraphicAttachment->fresh(),
        ];
    }

    private function createAttachment(OrderItemWorkForm $workForm, OrderItemPrintGraphic $graphic, string $fileName): OrderItemWorkFormAttachment
    {
        return OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/' . $fileName,
            'file_name' => $fileName,
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteri onay görseli',
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
                'notes' => 'Public graphic approval security payload',
                'items' => [[
                    'product_name' => 'Public Graphic Security Ürünü ' . $productCode,
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
