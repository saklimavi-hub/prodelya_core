<?php

namespace App\Services;

use App\Models\Order;
use App\Services\ProcessDepth\TenantProcessDepthPolicy;
use App\Services\ProcessDepth\TenantProcessDepthResolver;
use App\Support\ProcessDepth\ProcessDepth;

class OrderDetailProcessDepthPresenter
{
    public function __construct(
        protected TenantProcessDepthResolver $resolver,
        protected TenantProcessDepthPolicy $policy,
    ) {
    }

    public function present(Order $order, array $overview): array
    {
        $tenant = $order->tenant;
        $resolved = $tenant ? $this->resolver->resolve($tenant) : [
            'key' => ProcessDepth::default(),
            'label' => ProcessDepth::label(ProcessDepth::default()),
            'source' => 'system_default',
            'source_label' => ProcessDepth::sourceLabel('system_default'),
            'is_overridden' => false,
        ];

        $capabilities = $this->policy->forDepth((string) ($resolved['key'] ?? ProcessDepth::default()));
        $density = (string) ($capabilities['operation_card_density'] ?? 'standard');
        $focus = $this->presentFocus($overview);

        return array_merge($resolved, [
            'presentation' => [
                'operation_card_density' => $density,
                'density_class' => match ($density) {
                    'compact' => 'pd-order-depth-compact',
                    'detailed' => 'pd-order-depth-detailed',
                    default => 'pd-order-depth-standard',
                },
                'show_extended_readiness_details' => (bool) ($capabilities['show_extended_readiness_details'] ?? false),
                'show_evidence_sections' => (bool) ($capabilities['show_evidence_sections'] ?? false),
                'show_quality_control_section' => (bool) ($capabilities['show_quality_control_section'] ?? false),
                'show_advanced_activity_timeline' => (bool) ($capabilities['show_advanced_activity_timeline'] ?? false),
                'show_batch_operation_controls' => (bool) ($capabilities['show_batch_operation_controls'] ?? false),
            ],
            'focus' => $focus,
        ]);
    }

    private function presentFocus(array $overview): array
    {
        $focusKey = (string) ($overview['workflow_focus_key'] ?? 'review');
        $links = (array) ($overview['links'] ?? []);

        return match ($focusKey) {
            'graphic_pending' => [
                'key' => $focusKey,
                'current_label' => 'Grafik kontrolü bekliyor',
                'next_label' => 'Revize veya onay bekleyen grafik işini tamamla',
                'blocker_label' => 'Revize veya onay bekleyen grafik işi var.',
                'primary_label' => 'Grafik Detayını Aç',
                'primary_url' => (string) ($links['graphic'] ?? $links['show'] ?? '#'),
            ],
            'procurement_pending' => [
                'key' => $focusKey,
                'current_label' => 'Tedarik bekliyor',
                'next_label' => 'Tedarik bilgilerini tamamla',
                'blocker_label' => null,
                'primary_label' => 'Tedariğe Git',
                'primary_url' => (string) ($links['procurement'] ?? $links['show'] ?? '#'),
            ],
            'delivery_pending' => [
                'key' => $focusKey,
                'current_label' => 'Teslimat bekliyor',
                'next_label' => 'Teslimat bilgilerini tamamla',
                'blocker_label' => null,
                'primary_label' => 'Teslimata Git',
                'primary_url' => (string) ($links['delivery'] ?? $links['show'] ?? '#'),
            ],
            'production_pending' => [
                'key' => $focusKey,
                'current_label' => 'Üretim bekliyor',
                'next_label' => 'Üretim detayını aç',
                'blocker_label' => null,
                'primary_label' => 'Üretimi Aç',
                'primary_url' => (string) ($links['production'] ?? $links['show'] ?? '#'),
            ],
            'payment_pending' => [
                'key' => $focusKey,
                'current_label' => 'Tahsilat bekliyor',
                'next_label' => 'Finans tahsilat durumunu incele',
                'blocker_label' => null,
                'primary_label' => 'Finans Özeti',
                'primary_url' => (string) ($links['finance'] ?? $links['show'] ?? '#'),
            ],
            default => [
                'key' => $focusKey,
                'current_label' => (string) ($overview['operation_status_label'] ?? 'Siparişi İzle'),
                'next_label' => (string) ($overview['next_action_label'] ?? 'Siparişi incele'),
                'blocker_label' => null,
                'primary_label' => 'Sipariş Detayını Aç',
                'primary_url' => (string) ($links['show'] ?? '#'),
            ],
        };
    }
}
