<?php

namespace App\Models;

use App\Exceptions\Currency\InvalidExchangeRateException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'rate_type',
        'source_currency',
        'target_currency',
        'rate_date',
        'source_unit',
        'rate',
        'fetched_at',
        'payload_hash',
        'meta_json',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'fetched_at' => 'datetime',
        'meta_json' => 'array',
        'source_unit' => 'integer',
        'rate' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rate): void {
            $rate->provider = trim((string) $rate->provider);
            $rate->rate_type = trim((string) $rate->rate_type);
            $rate->source_currency = strtoupper(trim((string) $rate->source_currency));
            $rate->target_currency = strtoupper(trim((string) $rate->target_currency));

            if ((int) $rate->source_unit <= 0) {
                throw InvalidExchangeRateException::becauseSourceUnitIsInvalid($rate->source_unit);
            }

            if ((float) $rate->rate <= 0) {
                throw InvalidExchangeRateException::becauseRateIsInvalid($rate->rate);
            }
        });
    }
}
