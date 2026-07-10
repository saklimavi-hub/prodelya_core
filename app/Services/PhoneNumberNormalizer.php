<?php

namespace App\Services;

class PhoneNumberNormalizer
{
    public function normalizeTurkishPhoneForWhatsapp(?string $value): ?string
    {
        $digits = $this->digitsOnly($value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '90') && strlen($digits) === 12 && $this->hasValidTurkishNationalPrefix(substr($digits, 2))) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11 && $this->hasValidTurkishNationalPrefix(substr($digits, 1))) {
            return '+90' . substr($digits, 1);
        }

        if (strlen($digits) === 10 && $this->hasValidTurkishNationalPrefix($digits)) {
            return '+90' . $digits;
        }

        return null;
    }

    public function normalizeTurkishMobileForWhatsapp(?string $value): ?string
    {
        return $this->normalizeTurkishPhoneForWhatsapp($value);
    }

    public function toWhatsappDialString(?string $value): ?string
    {
        $normalized = $this->normalizeTurkishPhoneForWhatsapp($value);

        return $normalized ? ltrim($normalized, '+') : null;
    }

    public function isLikelyTurkishMobile(?string $value): bool
    {
        return $this->normalizeTurkishPhoneForWhatsapp($value) !== null;
    }

    public function formatTurkishPhoneForDisplay(?string $value): string
    {
        $normalizedPhone = $this->normalizeTurkishPhoneForWhatsapp($value);

        if ($normalizedPhone) {
            return $this->formatElevenDigitLocal('0' . substr($normalizedPhone, 3));
        }

        $digits = $this->digitsOnly($value);

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return $this->formatElevenDigitLocal($digits);
        }

        return trim((string) ($value ?? ''));
    }

    private function formatElevenDigitLocal(string $digits): string
    {
        return sprintf(
            '%s %s %s %s',
            substr($digits, 0, 4),
            substr($digits, 4, 3),
            substr($digits, 7, 2),
            substr($digits, 9, 2),
        );
    }

    private function digitsOnly(?string $value): string
    {
        return preg_replace('/\D+/', '', strip_tags((string) ($value ?? ''))) ?: '';
    }

    private function hasValidTurkishNationalPrefix(string $digits): bool
    {
        return strlen($digits) === 10 && preg_match('/^[2345]/', $digits) === 1;
    }
}
