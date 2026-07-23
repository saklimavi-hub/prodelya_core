<?php

namespace App\Services\Stock;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TenantStockReservationService
{
    public function __construct(
        private readonly TenantLocalStockResolver $resolver,
    ) {
    }

    public function syncForOrderItem(OrderItem $item, ?User $user = null): array
    {
        return DB::transaction(function () use ($item, $user): array {
            $item = OrderItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $resolver = $this->resolver->resolveForOrderItem($item);
            $activeReservations = TenantStockReservation::query()
                ->where('tenant_account_id', $item->tenant_account_id)
                ->where('order_item_id', $item->id)
                ->where('status', TenantStockReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->keyBy('tenant_local_stock_id');

            if (!($resolver['resolved'] ?? false)) {
                $releasedQuantity = $this->releaseReservations($activeReservations, $user);

                return [
                    'resolved' => false,
                    'allocated_quantity' => 0.0,
                    'supplier_requested_quantity' => round((float) $item->quantity, 4),
                    'reason_code' => $resolver['reason_code'] ?? 'no_local_stock',
                    'scope' => $resolver['scope'] ?? 'unresolved',
                    'released_quantity' => $releasedQuantity,
                ];
            }

            /** @var Collection<int, TenantLocalStock> $rows */
            $rows = collect($resolver['rows'] ?? []);
            $requestedQuantity = round((float) $item->quantity, 4);
            $existingReserved = round((float) $activeReservations->sum(fn (TenantStockReservation $reservation) => (float) $reservation->quantity), 4);
            $candidateAvailable = round((float) $rows->sum(fn (TenantLocalStock $row) => (float) $row->quantity_available), 4);
            $desiredAllocation = max(min($requestedQuantity, $candidateAvailable + $existingReserved), 0.0);

            $remainingToKeep = $desiredAllocation;

            foreach ($rows as $row) {
                $reservation = $activeReservations->get($row->id);
                $currentReservation = round((float) ($reservation?->quantity ?? 0), 4);
                $availableForRow = round((float) $row->quantity_available + $currentReservation, 4);
                $targetReservation = max(min($remainingToKeep, $availableForRow), 0.0);

                if ($targetReservation <= 0.0001) {
                    if ($reservation) {
                        $this->releaseReservation($reservation, $user);
                    }

                    continue;
                }

                if (!$reservation) {
                    $reservation = TenantStockReservation::query()->create([
                        'tenant_account_id' => $item->tenant_account_id,
                        'tenant_local_stock_id' => $row->id,
                        'order_id' => $item->order_id,
                        'order_item_id' => $item->id,
                        'quantity' => 0,
                        'status' => TenantStockReservation::STATUS_ACTIVE,
                        'reserved_at' => now(),
                        'meta_json' => [
                            'product_code' => $item->product_code,
                        ],
                        'created_by' => $user?->id ?? $item->order?->created_by,
                    ]);
                }

                $delta = round($targetReservation - $currentReservation, 4);

                if (abs($delta) > 0.0001) {
                    $row->quantity_reserved = round((float) $row->quantity_reserved + $delta, 4);
                    $row->quantity_available = max(round((float) $row->quantity_on_hand - (float) $row->quantity_reserved, 4), 0.0);
                    $row->save();

                    $reservation->quantity = $targetReservation;
                    $reservation->reserved_at = $reservation->reserved_at ?: now();
                    $reservation->released_at = null;
                    $reservation->status = TenantStockReservation::STATUS_ACTIVE;
                    $reservation->save();
                }

                $remainingToKeep = round($remainingToKeep - $targetReservation, 4);
            }

            $orphanReservations = $activeReservations->filter(fn (TenantStockReservation $reservation) => !$rows->contains(fn (TenantLocalStock $row) => $row->id === $reservation->tenant_local_stock_id));
            $this->releaseReservations($orphanReservations, $user);

            $allocatedQuantity = round(
                (float) TenantStockReservation::query()
                    ->where('tenant_account_id', $item->tenant_account_id)
                    ->where('order_item_id', $item->id)
                    ->where('status', TenantStockReservation::STATUS_ACTIVE)
                    ->sum('quantity'),
                4
            );

            return [
                'resolved' => true,
                'allocated_quantity' => $allocatedQuantity,
                'supplier_requested_quantity' => max(round($requestedQuantity - $allocatedQuantity, 4), 0.0),
                'reason_code' => $resolver['reason_code'] ?? 'exact_variant_stock_found',
                'scope' => $resolver['scope'] ?? 'variant',
            ];
        });
    }

    public function releaseForOrder(Order $order, ?User $user = null): float
    {
        return DB::transaction(function () use ($order, $user): float {
            $reservations = TenantStockReservation::query()
                ->where('tenant_account_id', $order->tenant_account_id)
                ->where('order_id', $order->id)
                ->where('status', TenantStockReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            return $this->releaseReservations($reservations, $user);
        });
    }

    protected function releaseReservations(iterable $reservations, ?User $user = null): float
    {
        $released = 0.0;

        foreach ($reservations as $reservation) {
            if (!$reservation instanceof TenantStockReservation || !$reservation->isActive()) {
                continue;
            }

            $released += $this->releaseReservation($reservation, $user);
        }

        return round($released, 4);
    }

    protected function releaseReservation(TenantStockReservation $reservation, ?User $user = null): float
    {
        /** @var TenantLocalStock|null $stock */
        $stock = TenantLocalStock::query()
            ->whereKey($reservation->tenant_local_stock_id)
            ->lockForUpdate()
            ->first();

        $quantity = round((float) $reservation->quantity, 4);

        if ($stock) {
            $stock->quantity_reserved = max(round((float) $stock->quantity_reserved - $quantity, 4), 0.0);
            $stock->quantity_available = max(round((float) $stock->quantity_on_hand - (float) $stock->quantity_reserved, 4), 0.0);
            $stock->save();
        }

        $reservation->status = TenantStockReservation::STATUS_RELEASED;
        $reservation->released_at = now();
        $reservation->save();

        return $quantity;
    }
}
