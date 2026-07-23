<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\ProductionCreationService;
use App\Services\WorkFormPdfService;
use App\Services\WorkFormQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionEndToEndSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_production_end_to_end_smoke_flow_is_safe_and_complete(): void
    {
        $supplierSource = $this->createSupplierSource('PROD-SMOKE');
        $partner = $this->createPartnerCompany();
        $order = $this->createConvertedOrderWithProductionScenario($supplierSource);

        $order->load([
            'items.prints.production.productionCompany',
            'items.procurement.workForm.activityLogs',
            'items.workForm.attachments',
            'procurements',
        ]);

        $this->assertCount(1, $order->items);
        $this->assertCount(1, $order->procurements);

        $item = $order->items->first();
        $this->assertNotNull($item);
        $this->assertTrue($item->has_print);
        $this->assertCount(2, $item->prints);
        $this->assertNotNull($item->workForm);
        $this->assertNotNull($item->procurement);

        $workForm = $item->workForm->fresh(['attachments', 'activityLogs']);
        $procurement = $item->procurement->fresh(['workForm']);
        $productions = $item->prints
            ->map(fn ($print) => $print->production?->fresh(['workForm', 'orderItemPrint', 'productionCompany']))
            ->filter()
            ->values();

        $this->assertCount(2, $productions);
        $this->assertEqualsCanonicalizing(
            $item->prints->pluck('id')->all(),
            $productions->pluck('order_item_print_id')->all()
        );

        foreach ($productions as $production) {
            $this->assertSame((float) $production->orderItemPrint->print_quantity, (float) $production->planned_quantity);
            $this->assertSame(0.0, (float) $production->completed_quantity);
            $this->assertSame((float) $production->planned_quantity, (float) $production->remaining_quantity);
            $this->assertSame(OrderItemPrintProduction::STATUS_PENDING, $production->production_status);
        }

        app(ProductionCreationService::class)->createForOrder($order, $this->adminUser);
        $this->assertSame(2, OrderItemPrintProduction::query()->where('order_id', $order->id)->count());

        $productionIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $productionIndex->assertOk();
        $productionIndex->assertSee('Üretim / Fason');
        $productionIndex->assertSee('Havuz Özeti');
        $productionIndex->assertSee($order->document_number);
        $productionIndex->assertSee($workForm->work_form_number);
        $productionIndex->assertSee('UV Baskı');
        $productionIndex->assertSee('Sıcak Baskı');
        $productionIndex->assertSee('Bu baskı için grafik üretime hazır değil.');
        $productionIndex->assertSee('Tedarik Bekliyor');
        $this->assertSafeOutput($productionIndex->getContent());

        $internalProduction = $productions->first(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_type === 'UV Baskı');
        $externalProduction = $productions->first(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_type !== 'UV Baskı');

        $this->assertNotNull($internalProduction);
        $this->assertNotNull($externalProduction);

        $productionShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', [
                $internalProduction,
                'tab' => 'genel',
            ]));

        $productionShow->assertOk();
        $productionShow->assertSee('Üretim Detayı');
        $productionShow->assertSee('Grafik Bekliyor');
        $productionShow->assertSee('Tedarik Bekliyor');
        $productionShow->assertSee('Fotoğraflar');
        $this->assertSafeOutput($productionShow->getContent());

        $initialVersion = $workForm->version;

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $internalProduction), [
                'production_type' => 'internal',
                'production_unit_name' => 'UV Hattı 1',
                'assigned_to' => $this->adminUser->id,
                'cliche_required' => '0',
                'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
                'production_note' => 'İç hatta hazırlanıyor.',
            ])
            ->assertRedirect(route('admin.productions.operator', $internalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'fully_received',
                'note' => 'Tedarik tamamlandı',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $this->markAllGraphicsProductionReady($workForm);
        $this->prepareSetupForProduction($externalProduction);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $internalProduction), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV Hattı 1',
                'note' => 'İç üretime alındı',
            ])
            ->assertRedirect(route('admin.productions.operator', $internalProduction));

        $internalProduction = $internalProduction->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $internalProduction->production_type);
        $this->assertSame(OrderItemPrintProduction::STATUS_INTERNAL, $internalProduction->production_status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $externalProduction), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'production_note' => 'Fason firmaya atandı',
            ])
            ->assertRedirect(route('admin.productions.subcontract-assignment', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'sent_to_subcontractor',
                'note' => 'Fasona gönderildi',
            ])
            ->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $externalProduction = $externalProduction->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $externalProduction->production_type);
        $this->assertSame($partner->id, $externalProduction->production_company_id);
        $this->assertSame(OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR, $externalProduction->production_status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'returned_from_subcontractor',
                'note' => 'Fasondan geldi',
            ])
            ->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_started',
            ])
            ->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_failed',
                'note' => 'Yüzey kalitesi tekrar kontrol edilecek',
            ])
            ->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $publicWhileProblematic = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->fresh()->public_tracking_token));

        $publicWhileProblematic->assertOk();
        $publicWhileProblematic->assertSee('Üretim süreci kontrol ediliyor');
        $publicWhileProblematic->assertDontSee('Yüzey kalitesi tekrar kontrol edilecek');
        $publicWhileProblematic->assertDontSee($partner->legal_name);
        $this->assertSafeOutput($publicWhileProblematic->getContent());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_started',
            ])
            ->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_passed',
            ])
            ->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $externalProduction))
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'completed',
                'completed_quantity' => '999',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction))
            ->assertSessionHasErrors('completed_quantity');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $externalProduction = $externalProduction->fresh(['workForm.activityLogs']);
        $workForm = $externalProduction->workForm->fresh(['attachments', 'activityLogs']);

        $this->assertSame(OrderItemPrintProduction::STATUS_COMPLETED, $externalProduction->production_status);
        $this->assertSame((float) $externalProduction->planned_quantity, (float) $externalProduction->completed_quantity);
        $this->assertSame(0.0, (float) $externalProduction->remaining_quantity);
        $this->assertGreaterThan($initialVersion, $workForm->version);
        $this->assertSame('Üretim tamamlandı', data_get($workForm->production_snapshot, 'public_status_label'));
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_assigned_internal'));
        $activityTypes = $workForm->activityLogs->pluck('action_type');
        $this->assertTrue($activityTypes->contains(fn ($type) => in_array($type, ['production_assigned_external', 'production_route_changed'], true)));
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_sent_to_subcontractor'));
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_qc_failed'));
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_completed'));

        $postReadyShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', [
                $externalProduction,
                'tab' => 'genel',
            ]));

        $postReadyShow->assertOk();
        $postReadyShow->assertDontSee('Grafik Bekliyor');
        $postReadyShow->assertDontSee('Tedarik Bekliyor');
        $postReadyShow->assertSee('Tamamlandı');
        $this->assertSafeOutput($postReadyShow->getContent());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'internal',
                'note' => 'İç üretim karesi',
                'file' => UploadedFile::fake()->image('internal-production.jpg'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'customer_visible',
                'note' => 'Müşteriye açık üretim karesi',
                'file' => UploadedFile::fake()->image('public-production.jpg'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);
        $this->assertSame(2, $workForm->productionPhotos()->count());
        $this->assertSame(2, (int) data_get($workForm->production_snapshot, 'photo_count'));
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_photo_added'));

        $adminWorkFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $adminWorkFormShow->assertOk();
        $adminWorkFormShow->assertSee('Üretim Durumu');
        $adminWorkFormShow->assertSee('Üretim Tipi');
        $adminWorkFormShow->assertSee('Kalite Kontrol');
        $adminWorkFormShow->assertSee('Üretim fotoğrafları');
        $adminWorkFormShow->assertSee('internal-production.jpg');
        $adminWorkFormShow->assertSee('public-production.jpg');
        $this->assertSafeOutput($adminWorkFormShow->getContent());

        $orderShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order->fresh()));

        $orderShow->assertOk();
        $orderShow->assertSee('Genel Özet');
        $orderShow->assertSee('Üretim');
        $orderShow->assertSee('Teslimat Bekliyor');
        $orderShow->assertSee(route('admin.productions.show', $internalProduction), false);
        $this->assertSafeOutput($orderShow->getContent());

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh([
            'tenant',
            'attachments',
            'activityLogs.attachment',
            'systemWorkFolder',
        ]));

        $this->assertStringContainsString('Üretim Durumu', $pdfHtml);
        $this->assertStringNotContainsString('Üretim Bekliyor', $pdfHtml);
        $this->assertStringContainsString('Tamamlandı', $pdfHtml);
        $this->assertStringContainsString('Planlanan / Tamamlanan', $pdfHtml);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $pdfHtml);
        $this->assertSafeOutput($pdfHtml);

        $pdfResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm));

        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('%PDF', (string) $pdfResponse->getContent());

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicTracking->assertOk();
        $publicTracking->assertSee('Üretim Durumu');
        $publicTracking->assertSee('public-production.jpg');
        $publicTracking->assertDontSee('internal-production.jpg');
        $publicTracking->assertDontSee($partner->legal_name);
        $this->assertSafeOutput($publicTracking->getContent());

        /** @var OrderItemWorkFormAttachment $publicPhoto */
        $publicPhoto = $workForm->attachments
            ->where('attachment_type', 'production_photo')
            ->firstWhere('visibility', 'customer_visible');
        /** @var OrderItemWorkFormAttachment $internalPhoto */
        $internalPhoto = $workForm->attachments
            ->where('attachment_type', 'production_photo')
            ->firstWhere('visibility', 'internal');

        $this->assertNotNull($publicPhoto);
        $this->assertNotNull($internalPhoto);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalPhoto->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $publicPhoto->id,
            ]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(
            route('public.work-forms.track', $workForm->public_tracking_token),
            app(WorkFormQrCodeService::class)->trackingUrl($workForm)
        );
        $this->assertStringContainsString('<svg', app(WorkFormQrCodeService::class)->qrSvg($workForm));
    }

    private function createConvertedOrderWithProductionScenario(SupplierSource $source): Order
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Production smoke payload',
                'items' => [[
                    'product_name' => 'Production Smoke Ürünü',
                    'product_code' => 'PROD-SMOKE-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '12.50',
                    'discount_rate' => '20',
                    'unit_price' => '10.00',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'supplier_source_id' => $source->id,
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '5',
                            'note' => 'Logo baskı',
                        ],
                        [
                            'print_type' => 'Sıcak Baskı',
                            'print_option' => 'Gövde baskı',
                            'production_type' => 'Dış üretim / Fason',
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'Yaldız baskı',
                            'cliche_status' => 'bekleniyor',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $quote->load('items');

        /** @var OrderItem $item */
        $item = $quote->items->first();
        $this->assertNotNull($item);
        $item->forceFill([
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $source->id,
            'product_snapshot' => [
                'product_name' => 'Production Smoke Ürünü',
                'product_code' => 'PROD-SMOKE-001',
                'supplier_name' => $source->supplier->name,
                'warning_badges' => ['Stok kontrolü gerekli'],
                'group_code' => 'PROD-HIDDEN-GROUP',
                'raw_mapping' => ['supplier_stock' => 999],
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 140,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
                'snapshot_taken_at' => '2026-06-13T11:00:00+03:00',
            ],
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function createPartnerCompany(): Company
    {
        $existing = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('status', 'active')
            ->whereKeyNot($this->customer->id)
            ->whereHas('companyRoles', fn ($query) => $query->whereIn('role_key', ['print_fason', 'production_partner']))
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Smoke Production Partner',
            'short_name' => 'Smoke Partner',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        return $company;
    }

    private function createSupplierSource(string $code): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);
    }

    private function markAllGraphicsProductionReady(OrderItemWorkForm $workForm): void
    {
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get();

        foreach ($graphics as $graphic) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post(route('admin.work-forms.attachments.store', $workForm), [
                    'attachment_type' => 'graphic_visual',
                    'visibility' => 'internal',
                    'note' => 'Final üretim görseli ' . $graphic->sequence_code,
                    'redirect_to' => 'admin.graphics.show',
                    'order_item_print_graphic_id' => $graphic->id,
                    'file' => UploadedFile::fake()->image('prod-ready-' . $graphic->sequence_code . '.jpg'),
                ])
                ->assertRedirect(route('admin.graphics.show', $workForm));

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->patch(route('admin.graphics.operations.update-status', [
                    'workForm' => $workForm,
                    'graphic' => $graphic,
                ]), [
                    'action' => 'approved',
                ])
                ->assertRedirect(route('admin.graphics.show', $workForm));

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->patch(route('admin.graphics.operations.update-status', [
                    'workForm' => $workForm,
                    'graphic' => $graphic,
                ]), [
                    'action' => 'production_ready',
                ])
                ->assertRedirect(route('admin.graphics.show', $workForm));
        }
    }

    private function prepareSetupForProduction(OrderItemPrintProduction $production): void
    {
        $requirements = $production->orderItemPrint?->fresh('setupRequirements')?->setupRequirements ?? collect();

        if ($requirements->isNotEmpty()) {
            foreach ($requirements as $requirement) {
                $this->actingAs($this->adminUser)
                    ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                    ->post(route('admin.print-setup-requirements.ready', $requirement))
                    ->assertRedirect();
            }

            return;
        }

        $production->forceFill([
            'cliche_required' => false,
            'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
            'updated_by' => $this->adminUser->id,
        ])->save();
    }

    private function assertSafeOutput(string $html): void
    {
        foreach ([
            'unit_price',
            'list_price',
            'discount_rate',
            'line_total',
            'print_unit_price',
            'print_total',
            'subtotal',
            'vat_total',
            'grand_total',
            'price_snapshot',
            'KDV',
            'alış maliyeti',
            'üretim maliyeti',
            'fason maliyeti',
            'kâr',
            'group_code',
            'raw_mapping',
            'supplier_stock_quantity',
            'physical_path',
            'C:\\',
            '/var/',
            'storage/app',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }
}
