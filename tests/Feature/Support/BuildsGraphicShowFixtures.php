<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\WorkFormAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait BuildsGraphicShowFixtures
{
    protected const GRAPHIC_SHOW_HOST = 'prodelya_core.test';

    protected User $graphicAdminUser;
    protected TenantAccount $graphicTenant;

    protected function setUpGraphicShowFixtures(): void
    {
        Storage::fake('public');
        $this->graphicTenant = TenantAccount::query()->firstOrFail();
        $this->graphicAdminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    protected function createGraphicShowWorkForm(string $productCode = 'GRAPHIC-SHOW-001'): OrderItemWorkForm
    {
        $customer = Company::query()
            ->where('tenant_account_id', $this->graphicTenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $partner = Company::query()
            ->where('tenant_account_id', $this->graphicTenant->id)
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-01',
                'valid_until' => '2026-07-08',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Graphic show fixture payload',
                'items' => [[
                    'product_name' => 'Grafik Detay Ürünü ' . $productCode,
                    'product_code' => $productCode,
                    'quantity' => '24',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '24',
                            'print_unit_price' => '10',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Kapak',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '24',
                            'print_unit_price' => '10',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }

    protected function attachGraphicImage(OrderItemPrintGraphic $graphic, string $name = 'graphic-preview.png', string $visibility = 'customer_visible'): void
    {
        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($name, 1600, 1200),
            ['visibility' => $visibility, 'note' => 'Grafik görseli'],
            $this->graphicAdminUser
        );
    }

    protected function attachCustomerVisibleGraphicRecord(OrderItemPrintGraphic $graphic, string $name = 'approval-graphic.png'): OrderItemWorkFormAttachment
    {
        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $graphic->tenant_account_id,
            'work_form_id' => $graphic->order_item_work_form_id,
            'order_id' => $graphic->order_id,
            'order_item_id' => $graphic->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $graphic->order_item_work_form_id . '/' . $name,
            'file_name' => $name,
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->graphicAdminUser->id,
            'note' => 'Müşteri onay görseli',
            'sort_order' => 1,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $attachment->id,
            'updated_by' => $this->graphicAdminUser->id,
        ])->save();

        return $attachment;
    }

    protected function enableGraphicCustomerApproval(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->graphicTenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->graphicTenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => 'public_graphic_approval',
            ],
            ['is_enabled' => true]
        );
    }
}
