<?php

namespace Tests\Feature;

use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryPackageValidationTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_package_quantity_cannot_exceed_ready_quantity(): void
    {
        $order = $this->createConvertedOrderForShow(['quantity' => '10']);
        $orderItem = $order->items()->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Koli miktarı teslimata hazır adedi aşamaz.');

        app(OrderDeliveryPlanningService::class)->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Aşan Koli',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 11],
                ],
            ],
        ], $this->orderShowAdminUser);
    }

    public function test_empty_package_cannot_be_saved(): void
    {
        $order = $this->createConvertedOrderForShow(['quantity' => '10']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Boş koli kaydedilemez.');

        app(OrderDeliveryPlanningService::class)->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Boş Koli',
                'package_type' => 'box',
                'items' => [],
            ],
        ], $this->orderShowAdminUser);
    }

    public function test_other_tenant_item_cannot_be_added_to_package_plan(): void
    {
        $order = $this->createConvertedOrderForShow(['quantity' => '10']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Başka tenant sipariş kalemi koli planına eklenemez.');

        app(OrderDeliveryPlanningService::class)->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Tenant Hatası',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => 999999, 'quantity' => 1],
                ],
            ],
        ], $this->orderShowAdminUser);
    }

    public function test_delivered_order_cannot_receive_new_package_plan(): void
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
                'package_label' => 'İlk Koli',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 2],
                ],
            ],
        ], $this->orderShowAdminUser);

        $service->completeDelivery($order->fresh(['deliveries', 'deliveryPackages']), [], $this->orderShowAdminUser);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Teslim edilmiş siparişe yeni koli planı eklenemez.');

        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Yeni Koli',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 1],
                ],
            ],
        ], $this->orderShowAdminUser);
    }
}
