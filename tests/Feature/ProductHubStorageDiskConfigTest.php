<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductHubStorageDiskConfigTest extends TestCase
{
    public function test_product_hub_disks_are_registered(): void
    {
        $this->assertIsArray(config('filesystems.disks.pdh_private'));
        $this->assertIsArray(config('filesystems.disks.pdh_public'));
        $this->assertIsArray(config('filesystems.disks.pdh_temp'));
        $this->assertIsArray(config('filesystems.disks.product_images'));
        $this->assertIsArray(config('filesystems.disks.exports'));
    }

    public function test_product_hub_disks_default_to_local_driver(): void
    {
        $this->assertSame('local', config('filesystems.disks.pdh_private.driver'));
        $this->assertSame('local', config('filesystems.disks.pdh_public.driver'));
        $this->assertSame('local', config('filesystems.disks.pdh_temp.driver'));
        $this->assertSame('local', config('filesystems.disks.product_images.driver'));
        $this->assertSame('local', config('filesystems.disks.exports.driver'));
    }
}
