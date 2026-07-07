<?php

namespace Tests\Feature;

use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrierDeliveryCurrentAccountAuditTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_carrier_delivery_current_account_automation_is_not_yet_wired_and_cost_fields_are_absent(): void
    {
        $fillable = (new OrderItemWorkFormDelivery())->getFillable();

        $this->assertContains('carrier_name', $fillable);
        $this->assertNotContains('carrier_company_id', $fillable);
        $this->assertNotContains('delivery_cost', $fillable);
        $this->assertNotContains('delivery_cost_currency', $fillable);
        $this->assertFalse(class_exists('App\\Services\\CarrierDeliveryCurrentAccountSyncService'));
    }
}
