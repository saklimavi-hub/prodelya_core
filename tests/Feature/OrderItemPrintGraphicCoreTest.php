<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\OrderItemPrintGraphicCreationService;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderItemPrintGraphicCoreTest extends TestCase
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

    public function test_print_graphic_core_model_and_workflow_behave_safely(): void
    {
        $this->assertTrue(Schema::hasTable('order_item_print_graphics'));
        $this->assertTrue(Schema::hasColumn('order_item_print_graphics', 'order_item_print_id'));
        $this->assertTrue(Schema::hasColumn('order_item_print_graphics', 'sequence_code'));
        $this->assertTrue(Schema::hasColumn('order_item_print_graphics', 'latest_attachment_id'));

        $order = $this->createConvertedOrderWithPrints([
            ['print_type' => 'Lazer', 'print_option' => 'İsim lazer'],
            ['print_type' => 'UV Baskı', 'print_option' => 'Tek taraf'],
            ['print_type' => 'Serigrafi', 'print_option' => 'Gövde'],
            ['print_type' => 'Lazer', 'print_option' => 'Kutu'],
        ], 'PPG-001');

        $workForm = OrderItemWorkForm::query()->where('order_id', $order->id)->firstOrFail();
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $prints = OrderItemPrint::query()->where('order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(4, $prints);

        $graphics = OrderItemPrintGraphic::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $graphics);
        $this->assertEqualsCanonicalizing($prints->pluck('id')->all(), $graphics->pluck('order_item_print_id')->all());
        $this->assertEquals(['1a', '1b', '1c', '1d'], $graphics->pluck('sequence_code')->all());
        $this->assertTrue($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->order_item_work_form_id === $workForm->id));
        $this->assertTrue($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL));
        $this->assertTrue($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->customer_approval_status === OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED));
        $this->assertSame($prints->first()->id, $graphics->first()->orderItemPrint->id);

        $duplicateCheck = app(OrderItemPrintGraphicCreationService::class)->ensureForOrderItemPrint($prints->first(), $this->adminUser);
        $this->assertSame($graphics->first()->id, $duplicateCheck->id);
        $this->assertSame(4, OrderItemPrintGraphic::query()->where('order_id', $order->id)->count());

        $workflow = app(OrderItemPrintGraphicWorkflowService::class);
        $attachmentService = app(WorkFormAttachmentService::class);
        $firstGraphic = $graphics->firstOrFail();
        $attachmentService->attachGraphicVisualToPrintGraphic(
            $firstGraphic,
            UploadedFile::fake()->image('ppg-core-first.jpg'),
            ['visibility' => 'internal'],
            $this->adminUser
        );
        $uploaded = $workflow->markVisualUploaded($firstGraphic, $this->adminUser);
        $this->assertSame(OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED, $uploaded->status);

        $approved = $workflow->markApproved($uploaded, $this->adminUser);
        $this->assertSame(OrderItemPrintGraphic::STATUS_APPROVED, $approved->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED, $approved->customer_approval_status);
        $this->assertNotNull($approved->approved_at);

        $ready = $workflow->markProductionReady($approved, $this->adminUser);
        $this->assertSame(OrderItemPrintGraphic::STATUS_PRODUCTION_READY, $ready->status);
        $this->assertNotNull($ready->production_ready_at);

        $revisionGraphic = $graphics->skip(1)->firstOrFail();
        $revisionGraphic = $workflow->requestRevision($revisionGraphic, 'Yalnız bu baskı revize olacak.', $this->adminUser);
        $this->assertSame(OrderItemPrintGraphic::STATUS_REVISION_REQUESTED, $revisionGraphic->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED, $revisionGraphic->customer_approval_status);
        $this->assertSame('Yalnız bu baskı revize olacak.', $revisionGraphic->customer_note);
        $this->assertNotNull($revisionGraphic->revision_requested_at);

        $this->expectException(\InvalidArgumentException::class);
        $workflow->markProductionReady($revisionGraphic, $this->adminUser);
    }

    public function test_no_print_order_conversion_does_not_create_print_graphics(): void
    {
        $order = $this->createConvertedOrderWithPrints([], 'PPG-NOPRINT-001', false);

        $this->assertSame(0, OrderItemPrint::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderItemPrintGraphic::query()->where('order_id', $order->id)->count());
    }

    public function test_creation_service_can_attach_work_form_when_print_graphic_is_created_before_work_form_exists(): void
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $order = Order::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-MANUAL-PPG-001',
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'product_total' => 1000,
            'print_total' => 100,
            'subtotal' => 1100,
            'vat_total' => 220,
            'grand_total' => 1320,
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Elle oluşturulan baskılı ürün',
            'product_code' => 'MAN-PPG-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'unit_price' => 100,
            'line_total' => 1000,
            'has_print' => true,
            'print_total' => 100,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 10,
            'print_unit_price' => 10,
            'print_total' => 100,
            'status' => 'pending',
        ]);

        $graphic = app(OrderItemPrintGraphicCreationService::class)->ensureForOrderItemPrint($print, $this->adminUser);
        $this->assertNull($graphic->order_item_work_form_id);
        $this->assertSame('1a', $graphic->sequence_code);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_number' => 'IF-MANUAL-001',
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'manual-print-graphic-token',
            'print_snapshot' => [['sequence' => '1a', 'print_type' => 'UV Baskı', 'print_option' => 'Tek taraf']],
            'graphic_snapshot' => ['status' => 'bekliyor'],
            'production_snapshot' => ['status' => 'bekliyor'],
            'delivery_snapshot' => ['status' => 'teslimat_bekliyor'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $reattached = app(OrderItemPrintGraphicCreationService::class)->ensureForOrderItemPrint($print, $this->adminUser);

        $this->assertSame($graphic->id, $reattached->id);
        $this->assertSame($workForm->id, $reattached->order_item_work_form_id);
        $this->assertSame($customer->tenant_account_id, $reattached->tenant_account_id);
        $this->assertArrayNotHasKey('group_code', $reattached->toArray());
        $this->assertArrayNotHasKey('price_snapshot', $reattached->toArray());
    }

    private function createConvertedOrderWithPrints(array $prints, string $productCode, bool $hasPrint = true): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->first();

        $printPayload = collect($prints)->map(function (array $print) use ($partner) {
            return [
                'print_type' => $print['print_type'],
                'print_option' => $print['print_option'],
                'production_type' => 'İç üretim',
                'subcontractor_company_id' => $partner?->id,
                'print_quantity' => '10',
                'print_unit_price' => '10',
                'note' => 'Test baskı notu',
            ];
        })->all();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-15',
                'valid_until' => '2026-06-22',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Print graphic core test payload',
                'items' => [[
                    'product_name' => 'Print Graphic Core Ürünü',
                    'product_code' => $productCode,
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => $hasPrint ? '1' : '0',
                    'prints' => $hasPrint ? $printPayload : [],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return Order::query()
            ->orders()
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();
    }
}
