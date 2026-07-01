@extends('layouts.prodelya-admin')

@section('title', 'Super Admin Paneli')

@php
    $toneCardMap = [
        'blue' => 'pd-metric-card-soft-blue',
        'green' => 'pd-metric-card-soft-green',
        'amber' => 'pd-metric-card-soft-amber',
        'red' => 'pd-metric-card-soft-red',
        'slate' => 'pd-metric-card-soft-slate',
        'gray' => 'pd-metric-card-soft-slate',
    ];

    $toneBadgeMap = [
        'blue' => 'pd-badge-blue',
        'green' => 'pd-badge-green',
        'amber' => 'pd-badge-amber',
        'red' => 'pd-badge-red',
        'slate' => 'pd-badge-gray',
        'gray' => 'pd-badge-gray',
        'purple' => 'pd-badge-purple',
        'warning' => 'pd-badge-amber',
        'critical' => 'pd-badge-red',
        'info' => 'pd-badge-blue',
        'healthy' => 'pd-badge-green',
        'unknown' => 'pd-badge-gray',
    ];

    $operationDashboard = is_array($operationDashboard ?? null) ? $operationDashboard : [];
    $operationKpis = data_get($operationDashboard, 'kpis.cards', []);
    $actionQueue = data_get($operationDashboard, 'action_queue', ['critical' => [], 'today' => [], 'technical' => []]);
    $tenantReadiness = data_get($operationDashboard, 'tenant_readiness', ['counts' => [], 'rows' => []]);
    $signupFunnel = data_get($operationDashboard, 'signup_funnel', ['counts' => [], 'rows' => []]);
    $upgradeRequests = data_get($operationDashboard, 'upgrade_requests', ['counts' => [], 'rows' => []]);
    $productDataHub = data_get($operationDashboard, 'product_data_hub', ['counts' => [], 'rows' => [], 'warnings' => []]);
    $systemHealth = data_get($operationDashboard, 'system_health', []);
    $recentOperations = data_get($operationDashboard, 'recent_operations', []);
    $securityWarnings = data_get($operationDashboard, 'security_warnings', []);

    $tenantReadinessCounts = data_get($tenantReadiness, 'counts', []);
    $signupCounts = data_get($signupFunnel, 'counts', []);
    $upgradeCounts = data_get($upgradeRequests, 'counts', []);
    $productHubCounts = data_get($productDataHub, 'counts', []);
    $tenantReadinessMessage = data_get($tenantReadiness, 'message', 'Canlıya hazırlık listesi hazırlanıyor.');
    $signupMessage = data_get($signupFunnel, 'message', 'Şu an aksiyon gerektiren kayıt yok.');
    $upgradeMessage = data_get($upgradeRequests, 'message', 'Şu an aksiyon gerektiren kayıt yok.');
    $productDataHubMessage = data_get($productDataHub, 'message', 'Şu an aksiyon gerektiren kayıt yok.');
    $actionQueueMessage = data_get($actionQueue, 'message', 'Şu an aksiyon gerektiren kayıt yok.');

    $healthCards = [
        'queue_worker',
        'scheduler',
        'failed_jobs',
        'backup',
        'disk_usage',
        'database',
        'cache',
        'storage_link',
        'log_errors',
        'php_compatibility',
    ];
@endphp

@section('content')
<div class="pd-hub-family-shell pd-operation-dashboard-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Super Admin Operasyon Merkezi</h1>
                    <p class="pd-hero-subtitle">Başvuru, talep, Product Data Hub, canlıya hazırlık ve sistem sağlığı sinyallerini tek ekranda izleyin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Operasyon Paneli</span>
                        <span class="pd-badge pd-badge-gray">Canlıya Hazırlık</span>
                        <span class="pd-badge pd-badge-amber">Aksiyon Gerektirenler</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.signup-requests.index') }}" class="pd-btn pd-btn-light">Başvurular</a>
                    <a href="{{ route('admin.super.upgrade-requests.index') }}" class="pd-btn pd-btn-light">Abone Firma Talepleri</a>
                    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Product Data Hub</a>
                    <a href="{{ route('admin.super.tenants.create') }}" class="pd-btn pd-btn-primary">Yeni Abone Firma</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Operasyon Özeti</h3>
                <p class="pd-section-subtitle">Super Admin operasyon panelinin üst KPI kartları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-kpi-strip">
                @forelse($operationKpis as $card)
                    <div class="pd-metric-card {{ $toneCardMap[$card['tone'] ?? 'slate'] ?? 'pd-metric-card-soft-slate' }}">
                        <div class="pd-metric-card-label">{{ $card['label'] ?? 'Veri hazırlanıyor' }}</div>
                        <div class="pd-metric-card-value">{{ $card['value'] ?? 0 }}</div>
                        <div class="pd-metric-card-note">{{ $card['helper'] ?? 'Veri hazırlanıyor' }}</div>
                    </div>
                @empty
                    @for($i = 0; $i < 4; $i++)
                        <div class="pd-metric-card pd-metric-card-soft-slate">
                            <div class="pd-metric-card-label">Veri hazırlanıyor</div>
                            <div class="pd-metric-card-value">0</div>
                            <div class="pd-metric-card-note">Operasyon paneli verisi hazırlanıyor.</div>
                        </div>
                    @endfor
                @endforelse
            </div>
        </div>
    </section>

    <div class="pd-operation-layout">
        <div class="pd-operation-main">
            <section class="pd-section-card pd-section-card-soft-slate">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Canlıya Hazırlık</h3>
                    <p class="pd-section-subtitle">Abone Firma canlıya hazırlık sinyalleri ve operasyon öncelikleri.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-mini-kpi-grid">
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Canlıya Hazır Abone Firma</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['ready'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Kontrol Gerekir</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['warning'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Bloklu</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['blocked'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Demo/Test</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['demo_test'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Alan Adı / Panel Eksik</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['domain_panel_missing'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">SMTP / WhatsApp Eksik</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['smtp_whatsapp_missing'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Product Data Hub Hazırlığı Eksik</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['product_data_hub_missing'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Paket / Limit Uyarısı</div><div class="pd-mini-kpi-value">{{ $tenantReadinessCounts['package_limit_warning'] ?? 0 }}</div></div>
                    </div>

                    <div class="pd-section-nav mt-4">
                        <a href="{{ route('admin.super.tenants.index') }}" class="pd-chip">Tüm Abone Firmaları Gör</a>
                    </div>

                    <div class="pd-table-wrap mt-4">
                        <table class="pd-table pd-table-compact">
                            <thead>
                                <tr>
                                    <th>Abone Firma</th>
                                    <th>Paket</th>
                                    <th>Durum</th>
                                    <th>Canlıya Hazırlık</th>
                                    <th>Eksikler</th>
                                    <th>Son İşlem</th>
                                    <th>Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(data_get($tenantReadiness, 'rows', []) as $row)
                                    @php
                                        $readinessTone = match($row['readiness_status'] ?? 'warning') {
                                            'ready' => 'pd-badge-green',
                                            'blocked' => 'pd-badge-red',
                                            default => 'pd-badge-amber',
                                        };
                                        $readinessLabel = match($row['readiness_status'] ?? 'warning') {
                                            'ready' => 'Hazır',
                                            'blocked' => 'Bloklu',
                                            default => 'Kontrol Gerekir',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="font-medium text-gray-900">{{ $row['name'] ?? 'Abone Firma' }}</td>
                                        <td>{{ $row['package'] ?? '-' }}</td>
                                        <td>{{ $row['subscription_status'] ?? 'Bilinmiyor' }}</td>
                                        <td><span class="pd-badge {{ $readinessTone }}">{{ $readinessLabel }}</span></td>
                                        <td>
                                            <div class="pd-chip-group">
                                                @forelse(($row['missing_badges'] ?? []) as $badge)
                                                    <span class="pd-badge pd-badge-gray">{{ $badge }}</span>
                                                @empty
                                                    <span class="pd-badge pd-badge-green">Eksik yok</span>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td>{{ $row['last_activity'] ?? '-' }}</td>
                                        <td>
                                            @if(!empty($row['detail_route']))
                                                <a href="{{ $row['detail_route'] }}" class="pd-btn pd-btn-light pd-btn-sm">İncele</a>
                                            @else
                                                <span class="pd-badge pd-badge-gray">Bilgi</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-sm text-gray-600">{{ $tenantReadinessMessage }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="pd-grid pd-grid-2">
                <section class="pd-section-card pd-section-card-soft-blue">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Başvuru ve Satış Akışı</h3>
                            <p class="pd-section-subtitle">Public başvuru hattı ve dönüşüm akışı görünürlüğü.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        <div class="pd-mini-kpi-grid">
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Yeni Başvuru</div><div class="pd-mini-kpi-value">{{ $signupCounts['new'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Görüşüldü</div><div class="pd-mini-kpi-value">{{ $signupCounts['contacted'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Dönüşüm Önizlemesi Açıldı</div><div class="pd-mini-kpi-value">{{ $signupCounts['preview_opened'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Abone Firmaya Dönüştü</div><div class="pd-mini-kpi-value">{{ $signupCounts['converted'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Reddedildi</div><div class="pd-mini-kpi-value">{{ $signupCounts['rejected'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Arşivlendi</div><div class="pd-mini-kpi-value">{{ $signupCounts['archived'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Deneme Süreci Talebi</div><div class="pd-mini-kpi-value">{{ $signupCounts['trial'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Demo Talebi</div><div class="pd-mini-kpi-value">{{ $signupCounts['demo'] ?? 0 }}</div></div>
                        </div>

                        <div class="pd-table-wrap mt-4">
                            <table class="pd-table pd-table-compact">
                                <thead>
                                    <tr>
                                        <th>Firma</th>
                                        <th>Talep tipi</th>
                                        <th>Paket</th>
                                        <th>Hazırlık</th>
                                        <th>Son durum</th>
                                        <th>Son işlem</th>
                                        <th>Aksiyon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse(data_get($signupFunnel, 'rows', []) as $row)
                                        @php
                                            $signupTone = match($row['readiness_status'] ?? 'warning') {
                                                'ready' => 'pd-badge-green',
                                                'blocker' => 'pd-badge-red',
                                                default => 'pd-badge-amber',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="font-medium text-gray-900">{{ $row['company_name'] ?? 'Firma' }}</td>
                                            <td>{{ $row['request_type'] ?? '-' }}</td>
                                            <td>{{ $row['package'] ?? '-' }}</td>
                                            <td><span class="pd-badge {{ $signupTone }}">{{ $row['readiness_label'] ?? 'Kontrol Gerekir' }}</span></td>
                                            <td>
                                                <div>{{ $row['status'] ?? '-' }}</div>
                                                @if(!empty($row['queue_label']))
                                                    <div class="pd-request-cell-meta mt-1">{{ $row['queue_label'] }}</div>
                                                @endif
                                            </td>
                                            <td>{{ $row['last_action_at'] ?? '-' }}</td>
                                            <td>
                                                @if(!empty($row['is_actionable']) && !empty($row['action_route']))
                                                    <a href="{{ $row['action_route'] }}" class="pd-btn pd-btn-light pd-btn-sm">
                                                        {{ $row['action_label'] ?? 'İncele' }}
                                                    </a>
                                                @else
                                                    <span class="pd-request-cell-meta">Şu an aksiyon gerektiren kayıt yok.</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-sm text-gray-600">{{ $signupMessage }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="pd-section-card pd-section-card-soft-purple">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Abone Firma Talepleri</h3>
                            <p class="pd-section-subtitle">Onay, uygulama ve güvenlik kontrolüne takılan talepler.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        <div class="pd-mini-kpi-grid">
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Bekleyen</div><div class="pd-mini-kpi-value">{{ $upgradeCounts['pending'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">İncelemede</div><div class="pd-mini-kpi-value">{{ $upgradeCounts['in_review'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Onaylanan</div><div class="pd-mini-kpi-value">{{ $upgradeCounts['approved'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Uygulama Bekleyen</div><div class="pd-mini-kpi-value">{{ $upgradeCounts['approved_but_unapplied'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Uygulandı</div><div class="pd-mini-kpi-value">{{ $upgradeCounts['applied'] ?? 0 }}</div></div>
                            <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Güvenlik Kontrolüne Takılan</div><div class="pd-mini-kpi-value">{{ $upgradeCounts['apply_blocked_or_failed'] ?? 0 }}</div></div>
                        </div>

                        <div class="pd-health-list mt-4">
                            @foreach([
                                'package_upgrade' => 'Paket Yükseltme',
                                'module_addon' => 'Ek Modül',
                                'feature_addon' => 'Ek Özellik',
                                'limit_increase' => 'Limit Artırma',
                                'supplier_access' => 'Tedarikçi Erişimi',
                                'service_request' => 'Ek Hizmet',
                            ] as $typeKey => $typeLabel)
                                <span>{{ $typeLabel }}: {{ data_get($upgradeCounts, 'by_type.' . $typeKey, 0) }}</span>
                            @endforeach
                        </div>

                        <div class="pd-table-wrap mt-4">
                            <table class="pd-table pd-table-compact">
                                <thead>
                                    <tr>
                                        <th>Abone Firma</th>
                                        <th>Talep tipi</th>
                                        <th>Durum</th>
                                        <th>Risk / Etki</th>
                                        <th>Son işlem</th>
                                        <th>Aksiyon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse(data_get($upgradeRequests, 'rows', []) as $row)
                                        <tr>
                                            <td class="font-medium text-gray-900">{{ $row['tenant'] ?? 'Abone Firma' }}</td>
                                            <td>{{ $row['request_type_label'] ?? '-' }}</td>
                                            <td>
                                                <span class="pd-badge {{ $toneBadgeMap[$row['status_tone'] ?? 'gray'] ?? 'pd-badge-gray' }}">{{ $row['status_label'] ?? 'Bilinmiyor' }}</span>
                                                @if(!empty($row['queue_badge']))
                                                    <div class="mt-2">
                                                        <span class="pd-badge {{ $toneBadgeMap[$row['queue_badge_tone'] ?? 'gray'] ?? 'pd-badge-gray' }}">{{ $row['queue_badge'] }}</span>
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                <div>{{ $row['risk_summary'] ?? 'Detay ekranda incelenmeli.' }}</div>
                                                @if(!empty($row['has_apply_failed']))
                                                    <div class="pd-request-cell-meta mt-1">Uygulama hatası yeniden inceleme gerektirir.</div>
                                                @elseif(!empty($row['has_apply_blocked']))
                                                    <div class="pd-request-cell-meta mt-1">Güvenlik kontrolü nedeniyle mutation yapılmadı.</div>
                                                @elseif(($row['status_key'] ?? null) === 'approved')
                                                    <div class="pd-request-cell-meta mt-1">Onaylanan talep Abone Firma tarafına henüz uygulanmadı.</div>
                                                @endif
                                            </td>
                                            <td>{{ $row['last_action'] ?? '-' }}</td>
                                            <td>
                                                @if(!empty($row['is_actionable']) && !empty($row['action_route']))
                                                    <a href="{{ $row['action_route'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $row['action_label'] ?? 'Detay' }}</a>
                                                @else
                                                    <span class="pd-request-cell-meta">Şu an aksiyon gerektiren kayıt yok.</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-sm text-gray-600">{{ $upgradeMessage }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <section class="pd-section-card pd-section-card-soft-blue">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Product Data Hub Durumu</h3>
                        <p class="pd-section-subtitle">Tedarikçi veri akışı ve katalog etkisi için üst seviye görünüm.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-mini-kpi-grid">
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Aktif Tedarikçi Kaynağı</div><div class="pd-mini-kpi-value">{{ $productHubCounts['active_supplier_sources'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Son Senkronizasyon Başarılı</div><div class="pd-mini-kpi-value">{{ $productHubCounts['last_sync_successful'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Fiyat / Stok Güncel</div><div class="pd-mini-kpi-value">{{ $productHubCounts['price_stock_current'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">İnceleme Bekleyen</div><div class="pd-mini-kpi-value">{{ $productHubCounts['review_pending'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Kataloğa Yansıtma Eski</div><div class="pd-mini-kpi-value">{{ $productHubCounts['projection_stale'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Senkronizasyon Hatası</div><div class="pd-mini-kpi-value">{{ $productHubCounts['sync_errors'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Tedarikçi Erişimi Sonrası Kontrol</div><div class="pd-mini-kpi-value">{{ $productHubCounts['supplier_access_followup'] ?? 0 }}</div></div>
                        <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Abone Firma Etkisi Olan Kaynak</div><div class="pd-mini-kpi-value">{{ $productHubCounts['tenant_impact_sources'] ?? 0 }}</div></div>
                    </div>

                    @if(!empty(data_get($productDataHub, 'warnings', [])))
                        <div class="pd-alert pd-alert-warning mt-4">Kontrol Gerekir: {{ data_get($productDataHub, 'warnings.0') }}</div>
                    @endif

                    <div class="pd-table-wrap mt-4">
                        <table class="pd-table pd-table-compact">
                            <thead>
                                <tr>
                                    <th>Tedarikçi</th>
                                    <th>Son Senkronizasyon</th>
                                    <th>Fiyat / Stok</th>
                                    <th>İnceleme</th>
                                    <th>Abone Firma Etkisi</th>
                                    <th>Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(data_get($productDataHub, 'rows', []) as $row)
                                    @php
                                        $hubTone = $toneBadgeMap[$row['status_tone'] ?? 'gray'] ?? 'pd-badge-gray';
                                    @endphp
                                    <tr>
                                        <td class="font-medium text-gray-900">{{ $row['supplier_name'] ?? 'Tedarikçi' }}</td>
                                        <td>{{ $row['last_sync'] ?? '-' }}</td>
                                        <td><span class="pd-badge {{ $hubTone }}">{{ $row['price_stock_status'] ?? 'Kontrol Gerekir' }}</span></td>
                                        <td>{{ $row['review_count'] ?? 0 }}</td>
                                        <td>{{ $row['tenant_impact'] ?? 0 }}</td>
                                        <td>
                                            @if(!empty($row['is_actionable']) && !empty($row['route']))
                                                <a href="{{ $row['route'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $row['action_label'] ?? 'İncele' }}</a>
                                            @else
                                                <span class="pd-request-cell-meta">Şu an aksiyon gerektiren kayıt yok.</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-sm text-gray-600">{{ $productDataHubMessage }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="pd-grid pd-grid-2">
                <section class="pd-section-card pd-section-card-soft-slate">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Sistem Sağlığı</h3>
                            <p class="pd-section-subtitle">Bu bölümde gerçek çalışma zamanı ölçümü sonraki fazda derinleşecek.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        <div class="pd-operation-health-grid">
                            @foreach($healthCards as $healthKey)
                                @php $item = $systemHealth[$healthKey] ?? null; @endphp
                                <div class="pd-panel-card">
                                    <div class="pd-panel-card-body">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="font-medium text-gray-900">{{ $item['label'] ?? 'Veri hazırlanıyor' }}</div>
                                            <span class="pd-badge {{ $toneBadgeMap[$item['status'] ?? 'unknown'] ?? 'pd-badge-gray' }}">{{ $item['status_label'] ?? 'Bilinmiyor' }}</span>
                                        </div>
                                        <div class="pd-panel-card-copy mt-3">{{ $item['description'] ?? 'Veri hazırlanıyor.' }}</div>
                                        @if(!empty($item['checked_at']))
                                            <div class="pd-field-note">Son kontrol: {{ $item['checked_at'] }}</div>
                                        @endif
                                        @if(!empty($item['details']) && is_array($item['details']))
                                            <div class="pd-health-list mt-3">
                                                @foreach(array_slice($item['details'], 0, 3) as $detail)
                                                    <div class="pd-health-row">
                                                        <div class="pd-health-row-copy">{{ $detail }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if(($item['is_placeholder'] ?? false) === true)
                                            <div class="pd-field-note">Bu sinyal sonraki fazda gerçek çalışma zamanı ölçümüyle beslenecek.</div>
                                        @endif
                                        @if(!empty($item['route']))
                                            <div class="mt-3">
                                                <a href="{{ $item['route'] }}" class="pd-btn pd-btn-light pd-btn-sm">Detay</a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="pd-section-card pd-section-card-soft-slate">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Canlı Güvenlik Uyarıları</h3>
                            <p class="pd-section-subtitle">Risk görünür olur, hassas değer gösterilmez.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        <div class="pd-operation-warning-list">
                            @forelse($securityWarnings as $warning)
                                <div class="pd-health-row">
                                    <div>
                                        <div class="pd-health-row-title">{{ $warning['title'] ?? 'Güvenlik uyarısı' }}</div>
                                        <div class="pd-health-row-copy">{{ $warning['description'] ?? 'Detay hazırlanıyor.' }}</div>
                                    </div>
                                    <span class="pd-badge {{ $toneBadgeMap[$warning['tone'] ?? 'warning'] ?? 'pd-badge-amber' }}">
                                        {{ ($warning['tone'] ?? 'warning') === 'warning' ? 'Kontrol Gerekir' : 'Bilgi' }}
                                    </span>
                                </div>
                            @empty
                                <div class="pd-alert pd-alert-warning">Canlı güvenlik uyarısı verisi hazırlanıyor.</div>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>

            <section class="pd-section-card pd-section-card-soft-slate">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Son Operasyonlar</h3>
                        <p class="pd-section-subtitle">Başvuru, talep ve erişim hareketleri tek listede özetlenir.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-operation-timeline">
                        @forelse($recentOperations as $item)
                            <div class="pd-detail-card">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-gray-900">{{ $item['event_title'] ?? 'Operasyon kaydı' }}</div>
                                        <div class="pd-request-cell-meta mt-1">
                                            {{ $item['tenant'] ?? $item['subject'] ?? 'Sistem' }} · {{ $item['actor'] ?? 'Sistem' }} · {{ $item['occurred_at'] ?? '-' }}
                                        </div>
                                    </div>
                                    <span class="pd-badge {{ $toneBadgeMap[$item['tone'] ?? 'gray'] ?? 'pd-badge-gray' }}">
                                        {{ match($item['tone'] ?? 'gray') {
                                            'green' => 'Tamamlandı',
                                            'red' => 'Kontrol Gerekir',
                                            'amber' => 'İşlemde',
                                            default => 'Bilgi',
                                        } }}
                                    </span>
                                </div>
                                <div class="pd-panel-card-copy mt-3">{{ $item['summary'] ?? 'Detay hazırlanıyor.' }}</div>
                                @if(!empty($item['route']))
                                    <div class="mt-3">
                                        <a href="{{ $item['route'] }}" class="pd-btn pd-btn-light pd-btn-sm">Detay</a>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="pd-alert pd-alert-warning">Son operasyonlar listesi hazırlanıyor.</div>
                        @endforelse
                    </div>
                </div>
            </section>

            <div class="pd-grid pd-grid-2">
                <section class="pd-section-card pd-section-card-soft-slate">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Sistem Genel Readiness</h3>
                            <p class="pd-section-subtitle">Depolama, kuyruk, log ve güvenlik başlıkları yapay olarak hazır gösterilmeden izlenir.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        @if(!empty($systemReadinessChecklist ?? []))
                            <div class="pd-health-list">
                                @foreach(($systemReadinessChecklist ?? []) as $item)
                                    <div class="pd-health-row">
                                        <div>
                                            <div class="pd-health-row-title">{{ $item['label'] }}</div>
                                            <div class="pd-health-row-copy">{{ $item['message'] }}</div>
                                        </div>
                                        <span class="pd-badge {{ match($item['status']) {
                                            'Hazır' => 'pd-badge-green',
                                            'Eksik' => 'pd-badge-red',
                                            'Kontrol Edilmeli' => 'pd-badge-amber',
                                            'Sonraki Faz' => 'pd-badge-gray',
                                            default => 'pd-badge-blue',
                                        } }}">{{ $item['status'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="pd-alert pd-alert-warning">Sistem readiness checklist’i henüz hazır değil.</div>
                        @endif
                    </div>
                </section>

                <section class="pd-section-card pd-section-card-soft-slate">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Canlıya Geçiş Öncesi Demo Kontrolü</h3>
                            <p class="pd-section-subtitle">Demo veriler yalnız raporlanır; bu fazda otomatik silme yapılmaz.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        @if(!empty($demoDataChecklist ?? []))
                            <div class="pd-health-list">
                                @foreach(($demoDataChecklist ?? []) as $item)
                                    <div class="pd-health-row">
                                        <div>
                                            <div class="pd-health-row-title">{{ $item['label'] }}</div>
                                            <div class="pd-health-row-copy">{{ $item['message'] }}</div>
                                        </div>
                                        <span class="pd-badge {{ match($item['status']) {
                                            'Hazır' => 'pd-badge-green',
                                            'Eksik' => 'pd-badge-red',
                                            'Kontrol Edilmeli' => 'pd-badge-amber',
                                            'Sonraki Faz' => 'pd-badge-gray',
                                            'Demo/Test' => 'pd-badge-blue',
                                            default => 'pd-badge-gray',
                                        } }}">{{ $item['status'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="pd-alert pd-alert-warning">Demo veri kontrol özeti henüz hazırlanamadı.</div>
                        @endif
                    </div>
                </section>
            </div>

            <div class="pd-grid pd-grid-2">
                <section class="pd-section-card pd-section-card-soft-slate">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Son 7 Gün Açılan Abone Firmalar</h3>
                            <p class="pd-section-subtitle">Yeni açılan Abone Firmaları panel yetkilisi, paket ve canlıya hazırlık durumu ile izleyin.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        @if(!empty($recentTenants))
                            <div class="pd-table-wrap">
                                <table class="pd-table">
                                    <thead>
                                        <tr>
                                            <th>Abone Firma</th>
                                            <th>Paket</th>
                                            <th>Durum</th>
                                            <th>Panel Yetkilisi</th>
                                            <th>Oluşturulma</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recentTenants as $row)
                                            <tr>
                                                <td>
                                                    <a href="{{ route('admin.super.tenants.show', $row['tenant_id']) }}" class="font-medium text-gray-900">{{ $row['name'] }}</a>
                                                </td>
                                                <td>{{ $row['package_label'] }}</td>
                                                <td><span class="pd-badge {{ $toneBadgeMap[$row['subscription']['severity']] ?? 'pd-badge-gray' }}">{{ $row['subscription']['label'] }}</span></td>
                                                <td>{{ $row['owner_name'] ?: 'Panel Yetkilisi eksik' }}</td>
                                                <td>{{ $row['created_at']?->format('d.m.Y H:i') ?: '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="pd-alert pd-alert-warning">Son 7 günde açılan Abone Firma kaydı bulunmuyor.</div>
                        @endif
                    </div>
                </section>

                <section class="pd-section-card pd-section-card-soft-slate">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Paket Dağılımı</h3>
                            <p class="pd-section-subtitle">Mevcut paket atamaları ve merkezi operasyon notları.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        @if(!empty($packageBreakdown))
                            <div class="pd-status-list">
                                @foreach($packageBreakdown as $package)
                                    <div class="pd-status-row">
                                        <span>{{ $package['label'] }}</span>
                                        <strong>{{ $package['count'] }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="pd-alert pd-alert-warning">Henüz takip edilecek paket dağılımı görünmüyor.</div>
                        @endif

                        <div class="pd-summary-section">
                            <h4 class="pd-summary-section-title">Operasyon Notları</h4>
                            <div class="pd-summary-action-list">
                                @foreach(($operationalNotes ?? []) as $note)
                                    <span class="pd-summary-action">
                                        <span>{{ $note['label'] }}</span>
                                        <span class="pd-badge {{ match($note['status']) {
                                            'Hazır' => 'pd-badge-green',
                                            'Kontrol Edilmeli' => 'pd-badge-amber',
                                            'Sonraki Faz' => 'pd-badge-gray',
                                            default => 'pd-badge-blue',
                                        } }}">{{ $note['status'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                            @if(!empty($operationalNotes ?? []))
                                <div class="pd-health-list pd-gap-top-md">
                                    @foreach(($operationalNotes ?? []) as $note)
                                        <div class="pd-health-row">
                                            <div>
                                                <div class="pd-health-row-title">{{ $note['label'] }}</div>
                                                <div class="pd-health-row-copy">{{ $note['message'] }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <aside class="pd-operation-aside">
            <div class="pd-side-summary">
                <div class="pd-card-header">
                    <h3 class="pd-summary-title">Aksiyon Gerektirenler</h3>
                </div>
                <div class="pd-card-body pd-stack-md">
                    @foreach([
                        'critical' => 'Kritik',
                        'today' => 'Bugün',
                        'technical' => 'Teknik',
                    ] as $groupKey => $groupLabel)
                        <div class="pd-summary-section">
                            <h4 class="pd-summary-section-title">{{ $groupLabel }}</h4>
                            <div class="pd-summary-action-list">
                                @forelse($actionQueue[$groupKey] ?? [] as $item)
                                    @if(!empty($item['is_actionable']) && !empty($item['route']))
                                        <a href="{{ $item['route'] }}" class="pd-summary-action">
                                            <span>
                                                {{ $item['title'] ?? 'Aksiyon' }}
                                                <span class="pd-request-cell-meta">{{ $item['description'] ?? '' }}</span>
                                                <span class="pd-request-cell-meta">{{ $item['severity_label'] ?? 'Bilgi' }} · {{ $item['action_label'] ?? 'İncele' }}</span>
                                            </span>
                                            <span class="pd-badge {{ $toneBadgeMap[$item['severity'] ?? 'warning'] ?? 'pd-badge-amber' }}">{{ $item['count'] ?? 0 }}</span>
                                        </a>
                                    @else
                                        <span class="pd-summary-action">
                                            <span>
                                                {{ $item['title'] ?? 'Aksiyon' }}
                                                <span class="pd-request-cell-meta">{{ $item['description'] ?? '' }}</span>
                                                <span class="pd-request-cell-meta">{{ $item['severity_label'] ?? 'Bilgi' }}</span>
                                            </span>
                                            <span class="pd-badge {{ $toneBadgeMap[$item['severity'] ?? 'warning'] ?? 'pd-badge-amber' }}">{{ $item['count'] ?? 0 }}</span>
                                        </span>
                                    @endif
                                @empty
                                    <span class="pd-summary-action">
                                        <span>{{ $actionQueueMessage }}</span>
                                        <span class="pd-badge pd-badge-green">0</span>
                                    </span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection
