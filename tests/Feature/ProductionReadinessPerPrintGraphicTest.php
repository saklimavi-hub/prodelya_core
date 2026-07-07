<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\ProcurementWorkflowService;
use App\Services\ProductionReadinessResolver;
use App\Services\ProductionWorkflowService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionReadinessPerPrintGraphicTest extends TestCase
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

    public function test_per_print_readiness_uses_graphic_operation_instead_of_work_form_snapshot(): void
    {
        $workForm = $this->createConvertedWorkForm('PRP-READY-001');
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with('orderItemPrint')
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');
        $productions = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->with(['graphicOperation.latestAttachment', 'orderItemPrint'])
            ->orderBy('order_item_print_id')
            ->get()
            ->keyBy(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_option);

        $this->prepareGraphicAsProductionReady($graphics['1a'], 'one-a-final.jpg');
        $workForm->forceFill([
            'graphic_snapshot' => array_merge($workForm->graphic_snapshot ?? [], ['status' => 'uretime_hazir']),
        ])->save();

        $resolver = app(ProductionReadinessResolver::class);
        $ready = $resolver->resolve($productions['Tek taraf']->fresh(['graphicOperation.latestAttachment', 'workForm.procurement', 'workForm.attachments']));
        $waiting = $resolver->resolve($productions['Gövde']->fresh(['graphicOperation.latestAttachment', 'workForm.procurement', 'workForm.attachments']));

        $this->assertTrue($ready['graphic_ready']);
        $this->assertFalse($waiting['graphic_ready']);
        $this->assertSame('Grafik Hazır, Tedarik Bekliyor', $ready['graphic_status_label']);
        $this->assertSame('Grafik Bekliyor', $waiting['graphic_status_label']);
        $this->assertSame('one-a-final.jpg', $ready['final_graphic_attachment']->file_name);
        $this->assertNull($waiting['final_graphic_attachment']);
    }

    public function test_workflow_blocks_revision_approved_without_ready_and_missing_final_visual_cases(): void
    {
        $workForm = $this->createConvertedWorkForm('PRP-BLOCK-001');
        $graphic = OrderItemPrintGraphic::query()->where('order_item_work_form_id', $workForm->id)->orderBy('sequence_code')->firstOrFail();
        $production = OrderItemPrintProduction::query()->where('order_item_print_id', $graphic->order_item_print_id)->firstOrFail();
        $graphicWorkflow = app(OrderItemPrintGraphicWorkflowService::class);
        $productionWorkflow = app(ProductionWorkflowService::class);

        $graphicWorkflow->requestRevision($graphic, 'Revize gerekli.', $this->adminUser);

        $this->expectExceptionMessage('Bu baskı revize bekliyor, üretime başlanamaz.');
        $productionWorkflow->assignInternal($production->fresh(), $this->adminUser, 'UV Hattı 1');
    }

    public function test_marking_production_ready_requires_latest_attachment_and_procurement_ready_to_start(): void
    {
        $workForm = $this->createConvertedWorkForm('PRP-PROC-001');
        $graphic = OrderItemPrintGraphic::query()->where('order_item_work_form_id', $workForm->id)->orderBy('sequence_code')->firstOrFail();
        $production = OrderItemPrintProduction::query()->where('order_item_print_id', $graphic->order_item_print_id)->firstOrFail();
        $procurement = $workForm->procurement()->firstOrFail();
        $graphicWorkflow = app(OrderItemPrintGraphicWorkflowService::class);
        $productionWorkflow = app(ProductionWorkflowService::class);
        $procurementWorkflow = app(ProcurementWorkflowService::class);

        $graphicWorkflow->markVisualUploaded($graphic, $this->adminUser);
        $approved = $graphicWorkflow->markApproved($graphic->fresh(), $this->adminUser);

        try {
            $graphicWorkflow->markProductionReady($approved, $this->adminUser);
            $this->fail('Expected production ready validation for missing latest attachment.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Final grafik görseli olmadan üretime hazırlanamaz.', $exception->getMessage());
        }

        $graphic = $this->prepareGraphicAsProductionReady($graphic->fresh(), 'proc-ready.jpg');

        try {
            $productionWorkflow->assignInternal($production->fresh(), $this->adminUser, 'UV Hattı 1');
            $this->fail('Expected procurement readiness validation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Ürün tedariki tamamlanmadan üretime başlanamaz.', $exception->getMessage());
        }

        $procurementWorkflow->markPartiallyReceived($procurement->fresh(), 10, $this->adminUser);

        try {
            $productionWorkflow->assignInternal($production->fresh(), $this->adminUser, 'UV Hattı 1');
            $this->fail('Expected partial procurement to remain blocked.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Ürün tedariki tamamlanmadan üretime başlanamaz.', $exception->getMessage());
        }

        $procurementWorkflow->markFullyReceived($procurement->fresh(), $this->adminUser);
        $started = $productionWorkflow->assignInternal($production->fresh(), $this->adminUser, 'UV Hattı 1');

        $this->assertSame(OrderItemPrintProduction::STATUS_INTERNAL, $started->production_status);
        $this->assertSame('UV Hattı 1', $started->production_unit_name);
        $this->assertSame($graphic->latest_attachment_id, $started->graphicOperation->latest_attachment_id);
    }

    public function test_production_filters_and_views_use_per_print_final_graphic_source(): void
    {
        $workForm = $this->createConvertedWorkForm('PRP-VIEW-001');
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');
        $oneA = $this->prepareGraphicAsProductionReady($graphics['1a'], 'one-a-preview.jpg');
        $oneB = $this->prepareGraphicAsProductionReady($graphics['1b'], 'one-b-preview.jpg');

        $readyResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['graphic_ready' => 'evet']));

        $readyResponse->assertOk();
        $readyResponse->assertSee('one-a-preview.jpg');
        $readyResponse->assertSee('one-b-preview.jpg');
        $readyResponse->assertDontSee('Final Görsel Yok');

        $notReadyResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['graphic_ready' => 'hayir']));

        $notReadyResponse->assertOk();
        $notReadyResponse->assertDontSee('one-a-preview.jpg');

        $production = OrderItemPrintProduction::query()
            ->where('order_item_print_id', $oneA->order_item_print_id)
            ->firstOrFail();

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', [
                $production,
                'tab' => 'genel',
            ]));

        $showResponse->assertOk();
        $showResponse->assertSee(route('admin.work-forms.attachments.preview', $oneA->latest_attachment_id), false);
        $showResponse->assertDontSee(route('admin.work-forms.attachments.preview', $oneB->latest_attachment_id), false);
        $showResponse->assertDontSee('file_path', false);
        $showResponse->assertDontSee('physical_path', false);
        $showResponse->assertDontSee('group_code', false);
    }

    private function prepareGraphicAsProductionReady(OrderItemPrintGraphic $graphic, string $fileName): OrderItemPrintGraphic
    {
        $attachmentService = app(WorkFormAttachmentService::class);
        $workflow = app(OrderItemPrintGraphicWorkflowService::class);

        $attachmentService->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            ['visibility' => 'internal', 'note' => $fileName],
            $this->adminUser
        );

        $workflow->markApproved($graphic->fresh(), $this->adminUser);

        return $workflow->markProductionReady($graphic->fresh(), $this->adminUser);
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-16',
                'valid_until' => '2026-06-23',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Production readiness per-print payload',
                'items' => [[
                    'product_name' => 'Production Readiness Ürünü',
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
                            'print_unit_price' => '10',
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
