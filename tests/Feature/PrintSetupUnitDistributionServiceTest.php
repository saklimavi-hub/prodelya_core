<?php

namespace Tests\Feature;

use App\Services\PrintSetupUnitDistributionService;
use Tests\TestCase;

class PrintSetupUnitDistributionServiceTest extends TestCase
{
    public function test_service_distributes_setup_total_over_print_quantity(): void
    {
        $service = app(PrintSetupUnitDistributionService::class);

        $result = $service->calculate(10, 800, 100);

        $this->assertSame(8.0, $result['setup_unit_amount']);
        $this->assertSame(18.0, $result['final_print_unit_price']);
        $this->assertSame(1800.0, $result['final_print_total']);
    }

    public function test_service_avoids_division_by_zero(): void
    {
        $service = app(PrintSetupUnitDistributionService::class);

        $result = $service->calculate(10, 800, 0);

        $this->assertSame(0.0, $result['setup_unit_amount']);
        $this->assertSame(10.0, $result['final_print_unit_price']);
        $this->assertSame(0.0, $result['final_print_total']);
    }

    public function test_service_keeps_final_price_same_when_setup_total_is_zero(): void
    {
        $service = app(PrintSetupUnitDistributionService::class);

        $result = $service->calculate(10, 0, 100);

        $this->assertSame(0.0, $result['setup_unit_amount']);
        $this->assertSame(10.0, $result['final_print_unit_price']);
        $this->assertSame(1000.0, $result['final_print_total']);
    }

    public function test_multiple_print_rows_can_be_calculated_independently(): void
    {
        $service = app(PrintSetupUnitDistributionService::class);

        $first = $service->calculate(10, 800, 100);
        $second = $service->calculate(6, 150, 50);

        $this->assertSame(18.0, $first['final_print_unit_price']);
        $this->assertSame(9.0, $second['final_print_unit_price']);
        $this->assertSame(450.0, $second['final_print_total']);
    }
}
