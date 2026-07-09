<?php

namespace Tests\Feature;

use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServiceNoSensitiveLeakTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_keeps_sensitive_fields_out_of_revision_records(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);

        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);
        $payload = json_encode([
            'summary' => $revision->summary,
            'changes' => $revision->changes()->get(['old_value', 'new_value', 'reason'])->toArray(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('supplier_cost', $payload);
        $this->assertStringNotContainsString('purchase_price', $payload);
        $this->assertStringNotContainsString('profit', $payload);
        $this->assertStringNotContainsString('margin', $payload);
        $this->assertStringNotContainsString('group_code', $payload);
        $this->assertStringNotContainsString('file_path', $payload);
        $this->assertStringNotContainsString('meta_json', $payload);
        $this->assertStringNotContainsString('payload', $payload);
    }
}
