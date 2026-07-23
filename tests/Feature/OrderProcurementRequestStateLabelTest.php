<?php

namespace Tests\Feature;

use App\Models\SupplierProcurementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class OrderProcurementRequestStateLabelTest extends TestCase
{
    use InteractsWithProcurementFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_procurement_user_facing_labels_follow_request_lifecycle(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LABEL');
        $procurement = $this->createProcurement($supplier, $source, 'SP-LABEL-001')->fresh(['supplierRequestItems.request']);

        $this->assertSame('need_unrequested', $procurement->userFacingState());
        $this->assertSame('Talep Hazırlanacak', $procurement->userFacingStatusLabel());
        $this->assertSame('Tedarik talebini hazırla', $procurement->userFacingNextActionLabel());

        $requestRecord = $this->createSupplierRequest($procurement);
        $procurement = $procurement->fresh(['supplierRequestItems.request']);

        $this->assertSame($requestRecord->id, $procurement->openSupplierRequest()?->id);
        $this->assertSame('request_draft', $procurement->userFacingState());
        $this->assertSame('Talep Taslağı', $procurement->userFacingStatusLabel());
        $this->assertSame('Talebi aç veya düzenle', $procurement->userFacingNextActionLabel());

        $requestRecord->forceFill(['status' => SupplierProcurementRequest::STATUS_REQUESTED])->save();
        $procurement = $procurement->fresh(['supplierRequestItems.request']);

        $this->assertSame('request_sent', $procurement->userFacingState());
        $this->assertSame('Tedarik Bekliyor', $procurement->userFacingStatusLabel());
        $this->assertSame('Tedarikçiden dönüş bekle', $procurement->userFacingNextActionLabel());
    }
}
