<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\WorkFormQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkFormQrCodeTest extends TestCase
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

    public function test_qr_code_service_builds_public_tracking_url_and_non_empty_svg(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $service = app(WorkFormQrCodeService::class);

        $trackingUrl = $service->trackingUrl($workForm);
        $qrSvg = $service->qrSvg($workForm);
        $qrDataUri = $service->qrDataUri($workForm);

        $this->assertSame(route('public.work-forms.track', $workForm->public_tracking_token), $trackingUrl);
        $this->assertNotSame('', trim($qrSvg));
        $this->assertStringContainsString('<svg', $qrSvg);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $qrDataUri);
        $this->assertStringNotContainsString('file_path', $qrSvg);
        $this->assertStringNotContainsString('grand_total', $qrSvg);
    }

    public function test_public_tracking_route_still_works_after_qr_integration(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertOk()
            ->assertSee($workForm->work_form_number);
    }

    private function createConvertedWorkForm(): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'QR payload',
                'items' => [
                    [
                        'product_name' => 'QR Test Ürünü',
                        'product_code' => 'QR-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '10.50',
                        'discount_rate' => '30',
                        'unit_price' => '7.35',
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
                                'note' => 'Logo baskı',
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
