<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantNumberSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'document_type',
        'order_family',
        'year',
        'month',
        'prefix',
        'sequence_length',
        'current_number',
        'reset_period',
        'format',
    ];

    protected $casts = [
        'document_type' => 'string',
        'order_family' => 'string',
        'year' => 'integer',
        'month' => 'integer',
        'sequence_length' => 'integer',
        'current_number' => 'integer',
        'reset_period' => 'string',
    ];

    /**
     * Get the tenant that owns this number sequence
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Generate the next document number
     */
    public function getNextNumber()
    {
        $this->increment('current_number');
        $this->refresh();
        
        return $this->formatNumber($this->current_number);
    }

    /**
     * Format a number according to the sequence format
     */
    public function formatNumber($number)
    {
        $paddedNumber = str_pad($number, $this->sequence_length, '0', STR_PAD_LEFT);
        
        return str_replace([
            '{YYYY}',
            '{YY}',
            '{MM}',
            '{SEQ4}',
            '{SEQ}',
        ], [
            $this->year,
            substr($this->year, -2),
            str_pad($this->month ?? 0, 2, '0', STR_PAD_LEFT),
            str_pad($number, 4, '0', STR_PAD_LEFT),
            $paddedNumber,
        ], $this->format);
    }

    /**
     * Get or create a sequence for the given parameters
     */
    public static function getOrCreate($tenantId, $documentType, $orderFamily = null, $year = null, $month = null)
    {
        $year = $year ?? date('Y');
        $month = $month ?? null;
        
        $sequence = static::where('tenant_account_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('order_family', $orderFamily)
            ->where('year', $year)
            ->where('month', $month)
            ->first();
            
        if (!$sequence) {
            $format = $documentType === 'quote' ? 'TK-{YYYY}-{SEQ4}' : 'SP-{YYYY}-{SEQ4}';
            $prefix = $documentType === 'quote' ? 'TK' : 'SP';
            
            $sequence = static::create([
                'tenant_account_id' => $tenantId,
                'document_type' => $documentType,
                'order_family' => $orderFamily,
                'year' => $year,
                'month' => $month,
                'prefix' => $prefix,
                'sequence_length' => 4,
                'current_number' => 0,
                'reset_period' => 'yearly',
                'format' => $format,
            ]);
        }
        
        return $sequence;
    }

    /**
     * Reset sequence if needed based on reset period
     */
    public function resetIfNeeded()
    {
        $currentYear = date('Y');
        $currentMonth = date('n');
        
        $shouldReset = false;
        
        switch ($this->reset_period) {
            case 'yearly':
                $shouldReset = $this->year < $currentYear;
                break;
            case 'monthly':
                $shouldReset = $this->year < $currentYear || 
                               ($this->year == $currentYear && $this->month < $currentMonth);
                break;
        }
        
        if ($shouldReset) {
            $this->current_number = 0;
            $this->year = $currentYear;
            $this->month = $this->reset_period === 'monthly' ? $currentMonth : null;
            $this->save();
        }
    }
}
