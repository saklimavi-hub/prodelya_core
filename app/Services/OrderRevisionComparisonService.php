<?php

namespace App\Services;

use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Support\Collection;

class OrderRevisionComparisonService
{
    private const DECISION_APPLICABLE = 'Uygulanabilir';
    private const DECISION_CONTROLLED = 'Kontrollü Uygulanabilir';
    private const DECISION_LOCKED = 'Kilitli';
    private const DECISION_MANUAL = 'Manuel Kontrol Gerekli';
    private const DECISION_NONE = 'Değişiklik Yok';

    public function build(Order $revisionQuote): array
    {
        $revisionQuote->loadMissing([
            'customer',
            'sourceOrder.customer',
            'sourceOrder.items.procurement',
            'sourceOrder.items.delivery',
            'sourceOrder.items.prints.production',
            'items.prints',
        ]);

        $sourceOrder = $revisionQuote->sourceOrder;

        $lineComparisons = $this->buildLineComparisons(
            $sourceOrder?->items?->values() ?? collect(),
            $revisionQuote->items->values()
        );

        $processGates = $this->buildProcessGates($sourceOrder);
        $decisionMatrix = $this->buildDecisionMatrix($sourceOrder, $revisionQuote, $lineComparisons, $processGates);
        $summary = $this->buildSummary($sourceOrder, $revisionQuote, $lineComparisons, $decisionMatrix, $processGates);

        return [
            'sourceOrder' => $sourceOrder,
            'revisionQuote' => $revisionQuote,
            'lineComparisons' => $lineComparisons,
            'decisionMatrix' => $decisionMatrix,
            'processGates' => $processGates,
            'summary' => $summary,
        ];
    }

    private function buildLineComparisons(Collection $sourceItems, Collection $revisionItems): array
    {
        $usedRevisionIds = [];
        $rows = [];

        foreach ($sourceItems->values() as $sourceIndex => $sourceItem) {
            [$matchedRevision, $matchConfidence, $matchReason] = $this->findBestItemMatch(
                $sourceItem,
                $sourceIndex,
                $revisionItems,
                $usedRevisionIds
            );

            if ($matchedRevision) {
                $usedRevisionIds[] = $matchedRevision->id;
            }

            $rows[] = $this->mapItemComparisonRow(
                $sourceItem,
                $matchedRevision,
                $sourceIndex,
                $matchedRevision ? $revisionItems->search(fn (OrderItem $item) => $item->id === $matchedRevision->id) : null,
                $matchConfidence,
                $matchReason
            );
        }

        foreach ($revisionItems->values() as $revisionIndex => $revisionItem) {
            if (in_array($revisionItem->id, $usedRevisionIds, true)) {
                continue;
            }

            $rows[] = $this->mapItemComparisonRow(
                null,
                $revisionItem,
                null,
                $revisionIndex,
                'new',
                'Revizyon teklifinde yeni eklenen kalem.'
            );
        }

        return array_values($rows);
    }

    private function findBestItemMatch(
        OrderItem $sourceItem,
        int $sourceIndex,
        Collection $revisionItems,
        array $usedRevisionIds
    ): array {
        $sameIndexItem = $revisionItems->get($sourceIndex);

        if ($sameIndexItem instanceof OrderItem && ! in_array($sameIndexItem->id, $usedRevisionIds, true)) {
            if ($this->sameProductIdentity($sourceItem, $sameIndexItem)) {
                return [$sameIndexItem, 'exact', 'Kalem aynı sıra ve aynı ürün kimliği ile eşleşti.'];
            }
        }

        $sameIdentity = $revisionItems
            ->first(fn (OrderItem $revisionItem) => ! in_array($revisionItem->id, $usedRevisionIds, true)
                && $this->sameProductIdentity($sourceItem, $revisionItem));

        if ($sameIdentity instanceof OrderItem) {
            return [$sameIdentity, 'identity', 'Kalem ürün kimliğine göre eşleşti.'];
        }

        if ($sameIndexItem instanceof OrderItem && ! in_array($sameIndexItem->id, $usedRevisionIds, true)) {
            return [$sameIndexItem, 'sequence', 'Kalem sıra bazında eşleşti; ürün farkı revizyon değişikliği olarak gösterildi.'];
        }

        return [null, 'removed', 'Kaynak siparişteki kalem revizyonda bulunamadı.'];
    }

    private function mapItemComparisonRow(
        ?OrderItem $sourceItem,
        ?OrderItem $revisionItem,
        ?int $sourceIndex,
        ?int $revisionIndex,
        string $matchConfidence,
        string $matchReason
    ): array {
        $prints = $this->buildPrintComparisons(
            $sourceItem?->prints?->values() ?? collect(),
            $revisionItem?->prints?->values() ?? collect(),
            $sourceIndex,
            $revisionIndex
        );

        $flags = [
            'is_new' => $sourceItem === null && $revisionItem !== null,
            'is_removed' => $sourceItem !== null && $revisionItem === null,
            'uncertain_match' => $matchConfidence === 'sequence_only',
            'product_changed' => $sourceItem && $revisionItem
                ? ! $this->sameProductIdentity($sourceItem, $revisionItem)
                : ($sourceItem !== $revisionItem),
            'quantity_changed' => $sourceItem && $revisionItem
                ? round((float) $sourceItem->quantity, 4) !== round((float) $revisionItem->quantity, 4)
                : false,
            'price_changed' => $sourceItem && $revisionItem
                ? $this->moneyChanged($sourceItem->unit_price, $revisionItem->unit_price)
                    || $this->moneyChanged($sourceItem->line_total, $revisionItem->line_total)
                : false,
            'print_type_changed' => collect($prints)->contains(fn (array $print) => $print['flags']['print_type_changed'] ?? false),
            'print_note_changed' => collect($prints)->contains(fn (array $print) => $print['flags']['print_note_changed'] ?? false),
        ];

        $itemStatus = $this->resolveItemStatus($flags);

        return [
            'sequence' => $sourceItem ? (string) (($sourceIndex ?? 0) + 1) : 'Yeni',
            'status' => $itemStatus,
            'status_tone' => $this->statusTone($itemStatus),
            'match_confidence' => $matchConfidence,
            'match_reason' => $matchReason,
            'source_item_id' => $sourceItem?->id,
            'revision_item_id' => $revisionItem?->id,
            'flags' => $flags,
            'source' => $this->mapItemSide($sourceItem),
            'revision' => $this->mapItemSide($revisionItem),
            'prints' => $prints,
        ];
    }

    private function buildPrintComparisons(
        Collection $sourcePrints,
        Collection $revisionPrints,
        ?int $sourceIndex,
        ?int $revisionIndex
    ): array {
        $usedRevisionIds = [];
        $rows = [];

        foreach ($sourcePrints->values() as $printIndex => $sourcePrint) {
            [$matchedRevision, $matchConfidence, $matchReason] = $this->findBestPrintMatch(
                $sourcePrint,
                $printIndex,
                $revisionPrints,
                $usedRevisionIds
            );

            if ($matchedRevision) {
                $usedRevisionIds[] = $matchedRevision->id;
            }

            $flags = [
                'is_new' => false,
                'is_removed' => $matchedRevision === null,
                'uncertain_match' => $matchConfidence === 'sequence_only',
                'print_type_changed' => $matchedRevision
                    ? ! $this->samePrintIdentity($sourcePrint, $matchedRevision)
                    : false,
                'print_note_changed' => $matchedRevision
                    ? trim((string) $sourcePrint->note) !== trim((string) $matchedRevision->note)
                    : false,
                'price_changed' => $matchedRevision
                    ? $this->moneyChanged($sourcePrint->print_unit_price, $matchedRevision->print_unit_price)
                        || $this->moneyChanged($sourcePrint->print_total, $matchedRevision->print_total)
                    : false,
            ];

            $status = $this->resolvePrintStatus($flags);
            $itemLabel = $sourceIndex !== null ? (string) ($sourceIndex + 1) : (string) (($revisionIndex ?? 0) + 1);

            $rows[] = [
                'sequence' => $itemLabel . '.' . ($printIndex + 1),
                'status' => $status,
                'status_tone' => $this->statusTone($status),
                'match_confidence' => $matchConfidence,
                'match_reason' => $matchReason,
                'source_print_id' => $sourcePrint->id,
                'revision_print_id' => $matchedRevision?->id,
                'flags' => $flags,
                'source' => $this->mapPrintSide($sourcePrint),
                'revision' => $this->mapPrintSide($matchedRevision),
            ];
        }

        foreach ($revisionPrints->values() as $printIndex => $revisionPrint) {
            if (in_array($revisionPrint->id, $usedRevisionIds, true)) {
                continue;
            }

            $itemLabel = $revisionIndex !== null ? (string) ($revisionIndex + 1) : 'Yeni';

            $rows[] = [
                'sequence' => $itemLabel . '.' . ($printIndex + 1),
                'status' => 'Yeni Eklendi',
                'status_tone' => $this->statusTone('Yeni Eklendi'),
                'match_confidence' => 'new',
                'match_reason' => 'Revizyon teklifinde yeni baskı kalemi eklendi.',
                'source_print_id' => null,
                'revision_print_id' => $revisionPrint->id,
                'flags' => [
                    'is_new' => true,
                    'is_removed' => false,
                    'uncertain_match' => false,
                    'print_type_changed' => false,
                    'print_note_changed' => false,
                    'price_changed' => false,
                ],
                'source' => $this->mapPrintSide(null),
                'revision' => $this->mapPrintSide($revisionPrint),
            ];
        }

        return array_values($rows);
    }

    private function findBestPrintMatch(
        OrderItemPrint $sourcePrint,
        int $printIndex,
        Collection $revisionPrints,
        array $usedRevisionIds
    ): array {
        $sameIndexPrint = $revisionPrints->get($printIndex);

        if ($sameIndexPrint instanceof OrderItemPrint && ! in_array($sameIndexPrint->id, $usedRevisionIds, true)) {
            if ($this->samePrintIdentity($sourcePrint, $sameIndexPrint)) {
                return [$sameIndexPrint, 'exact', 'Baskı kalemi aynı sıra ve baskı tipi ile eşleşti.'];
            }
        }

        $sameIdentity = $revisionPrints
            ->first(fn (OrderItemPrint $revisionPrint) => ! in_array($revisionPrint->id, $usedRevisionIds, true)
                && $this->samePrintIdentity($sourcePrint, $revisionPrint));

        if ($sameIdentity instanceof OrderItemPrint) {
            return [$sameIdentity, 'identity', 'Baskı kalemi baskı tipi ve lokasyonuna göre eşleşti.'];
        }

        if ($sameIndexPrint instanceof OrderItemPrint && ! in_array($sameIndexPrint->id, $usedRevisionIds, true)) {
            return [$sameIndexPrint, 'sequence_only', 'Baskı kalemi yalnızca sıra bazında eşleşti; manuel kontrol önerilir.'];
        }

        return [null, 'removed', 'Kaynak siparişteki baskı kalemi revizyonda bulunamadı.'];
    }

    private function buildProcessGates(?Order $sourceOrder): array
    {
        if (! $sourceOrder) {
            return [];
        }

        $procurements = $sourceOrder->relationLoaded('procurements')
            ? $sourceOrder->procurements
            : $sourceOrder->procurements()->get();
        $productions = $sourceOrder->relationLoaded('printProductions')
            ? $sourceOrder->printProductions
            : $sourceOrder->printProductions()->get();
        $deliveries = $sourceOrder->relationLoaded('deliveries')
            ? $sourceOrder->deliveries
            : $sourceOrder->deliveries()->get();
        $paymentsCount = $sourceOrder->relationLoaded('payments')
            ? $sourceOrder->payments->count()
            : $sourceOrder->payments()->count();
        $currentAccountTransactionCount = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $sourceOrder->tenant_account_id)
            ->where('source_type', 'order')
            ->where('source_id', $sourceOrder->id)
            ->count();

        $procurementStarted = $procurements->contains(fn (OrderItemProcurement $procurement) => ! in_array($procurement->procurement_status, [
            OrderItemProcurement::STATUS_PENDING,
            OrderItemProcurement::STATUS_CANCELLED,
            OrderItemProcurement::STATUS_NOT_REQUIRED,
        ], true));
        $procurementCompleted = $procurements->isNotEmpty()
            && $procurements->every(fn (OrderItemProcurement $procurement) => in_array($procurement->procurement_status, [
                OrderItemProcurement::STATUS_FULLY_RECEIVED,
                OrderItemProcurement::STATUS_CUSTOMER_RECEIVED,
                OrderItemProcurement::STATUS_NOT_REQUIRED,
                OrderItemProcurement::STATUS_CANCELLED,
            ], true));

        $productionStarted = $productions->contains(fn (OrderItemPrintProduction $production) => ! in_array($production->production_status, [
            OrderItemPrintProduction::STATUS_PENDING,
            OrderItemPrintProduction::STATUS_CANCELLED,
        ], true));
        $productionCompleted = $productions->isNotEmpty()
            && $productions->every(fn (OrderItemPrintProduction $production) => in_array($production->production_status, [
                OrderItemPrintProduction::STATUS_COMPLETED,
                OrderItemPrintProduction::STATUS_CANCELLED,
            ], true));

        $deliveryStarted = $deliveries->contains(fn (OrderItemWorkFormDelivery $delivery) => ! in_array($delivery->delivery_status, [
            OrderItemWorkFormDelivery::STATUS_PENDING,
            OrderItemWorkFormDelivery::STATUS_PREPARING,
            OrderItemWorkFormDelivery::STATUS_READY,
            OrderItemWorkFormDelivery::STATUS_CANCELLED,
        ], true));
        $deliveryCompleted = $deliveries->contains(fn (OrderItemWorkFormDelivery $delivery) => $delivery->delivery_status === OrderItemWorkFormDelivery::STATUS_DELIVERED);

        return [
            'procurement_started' => $procurementStarted,
            'procurement_completed' => $procurementCompleted,
            'production_started' => $productionStarted,
            'production_completed' => $productionCompleted,
            'delivery_started' => $deliveryStarted,
            'delivery_completed' => $deliveryCompleted,
            'finance_exists' => ($paymentsCount + $currentAccountTransactionCount) > 0,
            'rows' => [
                $this->gateRow('Tedarik', $procurementStarted, $procurementCompleted, 'Tedarik başlamadı', 'Tedarik devam ediyor', 'Tedarik tamamlandı'),
                $this->gateRow('Baskı / Üretim', $productionStarted, $productionCompleted, 'Üretim başlamadı', 'Üretim devam ediyor', 'Üretim tamamlandı'),
                $this->gateRow('Teslimat', $deliveryStarted, $deliveryCompleted, 'Teslimat başlamadı', 'Teslimat devam ediyor', 'Teslimat tamamlandı'),
                [
                    'label' => 'Finans / Cari',
                    'value' => $paymentsCount + $currentAccountTransactionCount > 0 ? 'Kayıt var' : 'Henüz kayıt yok',
                    'tone' => $paymentsCount + $currentAccountTransactionCount > 0 ? 'amber' : 'slate',
                    'helper' => $paymentsCount + $currentAccountTransactionCount > 0
                        ? 'Tahsilat veya cari hareketler kopyalanmaz; revizyon yalnızca karşılaştırılır.'
                        : 'Finansal kayıt bulunmadı.',
                ],
            ],
        ];
    }

    private function buildDecisionMatrix(Order $sourceOrder, Order $revisionQuote, array $lineComparisons, array $processGates): array
    {
        $productChanged = collect($lineComparisons)->contains(fn (array $row) => $row['flags']['product_changed'] ?? false);
        $quantityChanged = collect($lineComparisons)->contains(fn (array $row) => $row['flags']['quantity_changed'] ?? false);
        $printTypeChanged = collect($lineComparisons)->contains(fn (array $row) => $row['flags']['print_type_changed'] ?? false);
        $printNoteChanged = collect($lineComparisons)->contains(fn (array $row) => $row['flags']['print_note_changed'] ?? false);
        $priceChanged = collect($lineComparisons)->contains(fn (array $row) => $row['flags']['price_changed'] ?? false)
            || $this->moneyChanged($sourceOrder->grand_total, $revisionQuote->grand_total)
            || $this->moneyChanged($sourceOrder->subtotal, $revisionQuote->subtotal)
            || $this->moneyChanged($sourceOrder->print_total, $revisionQuote->print_total);
        $deliveryChanged = trim((string) $sourceOrder->delivery_type) !== trim((string) $revisionQuote->delivery_type);
        $uncertainMatch = collect($lineComparisons)->contains(fn (array $row) => $row['flags']['uncertain_match'] ?? false);

        return [
            $this->matrixRow(
                'Ürün Değişimi',
                $productChanged
                    ? ($uncertainMatch
                        ? self::DECISION_MANUAL
                        : ($processGates['procurement_started'] ? self::DECISION_LOCKED : self::DECISION_CONTROLLED))
                    : self::DECISION_NONE,
                $productChanged
                    ? ($processGates['procurement_started']
                        ? 'Tedarik süreci başladığı için ürün değişimi kilitli kabul edilir.'
                        : 'Ürün değişimi kontrollü uygulanabilir; yeni ürün ve stok seçimi tekrar doğrulanmalıdır.')
                    : 'Ürün kalemi değişmedi.'
            ),
            $this->matrixRow(
                'Adet Değişimi',
                ! $quantityChanged
                    ? self::DECISION_NONE
                    : (! $processGates['procurement_started']
                        ? self::DECISION_APPLICABLE
                        : self::DECISION_MANUAL),
                ! $quantityChanged
                    ? 'Miktar aynı kaldı.'
                    : (! $processGates['procurement_started']
                        ? 'Tedarik başlamadan adet güncellemesi uygulanabilir.'
                        : 'Tedarik hareketi başladığı için adet farkı manuel kontrol ister.')
            ),
            $this->matrixRow(
                'Baskı Tipi',
                ! $printTypeChanged
                    ? self::DECISION_NONE
                    : ($processGates['production_started'] ? self::DECISION_LOCKED : self::DECISION_CONTROLLED),
                ! $printTypeChanged
                    ? 'Baskı tipi aynı kaldı.'
                    : ($processGates['production_started']
                        ? 'Üretim başladığı için baskı tipi değişikliği kilitli kabul edilir.'
                        : 'Üretim başlamadan baskı tipi değişikliği kontrollü uygulanabilir.')
            ),
            $this->matrixRow(
                'Baskı Notu',
                ! $printNoteChanged
                    ? self::DECISION_NONE
                    : ($processGates['production_completed']
                        ? self::DECISION_LOCKED
                        : ($processGates['production_started'] ? self::DECISION_MANUAL : self::DECISION_APPLICABLE)),
                ! $printNoteChanged
                    ? 'Baskı notu aynı kaldı.'
                    : ($processGates['production_completed']
                        ? 'Üretim tamamlandıktan sonra baskı notu değişikliği kilitli kabul edilir.'
                        : ($processGates['production_started']
                            ? 'Üretim aktif olduğu için baskı notu farkı manuel kontrol ister.'
                            : 'Üretim başlamadan baskı notu güncellenebilir.'))
            ),
            $this->matrixRow(
                'Fiyat',
                $priceChanged ? self::DECISION_CONTROLLED : self::DECISION_NONE,
                $priceChanged
                    ? 'Fiyat farkı eski kaydı ezmeden revizyon farkı olarak izlenmelidir.'
                    : 'Fiyat değişikliği yok.'
            ),
            $this->matrixRow(
                'Teslim Bilgisi',
                ! $deliveryChanged
                    ? self::DECISION_NONE
                    : ($processGates['delivery_completed']
                        ? self::DECISION_LOCKED
                        : ($processGates['delivery_started'] ? self::DECISION_MANUAL : self::DECISION_APPLICABLE)),
                ! $deliveryChanged
                    ? 'Teslim bilgisi aynı kaldı.'
                    : ($processGates['delivery_completed']
                        ? 'Teslimat tamamlandığı için teslim bilgisi değişikliği kilitli kabul edilir.'
                        : ($processGates['delivery_started']
                            ? 'Teslimat akışı başladığı için teslim farkı manuel kontrol ister.'
                            : 'Teslimat başlamadan teslim bilgisi güncellenebilir.'))
            ),
        ];
    }

    private function buildSummary(
        Order $sourceOrder,
        Order $revisionQuote,
        array $lineComparisons,
        array $decisionMatrix,
        array $processGates
    ): array {
        $changedLines = collect($lineComparisons)->filter(fn (array $row) => in_array($row['status'], [
            'Değişti',
            'Kilitli',
            'Kontrol Gerekli',
            'Yeni Eklendi',
            'Kaldırıldı',
        ], true))->count();

        $manualOrLocked = collect($decisionMatrix)
            ->filter(fn (array $row) => in_array($row['decision'], [self::DECISION_MANUAL, self::DECISION_LOCKED], true))
            ->count();

        return [
            'source_label' => $sourceOrder->document_number ?: '-',
            'revision_label' => 'Revize ' . ((int) ($revisionQuote->revision_number ?: 1)),
            'quote_label' => $revisionQuote->document_number ?: '-',
            'customer_label' => $revisionQuote->customer?->legal_name ?: '-',
            'status_label' => $manualOrLocked > 0 ? 'Kontrollü karar gerekli' : 'Ön inceleme tamam',
            'status_tone' => $manualOrLocked > 0 ? 'amber' : 'green',
            'process_summary' => $this->buildProcessSummaryText($processGates),
            'counters' => [
                ['label' => 'Değişen Kalem', 'value' => $changedLines],
                ['label' => 'Kaynak Sipariş Kalemi', 'value' => $sourceOrder->items->count()],
                ['label' => 'Revizyon Baskı Kalemi', 'value' => $revisionQuote->items->sum(fn (OrderItem $item) => $item->prints->count())],
            ],
        ];
    }

    private function buildProcessSummaryText(array $processGates): string
    {
        $parts = [];

        $parts[] = $processGates['procurement_started']
            ? ($processGates['procurement_completed'] ? 'Tedarik tamamlandı' : 'Tedarik başladı')
            : 'Tedarik başlamadı';
        $parts[] = $processGates['production_started']
            ? ($processGates['production_completed'] ? 'üretim tamamlandı' : 'üretim başladı')
            : 'üretim başlamadı';
        $parts[] = $processGates['delivery_started']
            ? ($processGates['delivery_completed'] ? 'teslimat tamamlandı' : 'teslimat başladı')
            : 'teslimat başlamadı';

        return ucfirst(implode(', ', $parts)) . '.';
    }

    private function mapItemSide(?OrderItem $item): array
    {
        if (! $item) {
            return [
                'title' => '-',
                'code' => '-',
                'quantity' => '-',
                'unit_price' => '-',
                'line_total' => '-',
                'description' => '-',
            ];
        }

        return [
            'title' => trim((string) ($item->product_name ?: '-')),
            'code' => trim((string) ($item->product_code ?: '-')),
            'quantity' => $this->formatQuantity($item->quantity, $item->unit),
            'unit_price' => $this->formatMoney($item->unit_price, $item->order?->currency ?: 'TL'),
            'line_total' => $this->formatMoney($item->line_total, $item->order?->currency ?: 'TL'),
            'description' => trim((string) ($item->description ?: '-')),
        ];
    }

    private function mapPrintSide(?OrderItemPrint $print): array
    {
        if (! $print) {
            return [
                'type' => '-',
                'option' => '-',
                'location' => '-',
                'production_type' => '-',
                'quantity' => '-',
                'unit_price' => '-',
                'total' => '-',
                'note' => '-',
            ];
        }

        return [
            'type' => trim((string) ($print->print_type ?: '-')),
            'option' => trim((string) ($print->print_option ?: '-')),
            'location' => trim((string) ($print->print_location ?: '-')),
            'production_type' => trim((string) ($print->production_type ?: '-')),
            'quantity' => $this->formatQuantity($print->print_quantity, null),
            'unit_price' => $this->formatMoney($print->print_unit_price, $print->order?->currency ?: 'TL'),
            'total' => $this->formatMoney($print->print_total, $print->order?->currency ?: 'TL'),
            'note' => trim((string) ($print->note ?: '-')),
        ];
    }

    private function resolveItemStatus(array $flags): string
    {
        if ($flags['is_new']) {
            return 'Yeni Eklendi';
        }

        if ($flags['is_removed']) {
            return 'Kaldırıldı';
        }

        if ($flags['uncertain_match']) {
            return 'Kontrol Gerekli';
        }

        if ($flags['product_changed'] || $flags['quantity_changed'] || $flags['price_changed'] || $flags['print_type_changed'] || $flags['print_note_changed']) {
            return 'Değişti';
        }

        return 'Değişmedi';
    }

    private function resolvePrintStatus(array $flags): string
    {
        if ($flags['is_new']) {
            return 'Yeni Eklendi';
        }

        if ($flags['is_removed']) {
            return 'Kaldırıldı';
        }

        if ($flags['uncertain_match']) {
            return 'Kontrol Gerekli';
        }

        if ($flags['print_type_changed'] || $flags['print_note_changed'] || $flags['price_changed']) {
            return 'Değişti';
        }

        return 'Değişmedi';
    }

    private function matrixRow(string $label, string $decision, string $helper): array
    {
        return [
            'label' => $label,
            'decision' => $decision,
            'decision_tone' => $this->decisionTone($decision),
            'helper' => $helper,
        ];
    }

    private function gateRow(string $label, bool $started, bool $completed, string $pendingLabel, string $startedLabel, string $completedLabel): array
    {
        return [
            'label' => $label,
            'value' => $completed ? $completedLabel : ($started ? $startedLabel : $pendingLabel),
            'tone' => $completed ? 'green' : ($started ? 'amber' : 'slate'),
            'helper' => $completed
                ? $label . ' süreci tamamlandı.'
                : ($started ? $label . ' süreci aktif ilerliyor.' : $label . ' süreci henüz başlamadı.'),
        ];
    }

    private function sameProductIdentity(OrderItem $sourceItem, OrderItem $revisionItem): bool
    {
        if ($sourceItem->tenant_catalog_product_variant_id && $revisionItem->tenant_catalog_product_variant_id) {
            return (int) $sourceItem->tenant_catalog_product_variant_id === (int) $revisionItem->tenant_catalog_product_variant_id;
        }

        if ($sourceItem->standard_product_variant_id && $revisionItem->standard_product_variant_id) {
            return (int) $sourceItem->standard_product_variant_id === (int) $revisionItem->standard_product_variant_id;
        }

        if (filled($sourceItem->product_code) && filled($revisionItem->product_code)) {
            return trim((string) $sourceItem->product_code) === trim((string) $revisionItem->product_code);
        }

        return trim((string) $sourceItem->product_name) === trim((string) $revisionItem->product_name);
    }

    private function samePrintIdentity(OrderItemPrint $sourcePrint, OrderItemPrint $revisionPrint): bool
    {
        if ($sourcePrint->tenant_print_setting_id && $revisionPrint->tenant_print_setting_id) {
            return (int) $sourcePrint->tenant_print_setting_id === (int) $revisionPrint->tenant_print_setting_id
                && trim((string) $sourcePrint->print_location) === trim((string) $revisionPrint->print_location);
        }

        return trim((string) $sourcePrint->print_type) === trim((string) $revisionPrint->print_type)
            && trim((string) $sourcePrint->print_option) === trim((string) $revisionPrint->print_option)
            && trim((string) $sourcePrint->print_location) === trim((string) $revisionPrint->print_location);
    }

    private function moneyChanged(mixed $left, mixed $right): bool
    {
        return round((float) $left, 2) !== round((float) $right, 2);
    }

    private function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $value = (float) $quantity;
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

        return trim(number_format($value, $decimals, ',', '.') . ' ' . ($unit ?: 'Adet'));
    }

    private function formatMoney(mixed $amount, ?string $currency): string
    {
        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'Değişmedi' => 'slate',
            'Değişti' => 'blue',
            'Kilitli' => 'red',
            'Yeni Eklendi' => 'green',
            'Kaldırıldı' => 'red',
            'Kontrol Gerekli' => 'amber',
            default => 'slate',
        };
    }

    private function decisionTone(string $decision): string
    {
        return match ($decision) {
            self::DECISION_APPLICABLE => 'green',
            self::DECISION_CONTROLLED => 'blue',
            self::DECISION_LOCKED => 'red',
            self::DECISION_MANUAL => 'amber',
            self::DECISION_NONE => 'slate',
            default => 'slate',
        };
    }
}
