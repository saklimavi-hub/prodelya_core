<?php

namespace Tests\Unit\Currency;

use App\Exceptions\Currency\UnsupportedCurrencyException;
use App\Services\Currency\CurrencyCodeNormalizer;
use PHPUnit\Framework\TestCase;

class CurrencyCodeNormalizerTest extends TestCase
{
    public function test_it_normalizes_supported_aliases(): void
    {
        $normalizer = new CurrencyCodeNormalizer();

        $this->assertSame('TRY', $normalizer->normalize('TL'));
        $this->assertSame('TRY', $normalizer->normalize('try'));
        $this->assertSame('TRY', $normalizer->normalize('₺'));
        $this->assertSame('USD', $normalizer->normalize('USD'));
        $this->assertSame('USD', $normalizer->normalize('$'));
        $this->assertSame('USD', $normalizer->normalize('dolar'));
        $this->assertSame('EUR', $normalizer->normalize('EUR'));
        $this->assertSame('EUR', $normalizer->normalize('€'));
        $this->assertSame('EUR', $normalizer->normalize('euro'));
        $this->assertSame('EUR', $normalizer->normalize('avro'));
    }

    public function test_it_throws_for_unsupported_currency(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        (new CurrencyCodeNormalizer())->normalize('GBP');
    }

    public function test_it_returns_default_for_blank_value(): void
    {
        $this->assertSame('TRY', (new CurrencyCodeNormalizer())->normalizeOrDefault(''));
    }
}
