<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDeliveryLabelBatch extends Model
{
    use HasFactory;

    public const TEMPLATE_A4_1_4 = 'a4_1_4';
    public const TEMPLATE_A4_1_2 = 'a4_1_2';
    public const TEMPLATE_A4_1_1 = 'a4_1_1';
    public const TEMPLATE_ROLL = 'roll';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_PRINTED = 'printed';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'template_type',
        'label_count',
        'page_count',
        'roll_width_mm',
        'roll_height_mm',
        'roll_gap_mm',
        'status',
        'printed_at',
        'created_by',
    ];

    protected $casts = [
        'printed_at' => 'datetime',
        'roll_width_mm' => 'decimal:2',
        'roll_height_mm' => 'decimal:2',
        'roll_gap_mm' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function templateLabels(): array
    {
        return [
            self::TEMPLATE_A4_1_4 => 'A4 1/4',
            self::TEMPLATE_A4_1_2 => 'A4 1/2',
            self::TEMPLATE_A4_1_1 => 'A4 1/1',
            self::TEMPLATE_ROLL => 'Rulo Etiket',
        ];
    }
}
