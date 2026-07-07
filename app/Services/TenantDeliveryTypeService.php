<?php

namespace App\Services;

use App\Models\TenantAccount;
use App\Models\TenantDeliveryType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantDeliveryTypeService
{
    public function ensureDefaultsForTenant(TenantAccount|int $tenant): Collection
    {
        $tenantId = $tenant instanceof TenantAccount ? $tenant->id : $tenant;

        $existing = TenantDeliveryType::query()
            ->forTenant($tenantId)
            ->ordered()
            ->get();

        if ($existing->isNotEmpty()) {
            $this->ensureSingleDefault($tenantId);

            return TenantDeliveryType::query()
                ->forTenant($tenantId)
                ->ordered()
                ->get();
        }

        DB::transaction(function () use ($tenantId): void {
            foreach (TenantDeliveryType::defaultDefinitions() as $definition) {
                TenantDeliveryType::query()->create([
                    'tenant_account_id' => $tenantId,
                    'name' => $definition['name'],
                    'code' => TenantDeliveryType::makeCode($definition['name']),
                    'description' => $definition['description'],
                    'is_active' => true,
                    'sort_order' => $definition['sort_order'],
                    'is_default' => (bool) $definition['is_default'],
                ]);
            }
        });

        return TenantDeliveryType::query()
            ->forTenant($tenantId)
            ->ordered()
            ->get();
    }

    public function selectableForTenant(int $tenantId, ?int $includeCurrentId = null): Collection
    {
        $this->ensureDefaultsForTenant($tenantId);

        return TenantDeliveryType::query()
            ->forTenant($tenantId)
            ->where(function ($query) use ($includeCurrentId): void {
                $query->where('is_active', true);

                if ($includeCurrentId) {
                    $query->orWhere('id', $includeCurrentId);
                }
            })
            ->ordered()
            ->get();
    }

    public function defaultForTenant(int $tenantId): ?TenantDeliveryType
    {
        $this->ensureDefaultsForTenant($tenantId);

        return TenantDeliveryType::query()
            ->forTenant($tenantId)
            ->where('is_default', true)
            ->ordered()
            ->first();
    }

    public function selectionState(int $tenantId, ?int $currentId = null, ?string $currentLabel = null): array
    {
        $types = $this->selectableForTenant($tenantId, $currentId);
        $default = $this->defaultForTenant($tenantId);
        $matchedByName = null;

        if (!$currentId && filled($currentLabel)) {
            $matchedByName = $types->first(fn (TenantDeliveryType $type) => mb_strtolower($type->name) === mb_strtolower(trim((string) $currentLabel)));
        }

        $selectedId = $currentId ?: $matchedByName?->id ?: $default?->id;
        $legacyLabel = null;

        if (filled($currentLabel) && !$matchedByName && !$types->contains(fn (TenantDeliveryType $type) => $type->id === $currentId)) {
            $legacyLabel = trim((string) $currentLabel);
        }

        return [
            'types' => $types,
            'selected_id' => $selectedId,
            'legacy_label' => $legacyLabel,
        ];
    }

    public function resolveForPersistence(
        int $tenantId,
        ?int $selectedId,
        ?string $legacyLabel = null,
        ?int $allowedInactiveId = null
    ): array {
        $legacyLabel = filled($legacyLabel) ? trim((string) $legacyLabel) : null;

        if ($selectedId) {
            $record = TenantDeliveryType::query()
                ->forTenant($tenantId)
                ->whereKey($selectedId)
                ->first();

            if (!$record) {
                $existsElsewhere = TenantDeliveryType::query()->whereKey($selectedId)->exists();

                throw ValidationException::withMessages([
                    'delivery_type_id' => $existsElsewhere
                        ? 'Seçilen teslimat tipi bu abone firmaya ait değil.'
                        : 'Lütfen geçerli bir teslimat tipi seçin.',
                ]);
            }

            if (!$record->is_active && $record->id !== $allowedInactiveId) {
                throw ValidationException::withMessages([
                    'delivery_type_id' => 'Pasif teslimat tipi yeni tekliflerde kullanılamaz.',
                ]);
            }

            return [
                'delivery_type_id' => $record->id,
                'delivery_type' => $record->name,
            ];
        }

        if ($legacyLabel !== null) {
            $matched = TenantDeliveryType::query()
                ->forTenant($tenantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($legacyLabel)])
                ->first();

            return [
                'delivery_type_id' => $matched?->id,
                'delivery_type' => $legacyLabel,
            ];
        }

        return [
            'delivery_type_id' => null,
            'delivery_type' => null,
        ];
    }

    public function setDefault(TenantDeliveryType $type): TenantDeliveryType
    {
        DB::transaction(function () use ($type): void {
            TenantDeliveryType::query()
                ->forTenant($type->tenant_account_id)
                ->where('id', '!=', $type->id)
                ->update(['is_default' => false]);

            TenantDeliveryType::query()
                ->whereKey($type->id)
                ->update([
                    'is_active' => true,
                    'is_default' => true,
                ]);
        });

        return $type->fresh();
    }

    public function ensureSingleDefault(int $tenantId): void
    {
        $types = TenantDeliveryType::query()
            ->forTenant($tenantId)
            ->ordered()
            ->get();

        if ($types->isEmpty()) {
            return;
        }

        $activeTypes = $types->where('is_active', true)->values();
        $default = $types->firstWhere('is_default', true);

        if ($default && $default->is_active) {
            TenantDeliveryType::query()
                ->forTenant($tenantId)
                ->where('id', '!=', $default->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            return;
        }

        $fallback = $activeTypes->first() ?: $types->first();

        if (!$fallback) {
            return;
        }

        TenantDeliveryType::query()
            ->forTenant($tenantId)
            ->update(['is_default' => false]);

        $fallback->forceFill([
            'is_active' => true,
            'is_default' => true,
        ])->save();
    }
}
