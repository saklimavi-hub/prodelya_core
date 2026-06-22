<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicTrackingSecurityHardeningTest extends TestCase
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

    public function test_public_tracking_stays_open_for_valid_active_tokens_without_auth_or_optional_module_access(): void
    {
        $workForm = $this->createConvertedWorkForm();

        TenantSetting::setValue($workForm->tenant_account_id, 'enable_customer_portal', false, 'boolean');
        TenantSetting::setValue($workForm->tenant_account_id, 'portal_enabled', false, 'boolean');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertOk()
            ->assertSee($workForm->work_form_number);

        $workForm->forceFill(['status' => 'inactive'])->save();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertNotFound();

        $workForm->forceFill(['status' => 'cancelled'])->save();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', 'gecersiz-veya-bos-token'))
            ->assertNotFound();
    }

    public function test_public_tracking_response_strips_forbidden_finance_path_and_internal_markers(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'storage/private/products/secret-product.jpg',
            ]),
            'print_snapshot' => [[
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf baskılı',
                'production_type' => 'İç üretim',
                'print_quantity' => 100,
                'note' => 'setup_cost 500 / unit_price 25 / file_path C:\\private\\proof.pdf',
            ]],
            'procurement_snapshot' => array_merge($workForm->procurement_snapshot ?? [], [
                'public_status_label' => 'Ürününüz hazırlanıyor',
                'purchase_total' => 9999,
                'supplier_cost' => 444,
                'group_code' => 'PROC-GRP-001',
            ]),
            'production_snapshot' => array_merge($workForm->production_snapshot ?? [], [
                'public_status_label' => 'Üretim bekliyor',
                'profit' => 1200,
            ]),
            'delivery_snapshot' => array_merge($workForm->delivery_snapshot ?? [], [
                'public_status_label' => 'Teslimata hazırlanıyor',
                'finance warning' => 'Bakiye bekleniyor',
                'balance_due' => 2300,
            ]),
        ])->save();

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_document',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/private-group-code.pdf',
            'file_name' => 'group_code-report.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 'public',
            'note' => 'payment_amount 900 / raw json / storage/app/private/secret.pdf',
        ]);

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'delivery_document_added',
            'note' => 'notification_logs token api_key physical_path C:\\secret\\delivery.pdf',
            'visibility' => 'customer_visible',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $response->assertOk();
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('print_unit_price', false);
        $response->assertDontSee('print_total', false);
        $response->assertDontSee('subtotal', false);
        $response->assertDontSee('vat_total', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('payment_amount', false);
        $response->assertDontSee('balance_due', false);
        $response->assertDontSee('supplier_cost', false);
        $response->assertDontSee('purchase_total', false);
        $response->assertDontSee('profit', false);
        $response->assertDontSee('setup_cost', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('notification_logs', false);
        $response->assertDontSee('api_key', false);
        $response->assertDontSee('token', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('storage/private/products/secret-product.jpg', false);
        $response->assertDontSee('storage/app/private/secret.pdf', false);
        $response->assertDontSee('group_code-report.pdf');
        $response->assertDontSee('Bakiye bekleniyor');
    }

    public function test_public_attachment_endpoint_only_serves_active_customer_visible_matching_delivery_files(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $otherWorkForm = $this->createConvertedWorkForm();

        Storage::disk('public')->put('work-forms/public-delivery-photo.jpg', 'public-delivery-photo');
        Storage::disk('public')->put('work-forms/public-delivery-document.pdf', 'public-delivery-document');
        Storage::disk('public')->put('work-forms/internal-delivery-document.pdf', 'internal-delivery-document');

        $publicPhoto = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_photo',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/public-delivery-photo.jpg',
            'file_name' => 'teslim-fotografi.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
        ]);

        $publicDocument = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_document',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/public-delivery-document.pdf',
            'file_name' => 'teslim-belgesi.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 'public',
        ]);

        $internalDocument = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_document',
            'visibility' => 'internal',
            'file_path' => 'work-forms/internal-delivery-document.pdf',
            'file_name' => 'ic-belge.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 'public',
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $publicPhoto->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $publicDocument->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="teslim-belgesi.pdf"');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalDocument->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $otherWorkForm->public_tracking_token,
                'attachment' => $publicDocument->id,
            ]))
            ->assertNotFound();

        $workForm->forceFill(['status' => 'cancelled'])->save();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $publicPhoto->id,
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
}
