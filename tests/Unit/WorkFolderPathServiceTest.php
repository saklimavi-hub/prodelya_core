<?php

namespace Tests\Unit;

use App\Models\OrderItemWorkForm;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\WorkFolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkFolderPathServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_it_normalizes_turkish_segments_safely(): void
    {
        $service = new WorkFolderPathService();

        $this->assertSame('ABC-INSAAT-AS', $service->normalizeSegment('ABC İnşaat A.Ş.', 32, 'MUSTERI'));
        $this->assertSame('AK-1020-KIRMIZI', $service->normalizeSegment('AK-1020 Kırmızı', 48, 'URUN'));
    }

    public function test_it_limits_customer_slug_to_first_three_meaningful_words(): void
    {
        $service = new WorkFolderPathService();

        $this->assertSame(
            'CEMIL-YESIL-MSC',
            $service->buildCustomerSegment('CEMİL YEŞİL - MSC İş Makinası Yedek Parçaları ve İmalat')
        );

        $this->assertSame(
            'ANORMAL-GORSEL-SANATLAR',
            $service->buildCustomerSegment('ANORMAL GÖRSEL SANATLAR BASIM YAYIN LTD ŞTİ')
        );
    }

    public function test_it_builds_display_and_relative_paths_for_work_form(): void
    {
        $service = new WorkFolderPathService();

        $workForm = new OrderItemWorkForm([
            'item_sequence' => 1,
            'order_snapshot' => [
                'document_number' => 'SP-2026-0008',
            ],
            'customer_snapshot' => [
                'company_name' => 'ABC İnşaat A.Ş.',
            ],
            'product_snapshot' => [
                'product_code' => 'AK-1020-KIRMIZI',
                'product_name' => 'Kalem',
            ],
        ]);

        $paths = $service->buildForWorkForm($workForm);

        $this->assertSame('ISLER/ABC-INSAAT-AS/SP-2026-0008/01-AK-1020-KIRMIZI', $paths['relative_path']);
        $this->assertSame('ISLER / ABC-INSAAT-AS / SP-2026-0008 / 01-AK-1020-KIRMIZI', $paths['display_path']);
        $this->assertSame([
            '01_GRAFIK',
            '02_BASKIYA_HAZIR',
            '03_URETIM_TESLIMAT',
        ], $paths['subdirectories']);
    }

    public function test_it_uses_tenant_root_name_setting_when_available(): void
    {
        $service = new WorkFolderPathService();
        $tenantId = TenantAccount::query()->value('id');

        TenantSetting::setValue($tenantId, 'work_folder_root_name', 'Grafik İşleri');

        $workForm = new OrderItemWorkForm([
            'tenant_account_id' => $tenantId,
            'item_sequence' => 1,
            'order_snapshot' => [
                'document_number' => 'SP-2026-0008',
            ],
            'customer_snapshot' => [
                'company_name' => 'ABC İnşaat A.Ş.',
            ],
            'product_snapshot' => [
                'product_code' => 'AK-1020-KIRMIZI',
            ],
        ]);

        $paths = $service->buildForWorkForm($workForm);

        $this->assertSame('GRAFIK-ISLERI/ABC-INSAAT-AS/SP-2026-0008/01-AK-1020-KIRMIZI', $paths['relative_path']);
        $this->assertSame('GRAFIK-ISLERI / ABC-INSAAT-AS / SP-2026-0008 / 01-AK-1020-KIRMIZI', $paths['display_path']);
    }
}
