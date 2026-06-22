<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GraphicWorkflowService
{
    private const ALLOWED_STATUSES = [
        'hazirlaniyor',
        'musteri_onayi_bekliyor',
        'revize_istendi',
        'onaylandi',
        'uretime_hazir',
    ];

    public function updateGraphicStatus(OrderItemWorkForm $workForm, string $status, ?User $user = null): OrderItemWorkForm
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported graphic status.');
        }

        return DB::transaction(function () use ($workForm, $status, $user): OrderItemWorkForm {
            $graphicSnapshot = is_array($workForm->graphic_snapshot) ? $workForm->graphic_snapshot : [];
            $oldStatus = (string) ($graphicSnapshot['status'] ?? '');

            $graphicSnapshot['status'] = $status;
            $graphicSnapshot['updated_at'] = now()->toAtomString();

            if ($status === 'musteri_onayi_bekliyor') {
                $graphicSnapshot['approval_status'] = 'onay_bekliyor';
            } elseif ($status === 'revize_istendi') {
                $graphicSnapshot['approval_status'] = 'revize_istendi';
            } elseif (in_array($status, ['onaylandi', 'uretime_hazir'], true)) {
                $graphicSnapshot['approval_status'] = 'onaylandi';
            }

            $workForm->forceFill([
                'graphic_snapshot' => $graphicSnapshot,
                'version' => (int) $workForm->version + 1,
                'updated_by' => $user?->id,
            ])->save();

            OrderItemWorkFormActivityLog::query()->create([
                'tenant_account_id' => $workForm->tenant_account_id,
                'work_form_id' => $workForm->id,
                'order_id' => $workForm->order_id,
                'order_item_id' => $workForm->order_item_id,
                'action_type' => 'status_updated',
                'old_status' => $oldStatus !== '' ? $oldStatus : null,
                'new_status' => $status,
                'note' => $this->defaultNote($status),
                'visibility' => 'internal',
                'created_by' => $user?->id,
            ]);

            return $workForm->fresh(['attachments', 'activityLogs.attachment']);
        });
    }

    private function defaultNote(string $status): string
    {
        return match ($status) {
            'hazirlaniyor' => 'Grafik durumu hazırlanıyor olarak güncellendi.',
            'musteri_onayi_bekliyor' => 'Grafik müşteri onayı bekliyor olarak güncellendi.',
            'revize_istendi' => 'Grafik için revize istendi olarak güncellendi.',
            'onaylandi' => 'Grafik onaylandı olarak güncellendi.',
            'uretime_hazir' => 'Grafik üretime hazır olarak işaretlendi.',
            default => 'Grafik durumu güncellendi.',
        };
    }
}
