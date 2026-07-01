<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSignupRequest;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SuperAdminOperationAuditService
{
    public function logTenantUpdated(TenantAccount $tenant, User $actor, array $before, array $after): void
    {
        $domainBefore = $this->only($before, [
            'panel_subdomain',
            'custom_domain',
            'portal_domain',
            'domain_panel_status',
            'domain_custom_status',
            'domain_custom_ssl_status',
            'domain_portal_status',
            'domain_portal_ssl_status',
            'domain_operations_note',
        ]);
        $domainAfter = $this->only($after, [
            'panel_subdomain',
            'custom_domain',
            'portal_domain',
            'domain_panel_status',
            'domain_custom_status',
            'domain_custom_ssl_status',
            'domain_portal_status',
            'domain_portal_ssl_status',
            'domain_operations_note',
        ]);

        if ($domainBefore !== $domainAfter) {
            AuditLog::log([
                'tenant_account_id' => $tenant->id,
                'user_id' => $actor->id,
                'action' => 'tenant_domain_updated',
                'entity_type' => 'tenant_account',
                'entity_id' => $tenant->id,
                'old_values' => $domainBefore,
                'new_values' => $domainAfter,
                'notes' => 'Panel alt alanı, özel domain veya portal domain bilgileri güncellendi.',
            ]);
        }

        $subscriptionBefore = $this->only($before, [
            'status',
            'package_key',
            'subscription_trial_starts_at',
            'subscription_trial_ends_at',
            'subscription_package_starts_at',
            'subscription_package_ends_at',
            'subscription_status_note',
            'subscription_suspended_reason',
        ]);
        $subscriptionAfter = $this->only($after, [
            'status',
            'package_key',
            'subscription_trial_starts_at',
            'subscription_trial_ends_at',
            'subscription_package_starts_at',
            'subscription_package_ends_at',
            'subscription_status_note',
            'subscription_suspended_reason',
        ]);

        if ($subscriptionBefore !== $subscriptionAfter) {
            AuditLog::log([
                'tenant_account_id' => $tenant->id,
                'user_id' => $actor->id,
                'action' => 'tenant_subscription_updated',
                'entity_type' => 'tenant_account',
                'entity_id' => $tenant->id,
                'old_values' => $subscriptionBefore,
                'new_values' => $subscriptionAfter,
                'notes' => 'Lifecycle, paket veya abonelik notu güncellendi.',
            ]);
        }
    }

    public function logSignupRequestStatusChanged(TenantSignupRequest $request, User $actor, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_status_updated',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => ['status' => $from],
            'new_values' => ['status' => $to],
            'notes' => 'Başvuru durumu ' . ($this->signupStatusLabel($from)) . ' → ' . ($this->signupStatusLabel($to)) . ' olarak güncellendi.',
        ]);
    }

    public function logSignupRequestConverted(TenantSignupRequest $request, TenantAccount $tenant, User $actor): void
    {
        AuditLog::log([
            'tenant_account_id' => $tenant->id,
            'user_id' => $actor->id,
            'action' => 'signup_request_conversion_completed',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => ['status' => $request->getOriginal('status') ?: TenantSignupRequest::STATUS_NEW],
            'new_values' => [
                'status' => TenantSignupRequest::STATUS_CONVERTED,
                'converted_tenant_account_id' => $tenant->id,
            ],
            'notes' => 'Başvuru güvenli prefill tenant create akışıyla Abone Firma’ya dönüştürüldü.',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logSignupRequestConversionPrefillOpened(TenantSignupRequest $request, User $actor, array $context = []): void
    {
        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_conversion_prefill_opened',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => [],
            'new_values' => $context,
            'notes' => 'Başvuru tenant create prefill akışı için açıldı.',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logSignupRequestConversionPreviewOpened(TenantSignupRequest $request, User $actor, array $context = []): void
    {
        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_conversion_preview_opened',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => [],
            'new_values' => $context,
            'notes' => 'Başvuru dönüşüm önizleme ekranı açıldı.',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logSignupRequestConversionSuccessViewed(TenantSignupRequest $request, User $actor, array $context = []): void
    {
        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_conversion_success_viewed',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => [],
            'new_values' => $context,
            'notes' => 'Dönüşüm başarı özeti görüntülendi.',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $reasons
     */
    public function logSignupRequestConversionBlocked(TenantSignupRequest $request, User $actor, array $context = [], array $reasons = []): void
    {
        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_conversion_blocked',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => [],
            'new_values' => array_merge($context, ['reasons' => array_values(array_unique($reasons))]),
            'notes' => 'Başvuru dönüşümü guard kuralları nedeniyle başlatılamadı.',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $reasons
     */
    public function logSignupRequestConversionReplayBlocked(TenantSignupRequest $request, User $actor, array $context = [], array $reasons = []): void
    {
        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_conversion_replay_blocked',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => [],
            'new_values' => array_merge($context, ['reasons' => array_values(array_unique($reasons))]),
            'notes' => 'Başvuru dönüşümü replay guard tarafından durduruldu.',
        ]);
    }

    public function logSignupRequestNoteAdded(TenantSignupRequest $request, User $actor, string $note): void
    {
        $safePreview = trim(strip_tags($note));

        AuditLog::log([
            'tenant_account_id' => $request->converted_tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'signup_request_note_added',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $request->id,
            'old_values' => [],
            'new_values' => ['note_preview' => mb_substr($safePreview, 0, 180)],
            'notes' => 'Operasyon notu eklendi.',
        ]);
    }

    public function logPackageRequestStatusChanged(TenantPackageUpgradeRequest $request, User $actor, string $from, string $to, ?string $adminNote = null): void
    {
        if ($from === $to && blank($adminNote)) {
            return;
        }

        AuditLog::log([
            'tenant_account_id' => $request->tenant_account_id,
            'user_id' => $actor->id,
            'action' => 'package_request_status_updated',
            'entity_type' => 'tenant_package_upgrade_request',
            'entity_id' => $request->id,
            'old_values' => ['status' => $from, 'admin_note' => $request->getOriginal('admin_note')],
            'new_values' => ['status' => $to, 'admin_note' => $adminNote],
            'notes' => 'Paket talebi durumu ' . ($this->packageStatusLabel($from)) . ' → ' . ($this->packageStatusLabel($to)) . ' olarak güncellendi.',
        ]);
    }

    public function logPackageRequestApplied(TenantPackageUpgradeRequest $request, TenantAccount $tenant, Package $package, User $actor): void
    {
        AuditLog::log([
            'tenant_account_id' => $tenant->id,
            'user_id' => $actor->id,
            'action' => 'package_request_applied',
            'entity_type' => 'tenant_package_upgrade_request',
            'entity_id' => $request->id,
            'old_values' => ['package_key' => $request->current_package_key],
            'new_values' => ['package_key' => $package->key],
            'notes' => 'Onaylanan paket talebi tenant üzerine uygulandı.',
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function tenantTimeline(TenantAccount $tenant): array
    {
        $logs = AuditLog::query()
            ->with('user')
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('action', [
                'tenant_domain_updated',
                'tenant_subscription_updated',
                'signup_request_converted',
                'signup_request_conversion_completed',
                'package_request_applied',
            ])
            ->latest()
            ->limit(12)
            ->get();

        if ($logs->isEmpty()) {
            return [[
                'title' => 'Abone Firma oluşturuldu',
                'description' => 'Tenant kaydı oluşturuldu. İlk lifecycle ve domain takibi bu ekranda tutulur.',
                'tone' => 'blue',
                'at' => optional($tenant->created_at)->format('d.m.Y H:i') ?: '-',
            ]];
        }

        return $logs->map(fn (AuditLog $log) => [
            'title' => $this->tenantActionTitle($log->action),
            'description' => $this->buildTenantDescription($log),
            'tone' => $this->actionTone($log->action),
            'at' => optional($log->created_at)->format('d.m.Y H:i') ?: '-',
        ])->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function signupRequestTimeline(TenantSignupRequest $request): array
    {
        $logs = AuditLog::query()
            ->with('user')
            ->where('entity_type', 'tenant_signup_request')
            ->where('entity_id', $request->id)
            ->latest()
            ->get();

        $items = collect([[
            'title' => 'Başvuru alındı',
            'description' => ($request->source ?: 'public_landing') . ' kaynağından geldi.',
            'tone' => 'blue',
            'at' => optional($request->created_at)->format('d.m.Y H:i') ?: '-',
        ]]);

        foreach ($logs->sortBy('created_at') as $log) {
            $items->push([
                'title' => $this->signupActionTitle($log->action),
                'description' => $this->buildSignupDescription($log, $request),
                'tone' => $this->actionTone($log->action),
                'at' => optional($log->created_at)->format('d.m.Y H:i') ?: '-',
            ]);
        }

        return $items->reverse()->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function packageRequestTimeline(TenantPackageUpgradeRequest $request): array
    {
        $logs = AuditLog::query()
            ->with('user')
            ->where('entity_type', 'tenant_package_upgrade_request')
            ->where('entity_id', $request->id)
            ->latest()
            ->get();

        $items = collect([[
            'title' => 'Talep oluşturuldu',
            'description' => optional($request->created_at)->format('d.m.Y H:i') ?: 'Tarih yok',
            'tone' => 'blue',
            'at' => optional($request->created_at)->format('d.m.Y H:i') ?: '-',
        ]]);

        foreach ($logs->sortBy('created_at') as $log) {
            $items->push([
                'title' => $this->packageActionTitle($log->action),
                'description' => $this->buildPackageDescription($log, $request),
                'tone' => $this->actionTone($log->action),
                'at' => optional($log->created_at)->format('d.m.Y H:i') ?: '-',
            ]);
        }

        return $items->reverse()->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function tenantUpgradeRequestTimeline(TenantUpgradeRequest $request): array
    {
        $logs = AuditLog::query()
            ->with('user')
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('entity_id', $request->id)
            ->latest()
            ->get();

        $items = collect([[
            'title' => 'Talep oluşturuldu',
            'description' => ($request->requestTypeLabel()) . ' kaydı açıldı.',
            'tone' => 'blue',
            'at' => optional($request->created_at)->format('d.m.Y H:i') ?: '-',
        ]]);

        foreach ($logs->sortBy('created_at') as $log) {
            $items->push([
                'title' => $this->tenantUpgradeRequestActionTitle($log->action),
                'description' => $this->buildTenantUpgradeRequestDescription($log, $request),
                'tone' => $this->actionTone($log->action),
                'at' => optional($log->created_at)->format('d.m.Y H:i') ?: '-',
            ]);
        }

        return $items->reverse()->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentOperations(int $limit = 12): array
    {
        $logs = AuditLog::query()
            ->with(['user', 'tenant'])
            ->whereIn('action', [
                'tenant_domain_updated',
                'tenant_subscription_updated',
                'signup_request_status_updated',
                'signup_request_conversion_prefill_opened',
                'signup_request_conversion_preview_opened',
                'signup_request_conversion_success_viewed',
                'signup_request_conversion_blocked',
                'signup_request_conversion_replay_blocked',
                'signup_request_conversion_completed',
                'signup_request_converted',
                'signup_request_note_added',
                'package_request_status_updated',
                'package_request_applied',
                'tenant_upgrade_request_created',
                'tenant_upgrade_request_in_review',
                'tenant_upgrade_request_approved',
                'tenant_upgrade_request_rejected',
                'tenant_upgrade_request_cancelled',
                'tenant_upgrade_request_applied',
                'tenant_upgrade_request_apply_blocked',
                'tenant_upgrade_request_apply_failed',
                'module_enabled',
                'module_disabled',
            ])
            ->latest()
            ->limit($limit)
            ->get();

        return $logs->map(function (AuditLog $log): array {
            return [
                'event_title' => $this->recentOperationTitle($log),
                'tenant' => $log->tenant?->name,
                'subject' => $this->recentOperationSubject($log),
                'actor' => $log->user?->name ?: 'Sistem',
                'occurred_at' => optional($log->created_at)->format('d.m.Y H:i') ?: '-',
                'tone' => $this->actionTone($log->action),
                'summary' => $this->sanitizeAuditText($this->recentOperationSummary($log)),
                'route' => $this->recentOperationRoute($log),
            ];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $values, array $keys): array
    {
        $filtered = [];

        foreach ($keys as $key) {
            $filtered[$key] = $values[$key] ?? null;
        }

        return $filtered;
    }

    private function tenantActionTitle(string $action): string
    {
        return match ($action) {
            'tenant_domain_updated' => 'Domain bilgisi güncellendi',
            'tenant_subscription_updated' => 'Abonelik durumu güncellendi',
            'signup_request_conversion_completed',
            'signup_request_converted' => 'Başvurudan tenant dönüşümü tamamlandı',
            'package_request_applied' => 'Paket değişikliği uygulandı',
            default => 'Operasyon kaydı',
        };
    }

    private function signupActionTitle(string $action): string
    {
        return match ($action) {
            'signup_request_status_updated' => 'Başvuru durumu güncellendi',
            'signup_request_conversion_prefill_opened' => 'Dönüşüm prefill ekranı açıldı',
            'signup_request_conversion_preview_opened' => 'Dönüşüm önizleme ekranı açıldı',
            'signup_request_conversion_success_viewed' => 'Dönüşüm başarı özeti açıldı',
            'signup_request_conversion_blocked' => 'Dönüşüm güvenlik kontrolüne takıldı',
            'signup_request_conversion_replay_blocked' => 'Dönüşüm replay guard tarafından durduruldu',
            'signup_request_conversion_completed',
            'signup_request_converted' => 'Başvuru Abone Firma’ya dönüştürüldü',
            'signup_request_note_added' => 'Operasyon notu eklendi',
            default => 'Başvuru kaydı işlendi',
        };
    }

    private function packageActionTitle(string $action): string
    {
        return match ($action) {
            'package_request_status_updated' => 'Paket talebi durumu güncellendi',
            'package_request_applied' => 'Paket tenant üzerine uygulandı',
            default => 'Paket talebi işlendi',
        };
    }

    private function tenantUpgradeRequestActionTitle(string $action): string
    {
        return match ($action) {
            'tenant_upgrade_request_created' => 'Talep oluşturuldu',
            'tenant_upgrade_request_in_review' => 'Talep incelemeye alındı',
            'tenant_upgrade_request_approved' => 'Talep onaylandı',
            'tenant_upgrade_request_rejected' => 'Talep reddedildi',
            'tenant_upgrade_request_cancelled' => 'Talep iptal edildi',
            'tenant_upgrade_request_applied' => 'Talep uygulandı',
            'tenant_upgrade_request_apply_blocked' => 'Talep uygulama kontrolüne takıldı',
            'tenant_upgrade_request_apply_failed' => 'Talep uygulanırken hata oluştu',
            default => 'Talep işlendi',
        };
    }

    private function buildTenantDescription(AuditLog $log): string
    {
        $actor = $log->user?->name ?: 'Super Admin';

        return trim($log->notes . ' · ' . $actor);
    }

    private function buildSignupDescription(AuditLog $log, TenantSignupRequest $request): string
    {
        if (in_array($log->action, ['signup_request_converted', 'signup_request_conversion_completed'], true) && $request->convertedTenant) {
            return $request->convertedTenant->name . ' kaydıyla eşleştirildi.';
        }

        if ($log->action === 'signup_request_note_added') {
            return trim(($log->new_values['note_preview'] ?? 'Operasyon notu eklendi.') . ' · ' . ($log->user?->name ?: 'Super Admin'));
        }

        if ($log->action === 'signup_request_conversion_preview_opened') {
            return trim(($log->notes ?: 'Dönüşüm önizleme ekranı açıldı.') . ' · ' . ($log->user?->name ?: 'Super Admin'));
        }

        if ($log->action === 'signup_request_conversion_success_viewed') {
            return trim(($log->notes ?: 'Dönüşüm başarı özeti görüntülendi.') . ' · ' . ($log->user?->name ?: 'Super Admin'));
        }

        if (in_array($log->action, ['signup_request_conversion_blocked', 'signup_request_conversion_replay_blocked'], true)) {
            $reasons = collect($log->new_values['reasons'] ?? [])
                ->filter()
                ->implode(' · ');

            return trim(($reasons !== '' ? $reasons : ($log->notes ?: 'Dönüşüm engellendi.')) . ' · ' . ($log->user?->name ?: 'Super Admin'));
        }

        return trim(($log->notes ?: 'Başvuru kaydı işlendi.') . ' · ' . ($log->user?->name ?: 'Super Admin'));
    }

    private function buildPackageDescription(AuditLog $log, TenantPackageUpgradeRequest $request): string
    {
        if ($log->action === 'package_request_applied') {
            return ($request->tenant?->name ?: 'Tenant') . ' için istenen paket uygulandı.';
        }

        return trim(($log->notes ?: 'Paket talebi işlendi.') . ' · ' . ($log->user?->name ?: 'Super Admin'));
    }

    private function buildTenantUpgradeRequestDescription(AuditLog $log, TenantUpgradeRequest $request): string
    {
        $preview = $log->new_values['admin_note_preview']
            ?? $log->new_values['requested_note_preview']
            ?? null;

        $parts = [];

        if ($log->action === 'tenant_upgrade_request_applied' && isset($log->new_values['apply_summary']) && is_array($log->new_values['apply_summary'])) {
            $parts[] = $this->summarizeTenantUpgradeApply($log->new_values['apply_summary']);
        } elseif ($log->action === 'tenant_upgrade_request_apply_blocked') {
            $reasons = collect($log->new_values['reasons'] ?? [])->filter()->implode(' · ');
            $parts[] = $reasons !== '' ? $reasons : ($log->notes ?: 'Uygulama engellendi.');
        } elseif ($log->action === 'tenant_upgrade_request_apply_failed') {
            $parts[] = (string) ($log->new_values['message'] ?? $log->notes ?? 'Uygulama sırasında hata oluştu.');
        } elseif ($preview) {
            $parts[] = (string) $preview;
        } elseif ($log->notes) {
            $parts[] = (string) $log->notes;
        } else {
            $parts[] = $request->requestTypeLabel() . ' işlendi.';
        }

        $parts[] = $log->user?->name ?: 'Super Admin';

        return implode(' · ', array_filter($parts));
    }

    private function recentOperationTitle(AuditLog $log): string
    {
        return match ($log->entity_type) {
            'tenant_signup_request' => $this->signupActionTitle($log->action),
            'tenant_package_upgrade_request' => $this->packageActionTitle($log->action),
            'tenant_upgrade_request' => $this->tenantUpgradeRequestActionTitle($log->action),
            'tenant_account' => $this->tenantActionTitle($log->action),
            default => $log->action_description,
        };
    }

    private function recentOperationSubject(AuditLog $log): ?string
    {
        return match ($log->entity_type) {
            'tenant_signup_request' => 'Başvuru',
            'tenant_package_upgrade_request' => 'Paket talebi',
            'tenant_upgrade_request' => 'Abone Firma talebi',
            'tenant_account' => 'Abone Firma',
            default => null,
        };
    }

    private function recentOperationSummary(AuditLog $log): string
    {
        return match ($log->entity_type) {
            'tenant_signup_request' => $this->buildGenericSignupDescription($log),
            'tenant_package_upgrade_request' => trim((string) ($log->notes ?: 'Paket talebi işlendi.')),
            'tenant_upgrade_request' => trim((string) (
                $this->buildTenantUpgradeRequestDescription(
                    $log,
                    new TenantUpgradeRequest([
                        'request_type' => data_get($log->new_values, 'request_type'),
                    ])
                ) ?: 'Talep işlendi.'
            )),
            'tenant_account' => trim((string) ($log->notes ?: 'Abone Firma kaydı güncellendi.')),
            default => trim((string) ($log->notes ?: $log->action_description)),
        };
    }

    private function recentOperationRoute(AuditLog $log): ?string
    {
        try {
            return match ($log->entity_type) {
                'tenant_signup_request' => route('admin.super.signup-requests.show', ['signupRequest' => $log->entity_id]),
                'tenant_package_upgrade_request' => route('admin.super.package-requests.show', ['tenantPackageUpgradeRequest' => $log->entity_id]),
                'tenant_upgrade_request' => route('admin.super.upgrade-requests.show', ['tenantUpgradeRequest' => $log->entity_id]),
                'tenant_account' => $log->tenant_account_id ? route('admin.super.tenants.show', ['tenant' => $log->tenant_account_id]) : null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildGenericSignupDescription(AuditLog $log): string
    {
        if ($log->action === 'signup_request_note_added') {
            return trim((string) ($log->new_values['note_preview'] ?? $log->notes ?? 'Operasyon notu eklendi.'));
        }

        if (in_array($log->action, ['signup_request_conversion_blocked', 'signup_request_conversion_replay_blocked'], true)) {
            $reasons = collect($log->new_values['reasons'] ?? [])->filter()->implode(' · ');

            return trim($reasons !== '' ? $reasons : (string) ($log->notes ?: 'Dönüşüm engellendi.'));
        }

        return trim((string) ($log->notes ?: 'Başvuru işlendi.'));
    }

    private function sanitizeAuditText(?string $value): string
    {
        $text = trim(strip_tags((string) $value));

        if ($text === '') {
            return 'Detay bulunmuyor.';
        }

        foreach (['password', 'token', 'secret', 'smtp_password', 'api key', 'api_key', '<script'] as $needle) {
            if (Str::contains(Str::lower($text), $needle)) {
                return 'Hassas detay gizlendi.';
            }
        }

        return Str::limit($text, 220);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summarizeTenantUpgradeApply(array $summary): string
    {
        return match ($summary['request_type'] ?? null) {
            TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE => (($summary['old_package_label'] ?? '-') . ' → ' . ($summary['new_package_label'] ?? '-')),
            TenantUpgradeRequest::TYPE_MODULE_ADDON => (($summary['module_label'] ?? '-') . ' modülü açıldı.'),
            TenantUpgradeRequest::TYPE_FEATURE_ADDON => (($summary['feature_label'] ?? '-') . ' özelliği açıldı.'),
            TenantUpgradeRequest::TYPE_LIMIT_INCREASE => (($summary['limit_label'] ?? '-') . ' limiti ' . ($summary['old_limit'] ?? '-') . ' → ' . ($summary['new_limit'] ?? '-')),
            TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS => (($summary['supplier_name'] ?? '-') . ' tedarikçi erişimi açıldı.'),
            TenantUpgradeRequest::TYPE_SERVICE_REQUEST => (string) ($summary['service_result'] ?? 'Manuel hizmet tamamlandı.'),
            default => 'Talep uygulandı.',
        };
    }

    private function actionTone(string $action): string
    {
        return match ($action) {
            'signup_request_converted', 'signup_request_conversion_completed', 'package_request_applied' => 'green',
            'tenant_subscription_updated', 'package_request_status_updated', 'signup_request_status_updated' => 'amber',
            'signup_request_conversion_preview_opened', 'signup_request_conversion_success_viewed' => 'blue',
            'signup_request_conversion_blocked', 'signup_request_conversion_replay_blocked' => 'red',
            'tenant_upgrade_request_approved' => 'green',
            'tenant_upgrade_request_rejected', 'tenant_upgrade_request_cancelled' => 'gray',
            'tenant_upgrade_request_in_review' => 'amber',
            'tenant_upgrade_request_applied' => 'green',
            'tenant_upgrade_request_apply_blocked', 'tenant_upgrade_request_apply_failed' => 'red',
            default => 'blue',
        };
    }

    private function signupStatusLabel(string $status): string
    {
        return TenantSignupRequest::statusOptions()[$status] ?? $status;
    }

    private function packageStatusLabel(string $status): string
    {
        return TenantPackageUpgradeRequest::statusOptions()[$status] ?? $status;
    }
}
