<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionSubcontractReceiptTrackingFlowTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::PRODUCTION_SHOW_HOST]);
        $this->setUpProductionShowFixtures();
    }

    public function test_sent_subcontract_job_switches_from_send_cta_to_tracking_and_captures_send_baseline(): void
    {
        $partner = $this->createPartnerCompany('M13E Fason Takip');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'sent_to_subcontractor_at' => null,
            'subcontractor_cost' => null,
        ]);
        $production = $this->prepareProductionForReadyState($production);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_assignment',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'));

        $fresh = $production->fresh();
        $this->assertSame(OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR, $fresh->production_status);
        $this->assertSame(100.0, (float) data_get($fresh->production_snapshot, 'subcontract_tracking.send_baseline.remaining_quantity_at_send'));
        $this->assertSame($fresh->order_item_print_id, data_get($fresh->production_snapshot, 'subcontract_tracking.send_baseline.order_item_print_id'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $fresh->id . '/subcontract-assignment'))
            ->assertOk()
            ->assertSee('Fason Takibi Aç')
            ->assertDontSee('>Fasona Gönder<', false);

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $fresh->id . '/subcontract-tracking'))
            ->assertOk()
            ->assertSee('Fason Takibi')
            ->assertSee('Gelen Bilgisi Gir')
            ->assertSee('Tamamı Geldi')
            ->assertSee('Kısmi Geldi')
            ->assertSee('Eksik / Sorun Bildir')
            ->assertDontSee('subcontractor_cost')
            ->assertDontSee('Maliyet');
    }

    public function test_partial_internal_baseline_keeps_subcontract_receipt_quantities_separate_and_tracking_writes_no_current_account_without_cost(): void
    {
        $partner = $this->createPartnerCompany('M13E Kısmi Fason');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 40,
            'remaining_quantity' => 60,
            'sent_to_subcontractor_at' => null,
            'subcontractor_cost' => null,
        ]);
        $production = $this->prepareProductionForReadyState($production);
        $accountCount = CurrentAccountTransaction::query()->count();

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_tracking',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'))
            ->assertOk()
            ->assertSee('Önceden tamamlanan')
            ->assertSee('40')
            ->assertSee('Gönderilen')
            ->assertSee('60')
            ->assertSee('Gelen')
            ->assertSee('0');

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'partial',
                'partial_quantity' => 15,
                'return_to' => 'subcontract_tracking',
                'note' => 'M13E kısmi gelen',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'));

        $fresh = $production->fresh(['workForm.activityLogs']);
        $this->assertSame(55.0, (float) $fresh->completed_quantity);
        $this->assertSame(45.0, (float) $fresh->remaining_quantity);
        $this->assertSame($accountCount, CurrentAccountTransaction::query()->count());
        $this->assertTrue($fresh->workForm->activityLogs->contains('action_type', 'production_partially_completed'));
    }

    public function test_complete_issue_photo_and_guards_use_existing_exact_production_flow(): void
    {
        $partner = $this->createPartnerCompany('M13E Foto Fason');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'sent_to_subcontractor_at' => null,
            'subcontractor_cost' => null,
        ]);
        $production = $this->prepareProductionForReadyState($production);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_tracking',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser, 'web')
            ->post($this->tenantUrl('/admin/work-forms/' . $production->work_form_id . '/attachments'), [
                'attachment_type' => 'production_photo',
                'visibility' => 'internal',
                'redirect_to' => 'admin.productions.subcontract-tracking',
                'redirect_production_id' => $production->id,
                'file' => UploadedFile::fake()->image('m13e-fason-photo.jpg'),
                'note' => 'M13E fason dönüş fotoğrafı',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'))
            ->assertOk()
            ->assertSee('m13e-fason-photo.jpg')
            ->assertSee('M13E fason dönüş fotoğrafı');

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'subcontract_completed',
                'return_to' => 'subcontract_tracking',
                'note' => 'M13E tamamı geldi',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id));

        $fresh = $production->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemPrintProduction::STATUS_COMPLETED, $fresh->production_status);
        $this->assertSame(100.0, (float) $fresh->completed_quantity);
        $this->assertSame(0.0, (float) $fresh->remaining_quantity);
        $this->assertTrue($fresh->workForm->activityLogs->contains('action_type', 'production_returned_from_subcontractor'));
        $this->assertTrue($fresh->workForm->activityLogs->contains('action_type', 'production_completed'));

        $internal = $this->createInternalProductionForShow();
        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $internal->id . '/subcontract-tracking'))
            ->assertNotFound();

        $pending = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'sent_to_subcontractor_at' => null,
        ]);
        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $pending->id . '/subcontract-tracking'))
            ->assertNotFound();
    }

    public function test_subcontract_tracking_compact_surface_removes_repeated_summary_and_keeps_one_primary_action(): void
    {
        $partner = $this->createPartnerCompany('M13E Compact Fason');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'sent_to_subcontractor_at' => null,
            'subcontractor_cost' => null,
        ]);
        $production = $this->prepareProductionForReadyState($production);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_tracking',
            ])
            ->assertRedirect();

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'))
            ->assertOk()
            ->assertSee('pd-subcontract-tracking__compact-header', false)
            ->assertSee('pd-subcontract-tracking__metrics-line', false)
            ->assertSee('Gönderilen')
            ->assertSee('Gelen')
            ->assertSee('Kalan')
            ->assertDontSee('Kısa Özet')
            ->assertDontSee('Exact baskı işi')
            ->assertDontSee('Miktar ayrımı');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'Gelen Bilgisi Gir'));
        $this->assertStringNotContainsString('pd-subcontract-tracking__aside', $html);
    }

    public function test_subcontract_tracking_unknown_baseline_shows_single_compact_note_without_repeated_unresolved_boxes(): void
    {
        $partner = $this->createPartnerCompany('M13E Eski Fason');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            'completed_quantity' => 20,
            'remaining_quantity' => 80,
            'sent_to_subcontractor_at' => now(),
            'production_snapshot' => [],
            'subcontractor_cost' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'))
            ->assertOk()
            ->assertSee('Toplam tamamlanan')
            ->assertSee('Toplam kalan')
            ->assertDontSee('Ayrıştırılamadı');

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'Bu eski kayıtta fason başlangıç miktarı ayrı izlenemiyor')
        );
    }

    public function test_subcontract_tracking_completed_view_is_compact_and_read_only(): void
    {
        $partner = $this->createPartnerCompany('M13E Tamamlanan Fason');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'sent_to_subcontractor_at' => null,
            'subcontractor_cost' => null,
        ]);
        $production = $this->prepareProductionForReadyState($production);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_tracking',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'subcontract_completed',
                'return_to' => 'subcontract_tracking',
                'note' => 'M13E compact tamamlandı',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'))
            ->assertOk()
            ->assertSee('Fason işi tamamlandı')
            ->assertSee('Tamamlanan Kaydı İncele')
            ->assertDontSee('Kısmi Kaydet')
            ->assertDontSee('Sorun Bildir')
            ->assertDontSee('Teslim Fotoğrafı Ekle');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::PRODUCTION_SHOW_HOST . $path;
    }
}
