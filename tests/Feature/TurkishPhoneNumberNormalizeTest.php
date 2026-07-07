<?php

namespace Tests\Feature;

use App\Services\PhoneNumberNormalizer;
use Tests\TestCase;

class TurkishPhoneNumberNormalizeTest extends TestCase
{
    public function test_it_normalizes_common_turkish_mobile_formats_for_whatsapp(): void
    {
        $service = app(PhoneNumberNormalizer::class);

        $this->assertSame('+905321234567', $service->normalizeTurkishMobileForWhatsapp('0532 123 45 67'));
        $this->assertSame('+905321234567', $service->normalizeTurkishMobileForWhatsapp('5321234567'));
        $this->assertSame('+905321234567', $service->normalizeTurkishMobileForWhatsapp('+90 532 123 45 67'));
        $this->assertSame('+905321234567', $service->normalizeTurkishMobileForWhatsapp('0090 532 123 45 67'));
        $this->assertSame('+905321234567', $service->normalizeTurkishMobileForWhatsapp('90 532 123 45 67'));
        $this->assertSame('+905321234567', $service->normalizeTurkishMobileForWhatsapp('0 (532) 123 45 67'));
        $this->assertSame('905321234567', $service->toWhatsappDialString('0532 123 45 67'));
        $this->assertSame('0532 123 45 67', $service->formatTurkishPhoneForDisplay('5321234567'));
    }

    public function test_it_returns_null_for_invalid_values_and_does_not_leak_special_characters(): void
    {
        $service = app(PhoneNumberNormalizer::class);

        $this->assertNull($service->normalizeTurkishMobileForWhatsapp('abc'));
        $this->assertNull($service->normalizeTurkishMobileForWhatsapp('0212 123 45 67'));
        $this->assertNull($service->toWhatsappDialString('<script>alert(1)</script>'));
    }
}
