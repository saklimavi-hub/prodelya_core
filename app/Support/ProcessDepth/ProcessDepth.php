<?php

namespace App\Support\ProcessDepth;

final class ProcessDepth
{
    public const FAST = 'fast';
    public const STANDARD = 'standard';
    public const CONTROLLED = 'controlled';

    public static function values(): array
    {
        return [
            self::FAST,
            self::STANDARD,
            self::CONTROLLED,
        ];
    }

    public static function default(): string
    {
        return (string) config('process_depth.default', self::STANDARD);
    }

    public static function isValid(?string $value): bool
    {
        return is_string($value) && in_array(trim($value), self::values(), true);
    }

    public static function normalize(?string $value): string
    {
        $candidate = is_string($value) ? trim($value) : '';

        return self::isValid($candidate) ? $candidate : self::default();
    }

    public static function label(string $value): string
    {
        $normalized = self::normalize($value);

        return (string) trans("process_depth.depths.{$normalized}", [], 'tr');
    }

    public static function sourceLabel(string $source): string
    {
        return (string) trans("process_depth.sources.{$source}", [], 'tr');
    }
}
