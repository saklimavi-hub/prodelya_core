<?php

namespace App\Services;

use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\TenantPrintSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderItemPrintSetupRequirementService
{
    public function __construct(
        protected WorkFormDataBuilder $workFormDataBuilder,
        protected ProductionDataBuilder $productionDataBuilder
    ) {
    }

    public function createForPrint(OrderItemPrint $print): array
    {
        return $this->syncForPrint($print);
    }

    public function syncForPrint(OrderItemPrint $print): array
    {
        $print->loadMissing([
            'tenantPrintSetting.standardPrintType',
            'orderItem.workForm',
            'setupRequirements.assignedCompany',
            'production',
        ]);

        $setting = $print->tenantPrintSetting;

        if (!$setting || !$print->tenant_print_setting_id) {
            return [
                'created' => 0,
                'cancelled' => 0,
                'active_setup_types' => [],
            ];
        }

        if (!$print->effectiveRequiresProduction()) {
            $report = $this->cancelObsoleteRequirements($print, [], 'Baskı ayarı üretim gerektirmediği için hazırlık kaydı iptal edildi.');
            $this->refreshSnapshots($print);

            return [
                'created' => 0,
                'cancelled' => $report,
                'active_setup_types' => [],
            ];
        }

        if (!$setting->effectiveRequiresSetup()) {
            $report = $this->cancelObsoleteRequirements($print, [], 'Baskı ayarı artık setup gerektirmiyor.');
            $this->refreshSnapshots($print);

            return [
                'created' => 0,
                'cancelled' => $report,
                'active_setup_types' => [],
            ];
        }

        $created = $this->createFromTenantPrintSetting($print, $setting);
        $neededTypes = $this->normalizedSetupTypes($setting->effectiveSetupTypes());
        $cancelled = $this->cancelObsoleteRequirements($print, $neededTypes, 'Baskı ayarı bu setup tipini artık gerektirmiyor.');
        $this->refreshSnapshots($print);

        return [
            'created' => count($created),
            'cancelled' => $cancelled,
            'active_setup_types' => $neededTypes,
        ];
    }

    public function createFromTenantPrintSetting(OrderItemPrint $print, TenantPrintSetting $setting): array
    {
        $setupTypes = $this->normalizedSetupTypes($setting->effectiveSetupTypes());

        if (!$print->effectiveRequiresProduction() || !$setting->effectiveRequiresSetup() || $setupTypes === []) {
            return [];
        }

        $created = [];

        foreach ($setupTypes as $setupType) {
            $requirement = OrderItemPrintSetupRequirement::query()->firstOrCreate(
                [
                    'tenant_account_id' => $print->tenant_account_id,
                    'order_item_print_id' => $print->id,
                    'setup_type' => $setupType,
                ],
                [
                    'order_id' => $print->order_id,
                    'order_item_id' => $print->order_item_id,
                    'status' => OrderItemPrintSetupRequirement::STATUS_PENDING,
                    'assigned_company_id' => $setting->default_subcontractor_company_id,
                    'assigned_current_account_id' => $setting->default_subcontractor_current_account_id,
                    'cost' => null,
                    'currency' => $setting->default_currency ?: 'TRY',
                    'note' => null,
                ]
            );

            if ($requirement->wasRecentlyCreated) {
                $created[] = $requirement;
            } elseif ($requirement->status === OrderItemPrintSetupRequirement::STATUS_CANCELLED) {
                $requirement->forceFill([
                    'status' => OrderItemPrintSetupRequirement::STATUS_PENDING,
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'cancellation_reason' => null,
                    'assigned_company_id' => $requirement->assigned_company_id ?: $setting->default_subcontractor_company_id,
                    'assigned_current_account_id' => $requirement->assigned_current_account_id ?: $setting->default_subcontractor_current_account_id,
                    'currency' => $requirement->currency ?: ($setting->default_currency ?: 'TRY'),
                ])->save();
            }
        }

        return $created;
    }

    public function markRequested(OrderItemPrintSetupRequirement $requirement, ?User $user = null): OrderItemPrintSetupRequirement
    {
        return DB::transaction(function () use ($requirement, $user): OrderItemPrintSetupRequirement {
            $requirement->forceFill([
                'status' => OrderItemPrintSetupRequirement::STATUS_REQUESTED,
                'completed_at' => null,
                'completed_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ])->save();

            $this->refreshSnapshots($requirement->orderItemPrint()->firstOrFail());

            return $requirement->fresh();
        });
    }

    public function markReady(OrderItemPrintSetupRequirement $requirement, ?User $user = null): OrderItemPrintSetupRequirement
    {
        return DB::transaction(function () use ($requirement, $user): OrderItemPrintSetupRequirement {
            $requirement->forceFill([
                'status' => OrderItemPrintSetupRequirement::STATUS_READY,
                'completed_at' => now(),
                'completed_by' => $user?->id,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ])->save();

            $this->refreshSnapshots($requirement->orderItemPrint()->firstOrFail());

            return $requirement->fresh();
        });
    }

    public function cancel(OrderItemPrintSetupRequirement $requirement, string $reason, ?User $user = null): OrderItemPrintSetupRequirement
    {
        return DB::transaction(function () use ($requirement, $reason, $user): OrderItemPrintSetupRequirement {
            $requirement->forceFill([
                'status' => OrderItemPrintSetupRequirement::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user?->id,
                'cancellation_reason' => trim($reason),
            ])->save();

            $this->refreshSnapshots($requirement->orderItemPrint()->firstOrFail());

            return $requirement->fresh();
        });
    }

    private function cancelObsoleteRequirements(OrderItemPrint $print, array $activeSetupTypes, string $reason): int
    {
        $cancelled = 0;

        $requirements = $print->setupRequirements()
            ->whereNotIn('setup_type', $activeSetupTypes)
            ->where('status', '!=', OrderItemPrintSetupRequirement::STATUS_CANCELLED)
            ->get();

        foreach ($requirements as $requirement) {
            $requirement->forceFill([
                'status' => OrderItemPrintSetupRequirement::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => null,
                'cancellation_reason' => $reason,
            ])->save();
            $cancelled++;
        }

        return $cancelled;
    }

    private function refreshSnapshots(OrderItemPrint $print): void
    {
        $print->loadMissing([
            'order',
            'orderItem.workForm',
            'setupRequirements.assignedCompany',
            'production',
            'subcontractorCompany',
            'tenantPrintSetting.standardPrintType',
        ]);

        $workForm = $print->orderItem?->workForm;

        if ($workForm) {
            $workForm->loadMissing([
                'order',
                'orderItem.prints.setupRequirements.assignedCompany',
            ]);

            $snapshots = $this->workFormDataBuilder->build(
                $workForm->order,
                $workForm->orderItem,
                (int) $workForm->item_sequence
            );

            $workForm->forceFill([
                'print_snapshot' => $snapshots['print_snapshot'],
                'production_snapshot' => $snapshots['production_snapshot'],
            ])->save();
        }

        $production = $print->production;

        if ($production instanceof OrderItemPrintProduction) {
            $fullSnapshot = $this->productionDataBuilder->build($print, $workForm, $production);

            $production->forceFill([
                'production_snapshot' => $fullSnapshot,
            ])->save();

            if ($workForm) {
                $workForm->forceFill([
                    'production_snapshot' => $this->productionDataBuilder->buildWorkFormSnapshot($production->fresh()),
                ])->save();
            }
        }
    }

    private function normalizedSetupTypes(array $setupTypes): array
    {
        $allowed = array_keys(OrderItemPrintSetupRequirement::setupTypeLabels());

        return array_values(array_unique(array_values(array_filter(
            $setupTypes,
            static fn ($type) => is_string($type) && in_array($type, $allowed, true)
        ))));
    }
}
