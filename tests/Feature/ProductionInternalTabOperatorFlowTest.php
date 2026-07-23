<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionInternalTabOperatorFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private User $productionUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->productionUser = $this->createUserWithRoles('internal-flow-production@example.test', ['production']);
    }

    public function test_legacy_internal_tab_redirects_and_operator_screen_shows_actions_on_start_and_active_flow(): void
    {
        $production = $this->createProductionRecord([
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
        ]);

        $this->actingAs($this->productionUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=ic-uretim')
            ->assertRedirect(route('admin.productions.operator', $production));

        $pendingResponse = $this->actingAs($this->productionUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $pendingResponse->assertOk();
        $pendingResponse->assertSee('İç Üretim');
        $pendingResponse->assertSee('Başla');
        $pendingResponse->assertSee('pd-ui-v1-internal-operator', false);
        $pendingResponse->assertSee('Üretime Başla');
        $pendingResponse->assertDontSee('Kısmi Kaydet');
        $pendingResponse->assertDontSee('Sorun Bildir');

        $production->forceFill([
            'production_status' => OrderItemPrintProduction::STATUS_INTERNAL,
            'completed_quantity' => 0,
            'remaining_quantity' => $production->planned_quantity,
        ])->save();

        $this->actingAs($this->productionUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=ic-uretim')
            ->assertRedirect(route('admin.productions.operator', $production));

        $activeResponse = $this->actingAs($this->productionUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $activeResponse->assertOk();
        $activeResponse->assertSee('Kısmi Kaydet');
        $activeResponse->assertSee('Tamamlandı');
        $activeResponse->assertSee('Fotoğraf Ekle');
        $activeResponse->assertDontSee('Üretime Başla');
    }

    private function createProductionRecord(array $productionOverrides = []): OrderItemPrintProduction
    {
        $customer = $this->getCustomerCompany();
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-INT-' . random_int(1000, 9999),
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->productionUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'customer_supplied',
            'product_name' => 'Operator Üretim Ürünü',
            'product_code' => 'OP-' . random_int(1000, 9999),
            'quantity' => 80,
            'unit' => 'Adet',
            'has_print' => true,
            'print_total' => 80,
            'status' => 'pending',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baski',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_quantity' => 80,
            'status' => 'draft',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->productionUser)->firstOrFail();
        $procurement = $workForm->procurement;
        $this->assertNotNull($procurement);
        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->productionUser->id,
        ])->save();

        $production = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->where('order_id', $order->id)
            ->latest('id')
            ->firstOrFail();

        $this->prepareProductionForStart($production->fresh(), 'internal-flow-ready.jpg');

        $production->forceFill(array_merge([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'production_unit_name' => 'Operatör Hattı',
            'assigned_to' => $this->productionUser->id,
            'updated_by' => $this->productionUser->id,
        ], $productionOverrides))->save();

        return $production->fresh();
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $production = $production->fresh(['orderItemPrint', 'workForm', 'workForm.procurement', 'orderItemPrint.graphicOperation']);
        $graphic = $production->orderItemPrint?->graphicOperation;

        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            ['note' => 'Operator görseli', 'visibility' => 'internal'],
            $this->productionUser
        );

        $graphic = $graphic->fresh();
        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic, $this->productionUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic, $this->productionUser);
    }

    private function getCustomerCompany(): Company
    {
        return Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    private function createUserWithRoles(string $email, array $roleKeys): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roleKeys as $roleKey) {
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            ]);
        }

        return $user;
    }
}
