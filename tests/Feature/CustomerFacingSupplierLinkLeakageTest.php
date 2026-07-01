<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\Notifications\NotificationVariableBuilder;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFacingSupplierLinkLeakageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_public_work_form_tracking_does_not_expose_supplier_links_or_external_image(): void
    {
        $workForm = $this->createConvertedWorkForm('PUBLIC-SAFE-001');

        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://supplier-images.example.invalid/public-track.jpg',
                'product_url' => 'https://supplier.example.invalid/product-page',
                'detail_url' => 'https://supplier.example.invalid/detail-page',
            ]),
        ])->save();

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $response->assertOk();
        $response->assertDontSee('https://supplier-images.example.invalid/public-track.jpg', false);
        $response->assertDontSee('https://supplier.example.invalid/product-page', false);
        $response->assertDontSee('https://supplier.example.invalid/detail-page', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('storage_path', false);
    }

    public function test_work_form_pdf_does_not_render_supplier_links_or_external_image(): void
    {
        $workForm = $this->createConvertedWorkForm('PDF-SAFE-001');

        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://supplier-images.example.invalid/work-form-pdf.jpg',
                'product_url' => 'https://supplier.example.invalid/pdf-product-page',
                'detail_url' => 'https://supplier.example.invalid/pdf-detail-page',
                'file_path' => 'C:\\secret\\product-proof.png',
                'physical_path' => 'C:\\secret\\physical-proof.png',
                'storage_path' => 'storage/private/products/secret-proof.png',
            ]),
        ])->save();

        $html = app(WorkFormPdfService::class)->renderHtml($workForm->fresh([
            'attachments',
            'activityLogs.attachment',
            'tenant',
        ]));

        $this->assertStringNotContainsString('https://supplier-images.example.invalid/work-form-pdf.jpg', $html);
        $this->assertStringNotContainsString('https://supplier.example.invalid/pdf-product-page', $html);
        $this->assertStringNotContainsString('https://supplier.example.invalid/pdf-detail-page', $html);
        $this->assertStringNotContainsString('file_path', $html);
        $this->assertStringNotContainsString('physical_path', $html);
        $this->assertStringNotContainsString('storage_path', $html);
    }

    public function test_customer_notification_variables_do_not_include_supplier_links(): void
    {
        $workForm = $this->createConvertedWorkForm('NOTIFY-SAFE-001');
        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://supplier-images.example.invalid/notify.jpg',
                'product_url' => 'https://supplier.example.invalid/notify-product-page',
                'detail_url' => 'https://supplier.example.invalid/notify-detail-page',
            ]),
        ])->save();

        $variables = app(NotificationVariableBuilder::class)->buildForWorkForm(
            $workForm->fresh(['order.customer.contacts', 'orderItem', 'delivery']),
            NotificationTemplate::AUDIENCE_CUSTOMER
        );

        $this->assertArrayNotHasKey('image_url', $variables);
        $this->assertArrayNotHasKey('product_url', $variables);
        $this->assertArrayNotHasKey('detail_url', $variables);
        $this->assertStringNotContainsString('supplier.example.invalid', json_encode($variables, JSON_UNESCAPED_UNICODE));
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-24',
                'valid_until' => '2026-06-30',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Customer-facing safe image leakage regression',
                'items' => [[
                    'product_name' => 'Customer Facing Güvenli Ürün',
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '10.00',
                    'discount_rate' => '20',
                    'unit_price' => '8.00',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf baskılı',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '100',
                        'print_unit_price' => '2',
                        'note' => 'Logo baskı',
                    ]],
                ]],
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
