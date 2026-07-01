<?php

namespace App\Console\Commands;

use App\Services\System\SystemHeartbeatService;
use Illuminate\Console\Command;

class ProdelyaSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'prodelya:heartbeat-scheduler';

    protected $description = 'Zamanlayıcı için güvenli heartbeat kaydı üretir.';

    public function __construct(
        private readonly SystemHeartbeatService $heartbeatService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->heartbeatService->success('scheduler', [
            'label' => 'Zamanlayıcı',
            'source' => 'scheduler_heartbeat_command',
            'app_env' => config('app.env'),
        ]);

        $this->info('Zamanlayıcı heartbeat sinyali güncellendi.');

        return self::SUCCESS;
    }
}
