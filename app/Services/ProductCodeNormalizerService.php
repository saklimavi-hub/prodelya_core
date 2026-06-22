<?php

namespace App\Services;

class ProductCodeNormalizerService
{
    public function normalizeCode(string $value): string
    {
        $value = trim($value);

        $map = [
            'Ç' => 'C', 'ç' => 'C',
            'Ğ' => 'G', 'ğ' => 'G',
            'İ' => 'I', 'I' => 'I', 'ı' => 'I',
            'Ö' => 'O', 'ö' => 'O',
            'Ş' => 'S', 'ş' => 'S',
            'Ü' => 'U', 'ü' => 'U',
        ];

        $value = strtr($value, $map);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/\s+/u', '-', $value) ?? '';
        $value = preg_replace('/[^A-Z0-9\-]+/u', '-', $value) ?? '';
        $value = preg_replace('/-+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    public function applySupplierPrefix(string $prefix, string $code): string
    {
        $prefix = $this->normalizeCode($prefix);
        $code = $this->normalizeCode($code);

        if ($prefix === '') {
            return $code;
        }

        if ($code === '') {
            return $prefix;
        }

        return $this->normalizeCode($prefix . '-' . $code);
    }

    public function generateProductCode(array $data, string $template): string
    {
        return $this->buildFromTemplate($data, $template);
    }

    public function generateVariantCode(array $data, string $template): string
    {
        return $this->buildFromTemplate($data, $template);
    }

    private function buildFromTemplate(array $data, string $template): string
    {
        $replacements = [];

        foreach ($data as $key => $value) {
            $token = '{' . strtoupper((string) $key) . '}';
            $replacements[$token] = $this->normalizeCode((string) ($value ?? ''));
        }

        $built = strtr($template, $replacements);

        return $this->normalizeCode($built);
    }
}
