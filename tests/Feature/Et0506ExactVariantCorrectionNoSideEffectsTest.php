<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\NotificationLog;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506ExactVariantCorrectionNoSideEffectsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_apply_does_not_create_side_effect_rows(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();

        $before = [
            'stock_movements' => StockMovement::query()->count(),
            'current_account_transactions' => CurrentAccountTransaction::query()->count(),
            'notification_logs' => NotificationLog::query()->count(),
            'order_item_procurements' => DB::table('order_item_procurements')->count(),
            'supplier_procurement_request_items' => DB::table('supplier_procurement_request_items')->count(),
        ];

        $this->artisan('prodelya:repair-local-stock-variants', array_merge(
            $this->correctionCommandPayload($product, $blue, $red, $legacy),
            ['--apply' => true]
        ))
            ->expectsOutputToContain('Delta')
            ->assertSuccessful();

        $after = [
            'stock_movements' => StockMovement::query()->count(),
            'current_account_transactions' => CurrentAccountTransaction::query()->count(),
            'notification_logs' => NotificationLog::query()->count(),
            'order_item_procurements' => DB::table('order_item_procurements')->count(),
            'supplier_procurement_request_items' => DB::table('supplier_procurement_request_items')->count(),
        ];

        $this->assertSame($before, $after);
    }
}
