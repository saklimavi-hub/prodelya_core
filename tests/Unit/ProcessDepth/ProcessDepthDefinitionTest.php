<?php

namespace Tests\Unit\ProcessDepth;

use App\Support\ProcessDepth\ProcessDepth;
use Tests\TestCase;

class ProcessDepthDefinitionTest extends TestCase
{
    public function test_canonical_values_are_exact(): void
    {
        $this->assertSame(['fast', 'standard', 'controlled'], ProcessDepth::values());
    }

    public function test_null_and_unknown_values_normalize_to_standard(): void
    {
        $this->assertSame('standard', ProcessDepth::normalize(null));
        $this->assertSame('standard', ProcessDepth::normalize('unknown'));
    }

    public function test_labels_are_valid_turkish_utf8_strings(): void
    {
        $this->assertSame('Hızlı Akış', ProcessDepth::label(ProcessDepth::FAST));
        $this->assertSame('Standart Akış', ProcessDepth::label(ProcessDepth::STANDARD));
        $this->assertSame('Kontrollü Akış', ProcessDepth::label(ProcessDepth::CONTROLLED));
        $this->assertStringNotContainsString('Hizli', ProcessDepth::label(ProcessDepth::FAST));
        $this->assertStringNotContainsString('Kontrollu', ProcessDepth::label(ProcessDepth::CONTROLLED));
    }
}
