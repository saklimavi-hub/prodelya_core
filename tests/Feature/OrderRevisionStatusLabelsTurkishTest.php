<?php

namespace Tests\Feature;

use App\Models\OrderRevision;
use App\Models\OrderRevisionChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderRevisionStatusLabelsTurkishTest extends TestCase
{
    use RefreshDatabase;

    public function test_turkish_labels_are_returned_for_status_decision_and_apply_status(): void
    {
        $this->assertSame('Taslak', OrderRevision::statusLabels()[OrderRevision::STATUS_DRAFT]);
        $this->assertSame('Kontrol Bekliyor', OrderRevision::statusLabels()[OrderRevision::STATUS_REVIEW_PENDING]);
        $this->assertSame('Uygulamaya Hazır', OrderRevision::statusLabels()[OrderRevision::STATUS_READY_TO_APPLY]);
        $this->assertSame('Uygulandı', OrderRevision::statusLabels()[OrderRevision::STATUS_APPLIED]);
        $this->assertSame('Kısmi Uygulandı', OrderRevision::statusLabels()[OrderRevision::STATUS_PARTIALLY_APPLIED]);
        $this->assertSame('Reddedildi', OrderRevision::statusLabels()[OrderRevision::STATUS_REJECTED]);
        $this->assertSame('İptal Edildi', OrderRevision::statusLabels()[OrderRevision::STATUS_CANCELLED]);

        $this->assertSame('Değişiklik Yok', OrderRevisionChange::decisionLabels()[OrderRevisionChange::DECISION_NO_CHANGE]);
        $this->assertSame('Uygulanabilir', OrderRevisionChange::decisionLabels()[OrderRevisionChange::DECISION_APPLICABLE]);
        $this->assertSame('Kontrollü Uygulanabilir', OrderRevisionChange::decisionLabels()[OrderRevisionChange::DECISION_CONTROLLED_APPLICABLE]);
        $this->assertSame('Kilitli', OrderRevisionChange::decisionLabels()[OrderRevisionChange::DECISION_LOCKED]);
        $this->assertSame('Manuel Kontrol Gerekli', OrderRevisionChange::decisionLabels()[OrderRevisionChange::DECISION_MANUAL_REVIEW]);

        $this->assertSame('Bekliyor', OrderRevisionChange::applyStatusLabels()[OrderRevisionChange::APPLY_STATUS_PENDING]);
        $this->assertSame('Uygulandı', OrderRevisionChange::applyStatusLabels()[OrderRevisionChange::APPLY_STATUS_APPLIED]);
        $this->assertSame('Atlandı', OrderRevisionChange::applyStatusLabels()[OrderRevisionChange::APPLY_STATUS_SKIPPED]);
        $this->assertSame('Reddedildi', OrderRevisionChange::applyStatusLabels()[OrderRevisionChange::APPLY_STATUS_REJECTED]);
        $this->assertSame('Engellendi', OrderRevisionChange::applyStatusLabels()[OrderRevisionChange::APPLY_STATUS_BLOCKED]);
        $this->assertSame('Manuel Kontrol Gerekli', OrderRevisionChange::applyStatusLabels()[OrderRevisionChange::APPLY_STATUS_MANUAL_REQUIRED]);
    }
}
