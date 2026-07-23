<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantSetting;
use App\Models\OrderItemWorkFolder;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\WorkFolderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WorkFolderSystemTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_converted_work_form_creates_system_work_folder_and_subdirectories(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $folder = OrderItemWorkFolder::query()->where('work_form_id', $workForm->id)->where('folder_type', 'system')->firstOrFail();
        $expectedDisplayPath = 'ISLER / ABC-INSAAT-AS / ' . data_get($workForm->order_snapshot, 'document_number') . ' / 01-SMOKE-001';

        $this->assertSame('created', $folder->status);
        $this->assertSame('local', $folder->storage_driver);
        $this->assertSame($expectedDisplayPath, $folder->display_path);

        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path));
        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path . '/01_GRAFIK'));
        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path . '/02_BASKIYA_HAZIR'));
        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path . '/03_URETIM_TESLIMAT'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/02_ONAY'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/04_MONTAJ'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/05_URETIM'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/06_TESLIMAT'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/07_ARSIV'));
    }

    public function test_duplicate_system_folder_creation_is_prevented(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $service = app(WorkFolderCreationService::class);
        $first = $service->createSystemFolderForWorkForm($workForm, $this->adminUser);
        $second = $service->createSystemFolderForWorkForm($workForm, $this->adminUser);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderItemWorkFolder::query()->where('work_form_id', $workForm->id)->where('folder_type', 'system')->count());
    }

    public function test_graphics_and_work_form_views_show_display_path_without_absolute_path(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $folder = OrderItemWorkFolder::query()->where('work_form_id', $workForm->id)->where('folder_type', 'system')->firstOrFail();

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndex->assertOk();
        $graphicsIndex->assertDontSee('C:\\', false);
        $graphicsIndex->assertDontSee('/var/', false);
        $graphicsIndex->assertDontSee('storage/app', false);
        $graphicsIndex->assertDontSee('physical_path', false);

        $graphicsShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $graphicsShow->assertOk();
        $graphicsShow->assertSee($folder->display_path);
        $graphicsShow->assertDontSee('C:\\', false);
        $graphicsShow->assertDontSee('/var/', false);
        $graphicsShow->assertDontSee('storage/app', false);

        $workFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $workFormShow->assertOk();
        $workFormShow->assertSee($folder->display_path);
        $workFormShow->assertDontSee('C:\\', false);
        $workFormShow->assertDontSee('/var/', false);
        $workFormShow->assertDontSee('storage/app', false);
    }

    public function test_public_tracking_does_not_show_work_folder_information(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $response->assertOk();
        $response->assertDontSee('Çalışma Klasörü');
        $response->assertDontSee('ISLER /');
        $response->assertDontSee('C:\\', false);
        $response->assertDontSee('/var/', false);
    }

    public function test_tenant_root_name_setting_is_used_for_display_path(): void
    {
        $workForm = $this->createConvertedWorkForm();
        TenantSetting::setValue($workForm->tenant_account_id, 'work_folder_root_name', 'Grafik İşleri');

        OrderItemWorkFolder::query()->where('work_form_id', $workForm->id)->delete();
        app(WorkFolderCreationService::class)->createSystemFolderForWorkForm($workForm, $this->adminUser);

        $folder = OrderItemWorkFolder::query()->where('work_form_id', $workForm->id)->where('folder_type', 'system')->firstOrFail();

        $this->assertStringStartsWith('GRAFIK-ISLERI / ', $folder->display_path);
        $this->assertStringStartsWith('GRAFIK-ISLERI/', $folder->relative_path);
    }

    public function test_folder_creation_failure_marks_record_as_failed(): void
    {
        $workForm = $this->createConvertedWorkForm();
        OrderItemWorkFolder::query()->where('work_form_id', $workForm->id)->delete();

        $disk = Mockery::mock();
        $disk->shouldReceive('directoryExists')->andReturn(false);
        $disk->shouldReceive('makeDirectory')->andThrow(new \RuntimeException('Disk create failed'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $folder = app(WorkFolderCreationService::class)->createSystemFolderForWorkForm($workForm, $this->adminUser);

        $this->assertSame('failed', $folder->status);
        $this->assertStringContainsString('Disk create failed', (string) $folder->error_message);
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
                'notes' => 'Work folder payload',
                'items' => [[
                    'product_name' => 'Smoke Test Kalem',
                    'product_code' => 'SMOKE-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '5',
                            'note' => 'test baskı',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek taraf lazer',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'test ikinci baskı',
                        ],
                    ],
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
