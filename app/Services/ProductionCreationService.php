<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkForm;
use App\Models\StandardPrintType;
use App\Models\TenantPrintSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ProductionCreationService
{
    public function __construct(
        protected ProductionDataBuilder $dataBuilder,
        protected ProductionWorkflowService $workflowService
    ) {}

    public function createForOrder(Order $order, ?User $user = null): Collection
    {
        $order->loadMissing([
            'items.prints.subcontractorCompany',
            'items.prints.tenantPrintSetting.defaultSubcontractorCompany',
            'items.workForm.procurement',
        ]);

        $records = new Collection();

        foreach ($order->items as $item) {
            foreach ($item->prints as $print) {
                $production = $this->createForOrderItemPrint($print, $item->workForm, $user);

                if ($production) {
                    $records->push($production);
                }
            }
        }

        return $records;
    }

    public function createForWorkForm(OrderItemWorkForm $workForm, ?User $user = null): Collection
    {
        $workForm->loadMissing([
            'orderItem.prints.subcontractorCompany',
            'orderItem.prints.tenantPrintSetting.defaultSubcontractorCompany',
            'procurement',
        ]);

        $records = new Collection();

        foreach ($workForm->orderItem?->prints ?? [] as $print) {
            $production = $this->createForOrderItemPrint($print, $workForm, $user);

            if ($production) {
                $records->push($production);
            }
        }

        return $records;
    }

    public function createForOrderItemPrint(
        OrderItemPrint $print,
        ?OrderItemWorkForm $workForm = null,
        ?User $user = null
    ): ?OrderItemPrintProduction {
        $print->loadMissing([
            'order',
            'orderItem.workForm.procurement',
            'subcontractorCompany',
            'tenantPrintSetting.defaultSubcontractorCompany',
            'tenantPrintSetting.standardPrintType',
        ]);

        $workForm ??= $print->orderItem?->workForm;

        $existing = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $print->tenant_account_id)
            ->where('order_item_print_id', $print->id)
            ->first();

        if (!$print->effectiveRequiresProduction()) {
            return $existing?->fresh(['workForm', 'orderItemPrint', 'productionCompany']);
        }

        if ($existing) {
            $needsAssociation = $workForm && (int) $existing->work_form_id !== (int) $workForm->id;
            $needsSnapshotInitialization = blank($existing->production_snapshot);

            if ($needsAssociation) {
                $existing->forceFill([
                    'work_form_id' => $workForm->id,
                    'updated_by' => $user?->id,
                ])->save();
            }

            if ($needsSnapshotInitialization) {
                if ($existing->work_form_id) {
                    $this->workflowService->initialize($existing->fresh(['workForm', 'orderItemPrint.subcontractorCompany']), $user);
                } else {
                    $existing->forceFill([
                        'production_snapshot' => $this->dataBuilder->build(
                            $print,
                            $workForm,
                            $existing
                        ),
                        'updated_by' => $user?->id,
                    ])->save();
                }
            }

            return $existing->fresh(['workForm', 'orderItemPrint', 'productionCompany']);
        }

        $defaults = $this->defaultsForPrint($print);

        $production = OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $print->tenant_account_id,
            'order_id' => $print->order_id,
            'order_item_id' => $print->order_item_id,
            'order_item_print_id' => $print->id,
            'work_form_id' => $workForm?->id,
            'production_type' => $defaults['production_type'],
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'production_company_id' => $defaults['production_company_id'],
            'production_unit_name' => null,
            'assigned_to' => null,
            'planned_quantity' => $defaults['planned_quantity'],
            'completed_quantity' => 0,
            'remaining_quantity' => $defaults['planned_quantity'],
            'cliche_required' => $defaults['cliche_required'],
            'cliche_status' => $defaults['cliche_status'],
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
            'production_note' => $print->production_note,
            'issue_note' => null,
            'production_snapshot' => [],
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        if ($workForm) {
            return $this->workflowService->initialize(
                $production->fresh(['workForm', 'orderItemPrint.subcontractorCompany']),
                $user
            );
        }

        $production->forceFill([
            'production_snapshot' => $this->dataBuilder->build($print, $workForm, $production),
        ])->save();

        return $production->fresh(['workForm', 'orderItemPrint', 'productionCompany']);
    }

    private function defaultsForPrint(OrderItemPrint $print): array
    {
        $plannedQuantity = round((float) ($print->print_quantity ?? 0), 4);
        $legacyCliche = $this->normalizeLegacyClicheStatus($print->cliche_status);
        $setting = $this->resolveApplicableTenantPrintSetting($print);
        $explicitCompanyId = $this->resolveExplicitSubcontractorCompanyId($print);
        $explicitProductionType = $this->normalizeLegacyProductionType(
            $print->production_type,
            $explicitCompanyId
        );
        $defaultProductionType = $this->defaultProductionTypeFromSetting($setting);
        $resolvedProductionType = $explicitProductionType
            ?? $defaultProductionType
            ?? ($explicitCompanyId ? OrderItemPrintProduction::TYPE_OUTSOURCED : null);
        $resolvedProductionCompanyId = $this->resolveProductionCompanyId(
            $print,
            $setting,
            $resolvedProductionType,
            $explicitCompanyId
        );

        return [
            'planned_quantity' => $plannedQuantity,
            'production_type' => $resolvedProductionType,
            'production_company_id' => $resolvedProductionCompanyId,
            'cliche_required' => $legacyCliche !== null && $legacyCliche !== OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
            'cliche_status' => $legacyCliche ?: OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
        ];
    }

    private function resolveApplicableTenantPrintSetting(OrderItemPrint $print): ?TenantPrintSetting
    {
        $setting = $print->tenantPrintSetting;

        if (!$setting) {
            return null;
        }

        if ((int) $setting->tenant_account_id !== (int) $print->tenant_account_id) {
            return null;
        }

        if (!$setting->is_active) {
            return null;
        }

        return $setting;
    }

    private function resolveExplicitSubcontractorCompanyId(OrderItemPrint $print): ?int
    {
        $companyId = (int) ($print->subcontractor_company_id ?? 0);

        if ($companyId <= 0) {
            return null;
        }

        if (!$print->subcontractorCompany) {
            return null;
        }

        if ((int) $print->subcontractorCompany->tenant_account_id !== (int) $print->tenant_account_id) {
            return null;
        }

        return $companyId;
    }

    private function defaultProductionTypeFromSetting(?TenantPrintSetting $setting): ?string
    {
        if (!$setting) {
            return null;
        }

        return match ($setting->production_mode) {
            StandardPrintType::MODE_INTERNAL => OrderItemPrintProduction::TYPE_INTERNAL,
            StandardPrintType::MODE_OUTSOURCED => OrderItemPrintProduction::TYPE_OUTSOURCED,
            StandardPrintType::MODE_BOTH => OrderItemPrintProduction::TYPE_INTERNAL,
            default => null,
        };
    }

    private function resolveProductionCompanyId(
        OrderItemPrint $print,
        ?TenantPrintSetting $setting,
        ?string $resolvedProductionType,
        ?int $explicitCompanyId
    ): ?int {
        if ($explicitCompanyId) {
            return $explicitCompanyId;
        }

        if ($resolvedProductionType !== OrderItemPrintProduction::TYPE_OUTSOURCED) {
            return null;
        }

        if (!$setting) {
            return null;
        }

        $companyId = (int) ($setting->default_subcontractor_company_id ?? 0);

        if ($companyId <= 0) {
            return null;
        }

        $company = $setting->defaultSubcontractorCompany;

        if (!$company || (int) $company->tenant_account_id !== (int) $print->tenant_account_id) {
            return null;
        }

        return $companyId;
    }

    private function normalizeLegacyProductionType(?string $legacyType, ?int $subcontractorCompanyId): ?string
    {
        $normalized = trim(Str::ascii(mb_strtolower((string) $legacyType)));

        if ($normalized === '') {
            return $subcontractorCompanyId ? OrderItemPrintProduction::TYPE_OUTSOURCED : null;
        }

        if (str_contains($normalized, 'fason')) {
            return OrderItemPrintProduction::TYPE_OUTSOURCED;
        }

        if (str_contains($normalized, 'dis')) {
            return $subcontractorCompanyId ? OrderItemPrintProduction::TYPE_OUTSOURCED : OrderItemPrintProduction::TYPE_EXTERNAL;
        }

        if (str_contains($normalized, 'ic')) {
            return OrderItemPrintProduction::TYPE_INTERNAL;
        }

        return $subcontractorCompanyId ? OrderItemPrintProduction::TYPE_OUTSOURCED : null;
    }

    private function normalizeLegacyClicheStatus(?string $status): ?string
    {
        $normalized = trim(Str::ascii(mb_strtolower((string) $status)));

        return match ($normalized) {
            '', 'null' => null,
            'gerekli degil', 'gerekli_degil' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
            'mevcut' => OrderItemPrintProduction::CLICHE_AVAILABLE,
            'yeni yapilacak', 'yeni_yapilacak' => OrderItemPrintProduction::CLICHE_NEW,
            'bekleniyor' => OrderItemPrintProduction::CLICHE_WAITING,
            'hazir' => OrderItemPrintProduction::CLICHE_READY,
            default => null,
        };
    }
}
