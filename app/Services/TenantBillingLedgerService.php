<?php

namespace App\Services;

use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\TenantBillingEntry;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantBillingLedgerService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginatedEntries(TenantAccount $tenant, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($tenant, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, TenantBillingEntry>
     */
    public function entries(TenantAccount $tenant, array $filters = []): Collection
    {
        return $this->query($tenant, $filters)->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(TenantAccount $tenant, array $filters = []): array
    {
        $query = $this->query($tenant, $filters);
        $entries = $query->get();
        $totalDebit = (float) $entries->where('direction', 'debit')->sum('amount');
        $totalCredit = (float) $entries->where('direction', 'credit')->sum('amount');

        return [
            'balance' => $totalDebit - $totalCredit,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'entry_count' => $entries->count(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createEntry(TenantAccount $tenant, array $payload, ?User $actor = null): TenantBillingEntry
    {
        return DB::transaction(function () use ($tenant, $payload, $actor): TenantBillingEntry {
            return TenantBillingEntry::query()->create([
                'tenant_account_id' => $tenant->id,
                'tenant_service_definition_id' => $payload['tenant_service_definition_id'] ?? null,
                'package_key' => $payload['package_key'] ?? null,
                'entry_type' => $payload['entry_type'],
                'title' => trim((string) $payload['title']),
                'note' => filled($payload['note'] ?? null) ? trim((string) $payload['note']) : null,
                'reference_no' => filled($payload['reference_no'] ?? null) ? trim((string) $payload['reference_no']) : $this->referenceFor($payload['entry_type']),
                'direction' => $payload['direction'],
                'amount' => $payload['amount'],
                'currency' => $payload['currency'] ?? 'TRY',
                'entry_date' => $payload['entry_date'],
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'meta_json' => $payload['meta_json'] ?? [],
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateEntry(TenantBillingEntry $entry, array $payload, User $actor): TenantBillingEntry
    {
        $entry->update([
            'tenant_service_definition_id' => $payload['tenant_service_definition_id'] ?? null,
            'package_key' => $payload['package_key'] ?? null,
            'entry_type' => $payload['entry_type'],
            'title' => trim((string) $payload['title']),
            'note' => filled($payload['note'] ?? null) ? trim((string) $payload['note']) : null,
            'reference_no' => filled($payload['reference_no'] ?? null) ? trim((string) $payload['reference_no']) : $entry->reference_no,
            'direction' => $payload['direction'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'] ?? 'TRY',
            'entry_date' => $payload['entry_date'],
            'updated_by' => $actor->id,
            'meta_json' => $payload['meta_json'] ?? $entry->meta_json,
        ]);

        return $entry->fresh(['serviceDefinition', 'creator']) ?? $entry;
    }

    public function chargePackageFee(TenantAccount $tenant, User $actor): TenantBillingEntry
    {
        $package = $tenant->package;

        if (!$package instanceof Package) {
            throw new InvalidArgumentException('Tenant için aktif bir paket bulunamadı.');
        }

        $amount = $package->monthly_price ?? $package->yearly_price;

        if ($amount === null) {
            throw new InvalidArgumentException('Seçili pakette borçlandırılacak fiyat bilgisi bulunamadı.');
        }

        return $this->createEntry($tenant, [
            'tenant_service_definition_id' => null,
            'package_key' => $package->key,
            'entry_type' => 'package_fee',
            'title' => $package->name . ' paket bedeli',
            'note' => 'Tenant için paket borçlandırması oluşturuldu.',
            'reference_no' => 'PKG-' . Str::upper($tenant->slug) . '-' . now()->format('YmdHis'),
            'direction' => 'debit',
            'amount' => (float) $amount,
            'currency' => $package->currency ?: 'TRY',
            'entry_date' => now()->toDateString(),
            'meta_json' => [
                'package_name' => $package->name,
                'package_key' => $package->key,
            ],
        ], $actor);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function query(TenantAccount $tenant, array $filters = []): Builder
    {
        return TenantBillingEntry::query()
            ->with(['serviceDefinition', 'creator'])
            ->where('tenant_account_id', $tenant->id)
            ->when(filled($filters['date_from'] ?? null), function (Builder $query) use ($filters): void {
                $query->whereDate('entry_date', '>=', (string) $filters['date_from']);
            })
            ->when(filled($filters['date_to'] ?? null), function (Builder $query) use ($filters): void {
                $query->whereDate('entry_date', '<=', (string) $filters['date_to']);
            })
            ->when(filled($filters['entry_type'] ?? null), function (Builder $query) use ($filters): void {
                $query->where('entry_type', (string) $filters['entry_type']);
            })
            ->when(filled($filters['direction'] ?? null), function (Builder $query) use ($filters): void {
                $query->where('direction', (string) $filters['direction']);
            })
            ->when(filled($filters['tenant_service_definition_id'] ?? null), function (Builder $query) use ($filters): void {
                $query->where('tenant_service_definition_id', (int) $filters['tenant_service_definition_id']);
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id');
    }

    private function referenceFor(string $entryType): string
    {
        return Str::upper(Str::substr($entryType, 0, 3)) . '-' . now()->format('YmdHis');
    }

    /**
     * @return array<string, string>
     */
    public function entryTypeOptions(): array
    {
        return [
            'package_fee' => 'Paket Bedeli',
            'service_fee' => 'Hizmet Bedeli',
            'collection' => 'Tahsilat',
            'manual_debit' => 'Manuel Borç',
            'manual_credit' => 'Manuel Alacak',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function directionOptions(): array
    {
        return [
            'debit' => 'Borç',
            'credit' => 'Alacak',
        ];
    }

    public function dateLabel(?string $date): string
    {
        if (!filled($date)) {
            return 'Takip edilmiyor';
        }

        return Carbon::parse($date)->format('d.m.Y');
    }
}
