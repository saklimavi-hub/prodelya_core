<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkFolder;
use App\Models\OrderItemWorkForm;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantWorkFolderSettingsTest extends TestCase
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

    public function test_settings_page_shows_work_folder_root_name_field_and_safe_preview(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee('Çalışma klasörü kök adı');
        $response->assertSee('ISLER / ABC-INSAAT-AS / SP-2026-0008 / 01-AK-1020-KIRMIZI');
        $response->assertDontSee('C:\\', false);
        $response->assertDontSee('/var/', false);
        $response->assertDontSee('storage/app', false);
        $response->assertDontSee('physical_path', false);
    }

    public function test_blank_value_saves_default_isler(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.update'), [
                'work_folder_root_name' => '',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertSame(
            'ISLER',
            TenantSetting::query()->where('key', 'work_folder_root_name')->value('value')
        );
    }

    public function test_turkish_value_is_normalized_and_saved(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.update'), [
                'work_folder_root_name' => 'Grafik İşleri',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertSame(
            'GRAFIK-ISLERI',
            TenantSetting::query()->where('key', 'work_folder_root_name')->value('value')
        );

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $response->assertSee('GRAFIK-ISLERI / ABC-INSAAT-AS / SP-2026-0008 / 01-AK-1020-KIRMIZI');
    }

    public function test_existing_work_folders_are_not_changed_but_new_folders_use_new_root_name(): void
    {
        $firstWorkForm = $this->createConvertedWorkForm('SMOKE-ROOT-001');
        $firstFolder = OrderItemWorkFolder::query()
            ->where('work_form_id', $firstWorkForm->id)
            ->where('folder_type', 'system')
            ->firstOrFail();

        $this->assertStringStartsWith('ISLER / ', $firstFolder->display_path);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.update'), [
                'work_folder_root_name' => 'Sipariş Dosyaları',
            ])
            ->assertRedirect(route('admin.settings'));

        $firstFolder->refresh();
        $this->assertStringStartsWith('ISLER / ', $firstFolder->display_path);

        $secondWorkForm = $this->createConvertedWorkForm('SMOKE-ROOT-002');
        $secondFolder = OrderItemWorkFolder::query()
            ->where('work_form_id', $secondWorkForm->id)
            ->where('folder_type', 'system')
            ->firstOrFail();

        $this->assertStringStartsWith('SIPARIS-DOSYALARI / ', $secondFolder->display_path);
        $this->assertStringStartsWith('SIPARIS-DOSYALARI/', $secondFolder->relative_path);
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
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
                'notes' => 'Settings root payload',
                'items' => [[
                    'product_name' => 'Settings Test Kalem',
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
                    'has_print' => '0',
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
