<?php

namespace Tests\Feature;

use App\Models\OrderRevision;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareApplyModalTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_compare_page_shows_confirmation_modal_summary_and_finance_note(): void
    {
        [, $draft] = $this->createComparableRevisionDraft(['with_finance' => false]);
        $this->mutateRevisionQuantity($draft, 145);
        $this->mutateRevisionPrice($draft, 39);

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Uygulanacak Değişiklik')
            ->assertSee('Kilitli Alan')
            ->assertSee('Manuel Kontrol')
            ->assertSee('Finans kontrolü gerekiyor');
    }

    public function test_compare_page_disables_apply_for_already_applied_revision(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft(['with_finance' => false]);
        $this->mutateRevisionQuantity($draft, 130);

        $comparison = app(OrderRevisionComparisonService::class)->build($draft->fresh(['items.prints', 'sourceOrder']));
        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft->fresh(), $comparison, $this->adminUser);
        $revision->forceFill([
            'status' => OrderRevision::STATUS_PARTIALLY_APPLIED,
            'applied_at' => now(),
        ])->save();

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Bu revizyon daha önce uygulanmış.')
            ->assertSee('disabled', false);
    }
}
