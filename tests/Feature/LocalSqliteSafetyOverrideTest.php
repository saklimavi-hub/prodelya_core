<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class LocalSqliteSafetyOverrideTest extends TestCase
{
    public function test_local_sqlite_database_session_is_forced_to_file_driver(): void
    {
        config([
            'app.env' => 'local',
            'app.name' => 'Prodelya',
            'database.default' => 'sqlite',
            'session.driver' => 'database',
            'cache.default' => 'database',
            'session.domain' => null,
            'session.cookie' => 'prodelya-session',
            'prodelya_domains.local_hosts' => ['localhost', '127.0.0.1', 'prodelya_core.test'],
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
        $this->assertSame('.prodelya_core.test', config('session.domain'));
        $this->assertSame('prodelya-local-session', config('session.cookie'));
        $this->assertTrue((bool) config('prodelya_local.sqlite_lock_protection.active'));
    }

    public function test_production_config_is_not_overridden_by_local_sqlite_safety_guard(): void
    {
        config([
            'app.env' => 'production',
            'database.default' => 'sqlite',
            'session.driver' => 'database',
            'cache.default' => 'database',
            'session.domain' => '.saklimavi.net',
            'session.cookie' => 'prodelya-session',
            'prodelya_domains.local_hosts' => ['localhost', '127.0.0.1', 'prodelya_core.test'],
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertSame('database', config('session.driver'));
        $this->assertSame('database', config('cache.default'));
        $this->assertSame('.saklimavi.net', config('session.domain'));
        $this->assertSame('prodelya-session', config('session.cookie'));
        $this->assertFalse((bool) config('prodelya_local.sqlite_lock_protection.active'));
    }
}
