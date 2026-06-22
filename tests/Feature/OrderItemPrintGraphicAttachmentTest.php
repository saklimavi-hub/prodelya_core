<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderItemPrintGraphicAttachmentTest extends TestCase
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

    public function test_print_graphic_attachment_core_behaves_safely(): void
    {
        $this->assertTrue(Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_graphic_id'));

        $workForm = $this->createConvertedWorkForm();
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('id')
            ->firstOrFail();

        $service = app(WorkFormAttachmentService::class);

        $internalAttachment = $service->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image('print-graphic-internal.jpg'),
            [
                'note' => '1a iç ekip görseli',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        $graphic = $graphic->fresh(['attachments', 'latestAttachment', 'workForm']);
        $workForm = $workForm->fresh(['attachments', 'activityLogs']);

        $this->assertSame($graphic->id, $internalAttachment->order_item_print_graphic_id);
        $this->assertSame($graphic->order_item_print_id, $internalAttachment->order_item_print_id);
        $this->assertSame($graphic->id, $internalAttachment->printGraphic->id);
        $this->assertSame($internalAttachment->id, $graphic->latest_attachment_id);
        $this->assertSame($internalAttachment->id, $graphic->latestAttachment->id);
        $this->assertTrue($graphic->attachments->contains('id', $internalAttachment->id));
        $this->assertSame('visual_uploaded', $graphic->status);
        $this->assertSame('internal', $internalAttachment->visibility);
        $this->assertTrue($workForm->updated_at->gte($workForm->created_at));
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->attachment_id === $internalAttachment->id && str_contains((string) $log->note, '1a baskı operasyonuna grafik görseli eklendi')));
        Storage::disk('public')->assertExists($internalAttachment->file_path);

        $publicAttachment = $service->attachCustomerApprovalToPrintGraphic(
            $graphic,
            UploadedFile::fake()->create('customer-approval.pdf', 100, 'application/pdf'),
            [
                'note' => 'Müşteriye açık onay dosyası',
                'visibility' => 'customer_visible',
            ],
            $this->adminUser
        );

        $graphic = $graphic->fresh(['attachments', 'latestAttachment']);

        $this->assertSame($graphic->id, $publicAttachment->order_item_print_graphic_id);
        $this->assertSame('customer_visible', $publicAttachment->visibility);
        $this->assertSame($publicAttachment->id, $graphic->latest_attachment_id);

        $legacyAttachment = $service->store(
            $workForm,
            UploadedFile::fake()->image('legacy-workform-graphic.jpg'),
            'graphic_visual',
            'Eski work form akışı',
            'customer_visible',
            $this->adminUser
        );

        $this->assertNull($legacyAttachment->order_item_print_graphic_id);
        $this->assertNull($legacyAttachment->order_item_print_id);

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicTracking->assertOk();
        $publicTracking->assertSee('legacy-workform-graphic.jpg');
        $publicTracking->assertSee('customer-approval.pdf');
        $publicTracking->assertDontSee('print-graphic-internal.jpg');
        $publicTracking->assertDontSee($internalAttachment->file_path);
        $publicTracking->assertDontSee($publicAttachment->file_path);
        $publicTracking->assertDontSee('physical_path', false);

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-print-graphic',
            'panel_subdomain' => 'other-tenant-print-graphic',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $graphic->forceFill(['tenant_account_id' => $otherTenant->id])->save();

        $this->expectException(\InvalidArgumentException::class);
        $service->attachGraphicVisualToPrintGraphic(
            $graphic->fresh(),
            UploadedFile::fake()->image('forbidden-mismatch.jpg'),
            ['visibility' => 'internal'],
            $this->adminUser
        );
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
                'quote_date' => '2026-06-15',
                'valid_until' => '2026-06-22',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Per-print attachment payload',
                'items' => [[
                    'product_name' => 'Per-print Attachment Ürünü',
                    'product_code' => 'ATTACH-PRINT-001',
                    'quantity' => '10',
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
                            'print_quantity' => '10',
                            'print_unit_price' => '10',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Kutu',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '10',
                            'print_unit_price' => '5',
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

        return OrderItemWorkForm::query()->latest('id')->firstOrFail();
    }
}
