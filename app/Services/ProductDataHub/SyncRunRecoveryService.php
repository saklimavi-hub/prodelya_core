<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SyncRunRecoveryService
{
    public function __construct(
        private readonly SensitiveDataMasker $masker,
    ) {
    }

    public function recoverStuckRuns(int $minutes = 60, bool $dryRun = true): array
    {
        $thresholdMinutes = max(1, min(7 * 24 * 60, $minutes));
        $threshold = now()->subMinutes($thresholdMinutes);

        $runs = ProductDataHubSyncRun::query()
            ->with(['source.supplier'])
            ->where('status', ProductDataHubSyncRun::STATUS_RUNNING)
            ->where(function ($query) use ($threshold) {
                $query->where('updated_at', '<=', $threshold)
                    ->orWhere(function ($nested) use ($threshold) {
                        $nested->whereNull('updated_at')
                            ->where('started_at', '<=', $threshold);
                    });
            })
            ->orderBy('started_at')
            ->get();

        $entries = $runs->map(fn (ProductDataHubSyncRun $run) => $this->buildEntry($run, $thresholdMinutes));

        if (!$dryRun) {
            $runs->each(fn (ProductDataHubSyncRun $run) => $this->markAsStuck($run, $thresholdMinutes));
            $entries = $runs->fresh(['source.supplier'])->map(fn (ProductDataHubSyncRun $run) => $this->buildEntry($run, $thresholdMinutes));
        }

        return [
            'dry_run' => $dryRun,
            'minutes' => $thresholdMinutes,
            'count' => $entries->count(),
            'entries' => $entries->values()->all(),
        ];
    }

    private function buildEntry(ProductDataHubSyncRun $run, int $thresholdMinutes): array
    {
        $referenceAt = $run->updated_at instanceof CarbonInterface
            ? $run->updated_at
            : ($run->started_at instanceof CarbonInterface ? $run->started_at : null);

        $runningMinutes = $referenceAt instanceof CarbonInterface
            ? max(0, $referenceAt->diffInMinutes(now()))
            : null;

        return [
            'run_id' => $run->id,
            'source_id' => $run->supplier_source_id,
            'source_name' => $run->source?->source_name ?? 'Bilinmeyen Kaynak',
            'supplier_name' => $run->source?->supplier?->name ?? $run->supplier?->name ?? 'Bilinmeyen Tedarikçi',
            'status' => $run->normalizedStatus(),
            'started_at' => optional($run->started_at)?->toDateTimeString(),
            'updated_at' => optional($run->updated_at)?->toDateTimeString(),
            'running_minutes' => $runningMinutes,
            'threshold_minutes' => $thresholdMinutes,
            'recommended_action' => 'stuck olarak işaretle',
            'message' => $this->sanitizeMessage($run->error_message),
        ];
    }

    private function markAsStuck(ProductDataHubSyncRun $run, int $thresholdMinutes): void
    {
        $payload = (array) ($run->report_payload ?? []);
        $payload['recovery'] = array_merge((array) data_get($payload, 'recovery', []), [
            'applied' => true,
            'previous_status' => $run->status,
            'normalized_previous_status' => $run->normalizedStatus(),
            'marked_status' => ProductDataHubSyncRun::STATUS_STUCK,
            'threshold_minutes' => $thresholdMinutes,
            'recovered_at' => now()->toIso8601String(),
            'note' => 'Running kalan sync run recovery standardı ile stuck olarak işaretlendi.',
        ]);

        $existingMessage = trim($this->sanitizeMessage($run->error_message));
        $recoveryMessage = 'Recovery standardı uygulandı: running kalan işlem stuck olarak işaretlendi.';

        $run->forceFill([
            'status' => ProductDataHubSyncRun::STATUS_STUCK,
            'finished_at' => $run->finished_at ?? now(),
            'error_message' => $existingMessage !== '' ? ($existingMessage . ' | ' . $recoveryMessage) : $recoveryMessage,
            'report_payload' => $payload,
        ])->save();
    }

    private function sanitizeMessage(?string $message): ?string
    {
        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        return $this->masker->maskExceptionMessage($this->masker->maskUrl($message));
    }
}
