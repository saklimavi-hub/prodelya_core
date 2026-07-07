<?php

namespace Tests\Feature;

use App\Models\OrderDeliveryPackage;
use App\Models\OrderItemWorkFormDelivery;
use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryMethodAndCompletionTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_delivery_method_can_be_saved_and_completion_does_not_close_finance(): void
    {
        $order = $this->createConvertedOrderForShow(['quantity' => '2']);
        $orderItem = $order->items()->firstOrFail();
        $service = app(OrderDeliveryPlanningService::class);

        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Koli 1',
                'package_type' => 'box',
                'items' => [['order_item_id' => $orderItem->id, 'quantity' => 2]],
            ],
        ], $this->orderShowAdminUser);

        $service->updateDeliveryInfo($order->fresh('deliveries'), [
            'delivery_type' => 'Kargo',
            'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'recipient_name' => 'Teslim Alan Kişi',
            'recipient_phone' => '05550000000',
            'carrier_name' => 'Hızlı Kargo',
            'tracking_number' => 'TRK-123',
            'delivery_note' => 'Kapıdan teslim',
        ], $this->orderShowAdminUser);

        $service->completeDelivery($order->fresh(['deliveries', 'deliveryPackages']), [
            'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'recipient_name' => 'Teslim Alan Kişi',
            'carrier_name' => 'Hızlı Kargo',
            'tracking_number' => 'TRK-123',
        ], $this->orderShowAdminUser);

        $this->assertTrue($order->fresh('deliveries')->deliveries->every(fn (OrderItemWorkFormDelivery $delivery): bool => $delivery->isDelivered()));
        $this->assertSame(
            OrderDeliveryPackage::STATUS_DELIVERED,
            OrderDeliveryPackage::query()->where('order_id', $order->id)->firstOrFail()->status
        );

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()
            ->assertSee('Kargo')
            ->assertSee('Müşteri Kendisi Alacak')
            ->assertSee('Ambar')
            ->assertSee('Kurye')
            ->assertSee('Elden Teslim')
            ->assertSee('Teslimat tamamlandı. Sipariş operasyon akışından çıkarıldı. Finans bakiyesi açıksa Cari Ekstre ve Finans ekranında takip edilmeye devam eder.');

        $financeResponse = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $financeResponse->assertOk()
            ->assertSee('Müşteri Borcu')
            ->assertSee('Kalan Bakiye');
    }
}
