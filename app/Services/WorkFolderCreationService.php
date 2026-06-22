<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemWorkFolder;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkFolderCreationService
{
    private const SYSTEM_DISK = 'local';
    private const SYSTEM_ROOT_KEY = 'system_local';

    public function __construct(
        protected WorkFolderPathService $pathService
    ) {
    }

    public function createForOrder(Order $order, ?User $user = null): Collection
    {
        $order->loadMissing(['workForms']);

        $folders = new Collection();

        foreach ($order->workForms as $workForm) {
            $folders->push($this->createSystemFolderForWorkForm($workForm, $user));
        }

        return $folders;
    }

    public function createSystemFolderForWorkForm(OrderItemWorkForm $workForm, ?User $user = null): OrderItemWorkFolder
    {
        $existing = OrderItemWorkFolder::query()
            ->where('tenant_account_id', $workForm->tenant_account_id)
            ->where('work_form_id', $workForm->id)
            ->where('folder_type', 'system')
            ->first();

        if ($existing) {
            $this->ensureDirectoryStructureExists([
                'relative_path' => $existing->relative_path,
                'subdirectories' => $this->pathService::SUBDIRECTORIES,
            ]);

            return $existing;
        }

        $pathData = $this->pathService->buildForWorkForm($workForm);

        $folder = OrderItemWorkFolder::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'work_form_id' => $workForm->id,
            'folder_type' => 'system',
            'storage_driver' => self::SYSTEM_DISK,
            'root_key' => self::SYSTEM_ROOT_KEY,
            'relative_path' => $pathData['relative_path'],
            'display_path' => $pathData['display_path'],
            'physical_path' => null,
            'status' => 'pending',
            'created_by' => $user?->id,
        ]);

        try {
            $this->ensureDirectoryStructureExists($pathData);

            $folder->forceFill([
                'status' => 'created',
                'last_checked_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $folder->forceFill([
                'status' => 'failed',
                'last_checked_at' => now(),
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
            ])->save();
        }

        return $folder->fresh();
    }

    private function ensureDirectoryStructureExists(array $pathData): void
    {
        $disk = Storage::disk(self::SYSTEM_DISK);
        $root = (string) ($pathData['relative_path'] ?? '');

        if ($root === '') {
            return;
        }

        if (!$disk->directoryExists($root)) {
            $disk->makeDirectory($root);
        }

        foreach ((array) ($pathData['subdirectories'] ?? []) as $subdirectory) {
            $subdirectory = trim((string) $subdirectory);

            if ($subdirectory === '') {
                continue;
            }

            $fullPath = $root . '/' . $subdirectory;

            if (!$disk->directoryExists($fullPath)) {
                $disk->makeDirectory($fullPath);
            }
        }
    }
}
