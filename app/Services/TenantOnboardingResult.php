<?php

namespace App\Services;

class TenantOnboardingResult
{
    /**
     * @param  array<string, array{label:string,prepared:int,skipped:int}>  $sections
     */
    public function __construct(
        public readonly int $tenantId,
        private array $sections = [],
    ) {
    }

    public function addSection(string $key, string $label, int $prepared, int $skipped): void
    {
        $this->sections[$key] = [
            'label' => $label,
            'prepared' => max(0, $prepared),
            'skipped' => max(0, $skipped),
        ];
    }

    /**
     * @return array<string, array{label:string,prepared:int,skipped:int}>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    public function preparedCount(): int
    {
        return array_sum(array_map(
            fn (array $section): int => $section['prepared'],
            $this->sections
        ));
    }

    public function skippedCount(): int
    {
        return array_sum(array_map(
            fn (array $section): int => $section['skipped'],
            $this->sections
        ));
    }

    public function summary(): string
    {
        $parts = [];

        foreach ($this->sections as $section) {
            $parts[] = sprintf(
                '%s: %d hazırlandı, %d zaten vardı',
                $section['label'],
                $section['prepared'],
                $section['skipped']
            );
        }

        return implode(' | ', $parts);
    }
}
