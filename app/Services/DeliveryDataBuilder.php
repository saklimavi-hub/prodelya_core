<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Support\Str;

class DeliveryDataBuilder
{
    public function build(
        OrderItemWorkForm $workForm,
        ?OrderItemWorkFormDelivery $delivery = null
    ): array {
        $workForm->loadMissing(['order', 'orderItem', 'attachments']);

        $orderSnapshot = is_array($workForm->order_snapshot) ? $workForm->order_snapshot : [];
        $productSnapshot = is_array($workForm->product_snapshot) ? $workForm->product_snapshot : [];
        $existingSnapshot = is_array($workForm->delivery_snapshot) ? $workForm->delivery_snapshot : [];
        $deliveryMethod = $delivery?->delivery_method ?? $this->normalizeDeliveryMethod(
            $workForm->order?->delivery_type ?: data_get($orderSnapshot, 'delivery_type')
        );
        $deliveryStatus = $delivery?->delivery_status ?? OrderItemWorkFormDelivery::STATUS_PENDING;
        $plannedQuantity = round((float) ($delivery?->planned_quantity ?? data_get($productSnapshot, 'quantity', $workForm->orderItem?->quantity ?? 0)), 4);
        $deliveredQuantity = round((float) ($delivery?->delivered_quantity ?? 0), 4);
        $remainingQuantity = round((float) ($delivery?->remaining_quantity ?? max($plannedQuantity - $deliveredQuantity, 0)), 4);
        $packageType = $delivery?->package_type;
        $packageTypeLabel = $this->packageTypeLabel($packageType);

        return [
            'order_id' => $workForm->order_id,
            'order_number' => $workForm->order?->document_number ?: data_get($orderSnapshot, 'document_number'),
            'work_form_id' => $workForm->id,
            'work_form_number' => $workForm->work_form_number,
            'order_item_id' => $workForm->order_item_id,
            'product_name' => data_get($productSnapshot, 'product_name', $workForm->orderItem?->product_name),
            'product_code' => data_get($productSnapshot, 'product_code', $workForm->orderItem?->product_code),
            'ordered_quantity' => (float) data_get($productSnapshot, 'quantity', $workForm->orderItem?->quantity ?? 0),
            'quantity' => (float) data_get($productSnapshot, 'quantity', $workForm->orderItem?->quantity ?? 0),
            'unit' => data_get($productSnapshot, 'unit', $workForm->orderItem?->unit),
            'status' => $deliveryStatus,
            'status_label' => $this->statusLabel($deliveryStatus),
            'delivery_status' => $deliveryStatus,
            'delivery_status_label' => $this->statusLabel($deliveryStatus),
            'public_status_label' => $this->publicStatusLabel($deliveryStatus),
            'delivery_method' => $deliveryMethod,
            'delivery_method_label' => $this->deliveryMethodLabel($deliveryMethod),
            'delivery_type' => $this->deliveryMethodLabel($deliveryMethod) ?: (data_get($orderSnapshot, 'delivery_type') ?: null),
            'planned_quantity' => $plannedQuantity,
            'delivered_quantity' => $deliveredQuantity,
            'remaining_quantity' => $remainingQuantity,
            'package_count' => $delivery?->package_count,
            'units_per_package' => $delivery?->units_per_package,
            'packaged_quantity' => $delivery?->packaged_quantity,
            'package_type' => $packageType,
            'package_type_label' => $packageTypeLabel,
            'package_note' => $this->sanitizeNote($delivery?->package_note),
            'carrier_name' => $delivery?->carrier_name,
            'carrier_type' => $delivery?->carrier_name,
            'tracking_number' => $delivery?->tracking_number,
            'recipient_name' => $delivery?->recipient_name,
            'delivery_document_no' => $delivery?->delivery_document_no,
            'delivery_note' => $this->sanitizeNote($delivery?->delivery_note),
            'delivered_at' => optional($delivery?->delivered_at)->toAtomString(),
            'photo_count' => (int) data_get($existingSnapshot, 'photo_count', 0),
            'document_count' => (int) data_get($existingSnapshot, 'document_count', 0),
            'financial_warning' => $delivery?->financial_warning ?: OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING,
            'financial_warning_label' => $this->financialWarningLabel(
                $delivery?->financial_warning ?: OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING
            ),
            'production_status_label' => data_get($workForm->production_snapshot, 'production_status_label')
                ?: $this->fallbackStatusLabel(data_get($workForm->production_snapshot, 'status')),
            'readiness_warnings' => $this->readinessWarnings($workForm),
            'updated_at' => optional($delivery?->updated_at ?: $workForm->updated_at)->toAtomString(),
        ];
    }

    public function buildWorkFormSnapshot(OrderItemWorkFormDelivery $delivery): array
    {
        $snapshot = is_array($delivery->delivery_snapshot) ? $delivery->delivery_snapshot : [];
        $existing = is_array($delivery->workForm?->delivery_snapshot) ? $delivery->workForm->delivery_snapshot : [];
        $deliveryMethodLabel = $delivery->safeDeliveryMethodLabel();
        $packageTypeLabel = $this->packageTypeLabel($delivery->package_type);

        return [
            'delivery_id' => $delivery->id,
            'status' => $delivery->delivery_status,
            'status_label' => $delivery->safeStatusLabel(),
            'delivery_status' => $delivery->delivery_status,
            'delivery_status_label' => $delivery->safeStatusLabel(),
            'public_status_label' => $delivery->publicStatusLabel(),
            'delivery_method' => $delivery->delivery_method,
            'delivery_method_label' => $deliveryMethodLabel,
            'delivery_type' => $deliveryMethodLabel,
            'planned_quantity' => (float) $delivery->planned_quantity,
            'ordered_quantity' => (float) $delivery->planned_quantity,
            'delivered_quantity' => (float) $delivery->delivered_quantity,
            'remaining_quantity' => (float) $delivery->remaining_quantity,
            'package_count' => $delivery->package_count,
            'units_per_package' => $delivery->units_per_package,
            'packaged_quantity' => $delivery->packaged_quantity,
            'package_type' => $delivery->package_type,
            'package_type_label' => $packageTypeLabel,
            'package_note' => $this->sanitizeNote($delivery->package_note),
            'carrier_name' => $delivery->carrier_name,
            'carrier_type' => $delivery->carrier_name,
            'tracking_number' => $delivery->tracking_number,
            'recipient_name' => $delivery->recipient_name,
            'delivery_document_no' => $delivery->delivery_document_no,
            'delivery_note' => $this->sanitizeNote($delivery->delivery_note),
            'delivered_at' => optional($delivery->delivered_at)->toAtomString(),
            'financial_warning' => $delivery->financial_warning ?: OrderItemWorkFormDelivery::WARNING_NONE,
            'financial_warning_label' => $delivery->safeFinancialWarningLabel(),
            'production_status_label' => data_get($snapshot, 'production_status_label', '-'),
            'readiness_warnings' => array_values((array) data_get($snapshot, 'readiness_warnings', [])),
            'photo_count' => (int) data_get($existing, 'photo_count', 0),
            'document_count' => (int) data_get($existing, 'document_count', 0),
            'updated_at' => optional($delivery->updated_at)->toAtomString(),
        ];
    }

    public function publicStatusLabel(string $status): string
    {
        return OrderItemWorkFormDelivery::publicStatusLabels()[$status]
            ?? 'Teslimat bekliyor';
    }

    public function normalizeDeliveryMethod(?string $deliveryType): ?string
    {
        $normalized = trim(Str::ascii(mb_strtolower((string) $deliveryType)));

        return match ($normalized) {
            '' => null,
            'kargo' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'kurye' => OrderItemWorkFormDelivery::METHOD_COURIER,
            'elden', 'elden teslim' => OrderItemWorkFormDelivery::METHOD_HAND,
            'ambar' => OrderItemWorkFormDelivery::METHOD_FREIGHT,
            'musteri alacak', 'musteri_alacak', 'musteri teslim alacak' => OrderItemWorkFormDelivery::METHOD_CUSTOMER_PICKUP,
            default => OrderItemWorkFormDelivery::METHOD_OTHER,
        };
    }

    private function readinessWarnings(OrderItemWorkForm $workForm): array
    {
        $warnings = [];
        $productionStatus = (string) data_get($workForm->production_snapshot, 'production_status', data_get($workForm->production_snapshot, 'status', ''));
        $qcStatus = (string) data_get($workForm->production_snapshot, 'qc_status', '');
        $procurementStatus = (string) data_get($workForm->procurement_snapshot, 'procurement_status', '');

        if (!in_array($productionStatus, ['tamamlandi', 'gerekli_degil'], true)) {
            $warnings[] = 'Üretim tamamlanmadan teslimat başlatılmamalı.';
        }

        if (!in_array($qcStatus, ['uygun', 'gerekli_degil'], true)) {
            $warnings[] = 'Kalite kontrol tamamlanmadı.';
        }

        if ($procurementStatus !== '' && !in_array($procurementStatus, [
            'tamami_geldi',
            'tedarik_gerekmiyor',
            'musteri_urunu_geldi',
        ], true)) {
            $warnings[] = 'Tedarik süreci tamamlanmadı.';
        }

        return array_values(array_unique($warnings));
    }

    private function statusLabel(?string $status): string
    {
        if (!$status) {
            return 'Teslimat Bekliyor';
        }

        return OrderItemWorkFormDelivery::statusLabels()[$status]
            ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function deliveryMethodLabel(?string $method): ?string
    {
        if (!$method) {
            return null;
        }

        return OrderItemWorkFormDelivery::deliveryMethodLabels()[$method]
            ?? ucfirst(str_replace('_', ' ', $method));
    }

    private function financialWarningLabel(?string $warning): string
    {
        return OrderItemWorkFormDelivery::financialWarningLabels()[$warning ?: OrderItemWorkFormDelivery::WARNING_NONE]
            ?? 'Finans uyarısı yok';
    }

    private function packageTypeLabel(?string $packageType): ?string
    {
        if (!$packageType) {
            return null;
        }

        return OrderItemWorkFormDelivery::packageTypeLabels()[$packageType]
            ?? ucfirst(str_replace('_', ' ', $packageType));
    }

    private function fallbackStatusLabel(?string $status): string
    {
        if (!$status) {
            return '-';
        }

        return ucfirst(str_replace('_', ' ', $status));
    }

    private function sanitizeNote(mixed $note): ?string
    {
        if (!is_scalar($note)) {
            return null;
        }

        $value = trim((string) $note);

        if ($value === '') {
            return null;
        }

        foreach ($this->forbiddenTextFragments() as $fragment) {
            if (str_contains(mb_strtolower($value), $fragment)) {
                return null;
            }
        }

        return $value;
    }

    private function forbiddenTextFragments(): array
    {
        return [
            'fiyat',
            'price',
            'cost',
            'maliyet',
            'kdv',
            'kar',
            'kâr',
            'margin',
            'toplam',
            'group_code',
            'raw_mapping',
            'storage/app',
            'c:\\',
            '/var/',
        ];
    }
}
