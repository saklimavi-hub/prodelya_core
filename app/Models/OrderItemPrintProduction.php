<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItemPrintProduction extends Model
{
    use HasFactory;

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_OUTSOURCED = 'outsourced';

    public const STATUS_PENDING = 'uretim_bekliyor';
    public const STATUS_INTERNAL = 'ic_uretimde';
    public const STATUS_PARTIALLY_COMPLETED = 'kismi_basildi';
    public const STATUS_SENT_TO_SUBCONTRACTOR = 'fasona_gonderildi';
    public const STATUS_RETURNED_FROM_SUBCONTRACTOR = 'fasondan_geldi';
    public const STATUS_QUALITY_CONTROL = 'kalite_kontrol';
    public const STATUS_COMPLETED = 'tamamlandi';
    public const STATUS_PROBLEMATIC = 'sorunlu';
    public const STATUS_CANCELLED = 'iptal';

    public const CLICHE_NOT_REQUIRED = 'gerekli_degil';
    public const CLICHE_AVAILABLE = 'mevcut';
    public const CLICHE_NEW = 'yeni_yapilacak';
    public const CLICHE_WAITING = 'bekleniyor';
    public const CLICHE_READY = 'hazir';

    public const QC_WAITING = 'bekliyor';
    public const QC_OK = 'uygun';
    public const QC_PROBLEMATIC = 'sorunlu';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'order_item_print_id',
        'work_form_id',
        'production_type',
        'production_status',
        'production_company_id',
        'production_unit_name',
        'assigned_to',
        'planned_quantity',
        'completed_quantity',
        'remaining_quantity',
        'cliche_required',
        'cliche_status',
        'qc_status',
        'production_note',
        'issue_note',
        'subcontractor_cost',
        'subcontractor_cost_currency',
        'subcontractor_cost_note',
        'production_snapshot',
        'started_at',
        'sent_to_subcontractor_at',
        'returned_from_subcontractor_at',
        'qc_started_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'completed_quantity' => 'decimal:4',
        'remaining_quantity' => 'decimal:4',
        'subcontractor_cost' => 'decimal:2',
        'cliche_required' => 'boolean',
        'production_snapshot' => 'array',
        'started_at' => 'datetime',
        'sent_to_subcontractor_at' => 'datetime',
        'returned_from_subcontractor_at' => 'datetime',
        'qc_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function orderItemPrint(): BelongsTo
    {
        return $this->belongsTo(OrderItemPrint::class, 'order_item_print_id');
    }

    public function graphicOperation(): HasOne
    {
        return $this->hasOne(OrderItemPrintGraphic::class, 'order_item_print_id', 'order_item_print_id');
    }

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'work_form_id');
    }

    public function productionCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'production_company_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isInternal(): bool
    {
        return $this->production_type === self::TYPE_INTERNAL;
    }

    public function isExternal(): bool
    {
        return $this->production_type === self::TYPE_EXTERNAL;
    }

    public function isOutsourced(): bool
    {
        return $this->production_type === self::TYPE_OUTSOURCED;
    }

    public function isCompleted(): bool
    {
        return $this->production_status === self::STATUS_COMPLETED
            || $this->remainingQuantity() <= 0.0;
    }

    public function isProblematic(): bool
    {
        return $this->production_status === self::STATUS_PROBLEMATIC
            || $this->qc_status === self::QC_PROBLEMATIC;
    }

    public function remainingQuantity(): float
    {
        return (float) $this->remaining_quantity;
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->production_status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->production_status));
    }

    public function safeProductionTypeLabel(): string
    {
        return self::productionTypeLabels()[$this->production_type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->production_type));
    }

    public function safeClicheStatusLabel(): string
    {
        return self::clicheStatusLabels()[$this->cliche_status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->cliche_status));
    }

    public function safeQcStatusLabel(): string
    {
        return self::qcStatusLabels()[$this->qc_status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->qc_status));
    }

    public static function productionTypeLabels(): array
    {
        return [
            self::TYPE_INTERNAL => 'İç Üretim',
            self::TYPE_EXTERNAL => 'Dış Üretim',
            self::TYPE_OUTSOURCED => 'Dış Üretim / Fason',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Üretim Bekliyor',
            self::STATUS_INTERNAL => 'İç Üretimde',
            self::STATUS_PARTIALLY_COMPLETED => 'Kısmi Basıldı',
            self::STATUS_SENT_TO_SUBCONTRACTOR => 'Fasona Gönderildi',
            self::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'Fasondan Geldi',
            self::STATUS_QUALITY_CONTROL => 'Kalite Kontrol',
            self::STATUS_COMPLETED => 'Tamamlandı',
            self::STATUS_PROBLEMATIC => 'Sorunlu',
            self::STATUS_CANCELLED => 'İptal',
        ];
    }

    public static function clicheStatusLabels(): array
    {
        return [
            self::CLICHE_NOT_REQUIRED => 'Gerekli Değil',
            self::CLICHE_AVAILABLE => 'Mevcut',
            self::CLICHE_NEW => 'Yeni Yapılacak',
            self::CLICHE_WAITING => 'Bekleniyor',
            self::CLICHE_READY => 'Hazır',
        ];
    }

    public static function qcStatusLabels(): array
    {
        return [
            self::QC_WAITING => 'Bekliyor',
            self::QC_OK => 'Uygun',
            self::QC_PROBLEMATIC => 'Sorunlu',
        ];
    }
}
