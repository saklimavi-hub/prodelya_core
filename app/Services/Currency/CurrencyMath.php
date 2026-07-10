<?php

namespace App\Services\Currency;

use App\Exceptions\Currency\InvalidExchangeRateException;

class CurrencyMath
{
    public function __construct(
        private readonly int $rateScale = 8,
        private readonly int $calculationScale = 12,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            rateScale: (int) config('prodelya_currency.rate_precision', 8),
            calculationScale: (int) config('prodelya_currency.calculation_precision', 12),
        );
    }

    public function normalizeNumber(int|float|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $value = sprintf('%.14F', $value);
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            $normalized = '0';
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw InvalidExchangeRateException::becauseRateIsInvalid($value);
        }

        return $this->trimScale($normalized);
    }

    public function compare(int|float|string $left, int|float|string $right, ?int $scale = null): int
    {
        return bccomp($this->normalizeNumber($left), $this->normalizeNumber($right), $scale ?? $this->calculationScale);
    }

    public function multiply(int|float|string $left, int|float|string $right, ?int $scale = null): string
    {
        return $this->trimScale(bcmul($this->normalizeNumber($left), $this->normalizeNumber($right), $scale ?? $this->calculationScale));
    }

    public function divide(int|float|string $left, int|float|string $right, ?int $scale = null): string
    {
        if ($this->compare($right, '0', $scale ?? $this->calculationScale) === 0) {
            throw InvalidExchangeRateException::becauseRateIsInvalid($right);
        }

        return $this->trimScale(bcdiv($this->normalizeNumber($left), $this->normalizeNumber($right), $scale ?? $this->calculationScale));
    }

    public function round(int|float|string $value, int $precision): string
    {
        $normalized = $this->normalizeNumber($value);
        $offset = '0.' . str_repeat('0', max(0, $precision)) . '5';

        if ($this->compare($normalized, '0', $precision + 2) >= 0) {
            return $this->trimScale(bcadd($normalized, $offset, $precision), $precision);
        }

        return $this->trimScale(bcsub($normalized, $offset, $precision), $precision);
    }

    public function ensurePositiveRate(int|float|string $rate): string
    {
        $normalized = $this->normalizeNumber($rate);

        if ($this->compare($normalized, '0', $this->rateScale) <= 0) {
            throw InvalidExchangeRateException::becauseRateIsInvalid($rate);
        }

        return $this->trimScale($normalized, $this->rateScale);
    }

    private function trimScale(string $value, ?int $maxScale = null): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        [$whole, $fraction] = explode('.', $value, 2);
        $fraction = rtrim($fraction, '0');

        if ($maxScale !== null) {
            $fraction = substr($fraction, 0, $maxScale);
        }

        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }
}
