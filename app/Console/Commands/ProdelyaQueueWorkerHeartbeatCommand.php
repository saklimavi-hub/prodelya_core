<?php

namespace App\Console\Commands;

use App\Services\System\SystemHeartbeatService;
use Illuminate\Console\Command;

class ProdelyaQueueWorkerHeartbeatCommand extends Command
{
    protected $signature = 'prodelya:heartbeat-queue-worker';

    protected $description = 'Kuyruk çalışanı için güvenli heartbeat kaydı üretir.';

    public function __construct(
        private readonly SystemHeartbeatService $heartbeatService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->heartbeatService->success('queue_worker', [
            'label' => 'Kuyruk Çalışanı',
            'source' => 'queue_worker_heartbeat_command',
            'queue_connection' => config('queue.default'),
        ]);

        $this->info('Kuyruk çalışanı heartbeat sinyali güncellendi.');

        return self::SUCCESS;
    }
}
