@extends('layouts.prodelya-admin')

@section('title', 'Kurulum Merkezi')
@section('page_title', 'Kurulum Merkezi')
@section('page_subtitle', 'Abone Firma ayarlarını, canlıya hazırlık durumunu ve günlük kurulum aksiyonlarını sekmeli yapı içinde yönetin.')
@section('hide_side_summary', true)

@php
    $statusPillMap = [
        'Hazır' => 'badge-green',
        'Aktif' => 'badge-green',
        'Eksik' => 'badge-red',
        'Kontrol Gerekir' => 'badge-amber',
        'Kontrol Edilmeli' => 'badge-amber',
        'Pakette Yok' => 'badge-gray',
        'Sonraki Faz' => 'badge-gray',
        'Bilgi' => 'badge-blue',
        'Pasif' => 'badge-gray',
        'Veri yok' => 'badge-gray',
        'Tanımlı' => 'badge-green',
    ];

    $usageBadgeMap = [
        'ok' => 'badge-green',
        'warning' => 'badge-amber',
        'exceeded' => 'badge-red',
        'unlimited' => 'badge-blue',
    ];

    $usageLabelMap = [
        'ok' => 'Aktif',
        'warning' => 'Limit dolmak üzere',
        'exceeded' => 'Limit aşıldı',
        'unlimited' => 'Limitsiz',
    ];

    $activeTabMeta = collect($settingsTabs)->firstWhere('key', $activeSettingsTab) ?? collect($settingsTabs)->first();
    $activeTabDescription = $activeTabMeta['description'] ?? '';
    $userUsage = collect($usageSnapshot)->first(fn (array $usage) => str_contains(mb_strtolower((string) ($usage['label'] ?? '')), 'kullan'));
    $latestSignalLabel = $settingsOverview['latest_signal'] ?? 'Kurulum özeti';
@endphp

@section('content')
<style>
    .setup-center-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .setup-center-topbar,
    .setup-center-hero,
    .setup-center-section,
    .setup-summary-panel,
    .setup-module-block,
    .setup-status-card,
    .setup-check-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .setup-center-topbar,
    .setup-center-hero,
    .setup-center-section,
    .setup-summary-panel {
        padding: 18px;
    }
    .setup-center-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
    }
    .setup-center-topbar h2,
    .setup-section-title,
    .setup-module-title {
        margin: 0;
        color: #1f2d45;
        font-weight: 600;
    }
    .setup-center-topbar h2 {
        font-size: 26px;
        line-height: 1.15;
    }
    .setup-muted,
    .setup-mini-text,
    .setup-module-desc,
    .setup-info-key,
    .setup-tab-caption {
        color: #6d7788;
    }
    .setup-mini-text {
        font-size: 12px;
        line-height: 1.5;
    }
    .setup-top-actions,
    .setup-chip-row,
    .setup-tab-toolbar,
    .setup-tab-buttons,
    .setup-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .setup-chip {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 0 10px;
        border-radius: 4px;
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #58657a;
        font-size: 12px;
        white-space: nowrap;
    }
    .setup-chip-blue {
        background: #eef4ff;
        border-color: #dce7ff;
        color: #2f6fed;
    }
    .setup-chip-green {
        background: #edf8f2;
        border-color: #d8f0e4;
        color: #238a55;
    }
    .setup-chip-amber {
        background: #fff6e7;
        border-color: #f1ddb5;
        color: #c47713;
    }
    .setup-chip-gray {
        background: #f5f7fa;
        color: #6b7587;
    }
    .setup-center-hero {
        margin-bottom: 14px;
    }
    .setup-hero-grid {
        display: grid;
        grid-template-columns: 1.35fr .95fr;
        gap: 14px;
        align-items: start;
    }
    .setup-info-banner {
        border: 1px solid #dfe7f3;
        border-left: 3px solid #2f6fed;
        background: #f8fbff;
        border-radius: 6px;
        padding: 12px 14px;
        color: #536176;
        font-size: 13px;
        line-height: 1.55;
    }
    .setup-status-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-top: 14px;
    }
    .setup-status-card {
        padding: 12px;
        min-height: 84px;
    }
    .setup-status-label {
        color: #6f7b90;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 6px;
    }
    .setup-status-value {
        color: #233149;
        font-size: 16px;
        margin-bottom: 4px;
        font-weight: 600;
    }
    .setup-check-card {
        padding: 0;
        overflow: hidden;
    }
    .setup-check-head {
        padding: 16px 18px 10px;
        border-bottom: 1px solid #edf1f5;
        background: linear-gradient(180deg, #fff 0%, #fcfcfd 100%);
    }
    .setup-check-list {
        display: grid;
        gap: 8px;
        padding: 18px;
    }
    .setup-check-item {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        border: 1px solid #e7ebf1;
        border-radius: 6px;
        padding: 10px 12px;
        background: #fbfcfd;
    }
    .setup-check-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        margin-top: 5px;
        flex: 0 0 auto;
        background: #bbc5d4;
    }
    .setup-check-dot.green { background: #238a55; }
    .setup-check-dot.amber { background: #c47713; }
    .setup-layout {
        display: grid;
        grid-template-columns: minmax(760px, 1fr) 305px;
        gap: 16px;
        align-items: start;
    }
    .setup-summary-panel {
        position: sticky;
        top: 18px;
        padding: 0;
        overflow: hidden;
    }
    .setup-summary-section {
        padding: 14px 16px;
        border-bottom: 1px solid #edf1f5;
    }
    .setup-summary-section:last-child {
        border-bottom: 0;
    }
    .setup-summary-title {
        color: #748096;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 10px;
    }
    .setup-progress-wrap {
        border: 1px solid #e6ebf2;
        background: #f8fafc;
        border-radius: 6px;
        padding: 10px 12px;
    }
    .setup-progress-top {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 8px;
        font-size: 13px;
        color: #233149;
    }
    .setup-progress-bar {
        height: 6px;
        background: #e6ebf3;
        border-radius: 999px;
        overflow: hidden;
    }
    .setup-progress-bar > span {
        display: block;
        height: 100%;
        width: {{ max(0, min(100, $settingsOverview['progress_percent'] ?? 0)) }}%;
        background: linear-gradient(90deg, #2f6fed, #79a3ff);
    }
    .setup-summary-list,
    .setup-quick-actions,
    .setup-summary-checks {
        display: grid;
        gap: 8px;
    }
    .setup-summary-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        border-top: 1px solid #edf1f5;
        padding-top: 9px;
        color: #425066;
        font-size: 12.5px;
    }
    .setup-summary-row:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .setup-section-head {
        padding-bottom: 14px;
        border-bottom: 1px solid #edf1f5;
        margin-bottom: 14px;
    }
    .setup-tab-toolbar {
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 14px;
    }
    .setup-tab-buttons {
        flex: 1 1 auto;
    }
    .setup-tab-button {
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #42506a;
        min-height: 36px;
        padding: 0 12px;
        border-radius: 5px;
        font: inherit;
        cursor: pointer;
    }
    .setup-tab-button.is-active {
        color: #2f6fed;
        background: #eef4ff;
        border-color: #dbe7ff;
    }
    .setup-tab-caption {
        min-height: 28px;
        padding: 0 10px;
        border-radius: 4px;
        border: 1px solid #dce7ff;
        background: #eef4ff;
        color: #2f6fed;
        display: inline-flex;
        align-items: center;
        font-size: 12px;
    }
    .js-settings-tabs .setup-tab-panel {
        display: none;
    }
    .js-settings-tabs .setup-tab-panel.is-active {
        display: block;
    }
    .setup-card-grid-2,
    .setup-card-grid-3,
    .setup-info-grid,
    .setup-module-grid,
    .setup-usage-grid {
        display: grid;
        gap: 12px;
    }
    .setup-card-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .setup-card-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .setup-info-grid { grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
    .setup-module-grid { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
    .setup-usage-grid { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
    .setup-module-block {
        padding: 14px;
        min-height: 162px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .setup-module-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
    }
    .setup-module-title {
        font-size: 16px;
    }
    .setup-module-desc {
        font-size: 12px;
        margin-top: 3px;
    }
    .setup-info-list {
        display: grid;
        gap: 8px;
        margin-top: 2px;
    }
    .setup-info-row {
        display: grid;
        grid-template-columns: 145px minmax(0, 1fr);
        gap: 12px;
        align-items: start;
        padding: 8px 0;
        border-top: 1px solid #edf1f5;
    }
    .setup-info-row:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .setup-info-key {
        font-size: 12px;
    }
    .setup-info-val {
        color: #243149;
        font-size: 13px;
    }
    .setup-link-list {
        display: grid;
        gap: 8px;
        margin-top: auto;
    }
    .setup-action-link {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 38px;
        padding: 0 12px;
        border: 1px solid #e5e7eb;
        border-radius: 5px;
        background: #fafbfd;
        color: #30405a;
        text-decoration: none;
    }
    .setup-action-link.primary {
        background: #eef4ff;
        border-color: #dce7ff;
        color: #2f6fed;
    }
    .setup-note-box {
        border: 1px solid #dce7ff;
        background: #f8fbff;
        border-radius: 6px;
        padding: 12px 14px;
        color: #536176;
        font-size: 12px;
        line-height: 1.55;
        margin-top: 12px;
    }
    .setup-static-chip {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 0 10px;
        border-radius: 5px;
        border: 1px solid #e5e7eb;
        background: #f8fafc;
        color: #6b7587;
        font-size: 12px;
    }
    @media (max-width: 1300px) {
        .setup-layout {
            grid-template-columns: 1fr;
        }
        .setup-summary-panel {
            position: static;
        }
    }
    @media (max-width: 1080px) {
        .setup-hero-grid {
            grid-template-columns: 1fr;
        }
        .setup-status-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 920px) {
        .setup-center-topbar {
            padding: 14px;
        }
        .setup-center-topbar h2 {
            font-size: 24px;
        }
        .setup-card-grid-2,
        .setup-card-grid-3,
        .setup-status-grid,
        .setup-info-row,
        .setup-hero-grid,
        .setup-module-grid,
        .setup-usage-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="setup-center-shell">
    @if(session('success'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if($subscription['status'] === 'expired')
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Paket süresi dolmuş. İşlem yapmak için paket yenilenmeli.
        </div>
    @endif

    <div class="setup-center-topbar">
        <div>
            <h2>Kurulum Merkezi</h2>
            <p class="setup-muted">Abone Firma ayarlarını, canlıya hazırlık durumunu ve günlük kurulum aksiyonlarını sekmeli yapı içinde yönetin.</p>
            <div class="setup-chip-row mt-3">
                <span class="setup-chip setup-chip-blue">Sekmeli görünüm</span>
                <span class="setup-chip setup-chip-green">Hazır alanlar</span>
                <span class="setup-chip setup-chip-amber">Kontrol gerekenler</span>
                <span class="setup-chip setup-chip-gray">Sonraki faz alanları</span>
            </div>
        </div>
        <div class="setup-top-actions">
            <span class="setup-chip">Para birimi: {{ $tenant->default_currency ?: 'TL' }}</span>
            <span class="setup-chip">{{ $settingsOverview['package_label'] }}</span>
        </div>
    </div>

    <div class="setup-center-hero">
        <div class="setup-section-head border-0 mb-0 pb-0">
            <h3 class="setup-section-title">Abone Firma ayarlarını ve canlıya hazırlık durumunu tek merkezden yönetin.</h3>
            <p class="setup-muted mt-1">Üst bölüm hızlı özet verir. Detaylar kategori sekmeleri altında açılır.</p>
        </div>
        <div class="setup-hero-grid mt-4">
            <div>
                <div class="setup-info-banner">
                    Firma profili, panel ve portal, bildirimler, paket durumu, kullanıcılar, katalog erişimi, dosya yapısı ve Talep Merkezi aynı ekranda görünür; ancak kullanıcı sekmeden yalnız ilgilendiği alanı açar.
                </div>
                <div class="setup-status-grid">
                    <div class="setup-status-card">
                        <div class="setup-status-label">Paket</div>
                        <div class="setup-status-value">{{ $settingsOverview['package_label'] }}</div>
                        <div><span class="badge {{ $statusPillMap[$subscription['label']] ?? 'badge-blue' }}">{{ $subscription['label'] }}</span></div>
                    </div>
                    <div class="setup-status-card">
                        <div class="setup-status-label">Canlıya Hazırlık</div>
                        <div class="setup-status-value">{{ $settingsOverview['ready_count'] }} / {{ $settingsOverview['total_count'] }} hazır</div>
                        <div><span class="badge badge-amber">{{ $settingsOverview['attention_count'] }} alan kontrol</span></div>
                    </div>
                    <div class="setup-status-card">
                        <div class="setup-status-label">Kullanıcılar</div>
                        <div class="setup-status-value">{{ $userRoleSummary['active_users'] }} / {{ $userUsage['limit'] ?? 'Limitsiz' }}</div>
                        <div><span class="badge badge-blue">Ekip açık</span></div>
                    </div>
                    <div class="setup-status-card">
                        <div class="setup-status-label">Son İşlem</div>
                        <div class="setup-status-value">{{ $latestSignalLabel }}</div>
                        <div><span class="badge {{ ($settingsOverview['attention_count'] ?? 0) > 0 ? 'badge-amber' : 'badge-green' }}">{{ ($settingsOverview['attention_count'] ?? 0) > 0 ? 'Kontrol Gerekir' : 'Hazır' }}</span></div>
                    </div>
                </div>
            </div>
            <div class="setup-check-card">
                <div class="setup-check-head">
                    <h3 class="setup-section-title">Bugün Ne Tamamlanmalı?</h3>
                    <p class="setup-muted mt-1">İlk kurulum ve günlük ayar kontrolü için kısa aksiyon listesi.</p>
                </div>
                <div class="setup-check-list">
                    @foreach($settingsOverview['focus_actions'] as $action)
                        <div class="setup-check-item">
                            <span class="setup-check-dot {{ $action['tone'] === 'green' ? 'green' : 'amber' }}"></span>
                            <div>
                                <div class="text-gray-900">{{ $action['title'] }}</div>
                                <div class="setup-mini-text">{{ $action['description'] }}</div>
                            </div>
                        </div>
                    @endforeach
                    <div class="setup-note-box">Uzun tek sayfa yerine sekme sistemi kullanılır. Kullanıcı yalnız ilgili kurulum alanını açar.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="setup-layout" data-settings-shell>
        <section class="setup-center-section">
            <div class="setup-section-head">
                <h3 class="setup-section-title">Kategori Bazlı Kurulum Alanları</h3>
                <p class="setup-muted mt-1">Her alan kendi sekmesinde açılır. İlgisiz bilgiler gizli kalır, aktif sekme odakta çalışılır.</p>
            </div>

            <div class="setup-tab-toolbar">
                <div class="setup-tab-buttons" role="tablist" aria-label="Kurulum Merkezi Sekmeleri">
                    @foreach($settingsTabs as $tab)
                        <button
                            type="button"
                            class="setup-tab-button {{ $activeSettingsTab === $tab['key'] ? 'is-active' : '' }}"
                            data-settings-tab-trigger="{{ $tab['key'] }}"
                            role="tab"
                            aria-selected="{{ $activeSettingsTab === $tab['key'] ? 'true' : 'false' }}"
                        >
                            {{ $tab['label'] }}
                        </button>
                    @endforeach
                </div>
                @if($activeTabDescription !== '')
                    <span class="setup-tab-caption" data-settings-tab-caption>{{ $activeTabDescription }}</span>
                @endif
            </div>

            <div class="setup-tabs-root">
                <section class="setup-tab-panel {{ $activeSettingsTab === 'company-profile' ? 'is-active' : '' }}" data-settings-tab-panel="company-profile">
                    <div class="setup-card-grid-2">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">{{ $companyProfileCards['identity']['title'] }}</h4>
                                    <div class="setup-module-desc">Görünen firma adı, yasal unvan ve temel iletişim alanları.</div>
                                </div>
                                <span class="badge badge-green">Hazır</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Görünen firma adı</div><div class="setup-info-val">{{ $companyProfileCards['identity']['items'][0]['value'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Yasal unvan</div><div class="setup-info-val">{{ $companyProfileCards['identity']['items'][1]['value'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Telefon / E-posta</div><div class="setup-info-val">{{ $companyProfileCards['identity']['items'][2]['value'] }} · {{ $companyProfileCards['identity']['items'][3]['value'] }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($companyProfileCards['identity']['action_url'])
                                    <a class="setup-action-link primary" href="{{ $companyProfileCards['identity']['action_url'] }}">Firma Bilgilerini Düzenle <span>Yönet</span></a>
                                @endif
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">{{ $companyProfileCards['locale']['title'] }}</h4>
                                    <div class="setup-module-desc">Dil, para birimi, zaman dilimi ve web sitesi bilgisi.</div>
                                </div>
                                <span class="badge badge-green">Hazır</span>
                            </div>
                            <div class="setup-info-list">
                                @foreach($companyProfileCards['locale']['items'] as $item)
                                    <div class="setup-info-row">
                                        <div class="setup-info-key">{{ $item['label'] }}</div>
                                        <div class="setup-info-val">{{ $item['value'] }}</div>
                                    </div>
                                @endforeach
                                <div class="setup-info-row">
                                    <div class="setup-info-key">Ülke / Şehir</div>
                                    <div class="setup-info-val">{{ $companyProfileCards['identity']['items'][5]['value'] }}</div>
                                </div>
                                <div class="setup-info-row">
                                    <div class="setup-info-key">Web sitesi</div>
                                    <div class="setup-info-val">{{ $companyProfileCards['identity']['items'][4]['value'] }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">{{ $companyProfileCards['brand']['title'] }}</h4>
                                    <div class="setup-module-desc">Belge üst alanı ve görsel kimlik bilgileri.</div>
                                </div>
                                <span class="badge badge-gray">Sonraki Faz</span>
                            </div>
                            <div class="setup-info-list">
                                @foreach($companyProfileCards['brand']['items'] as $item)
                                    <div class="setup-info-row">
                                        <div class="setup-info-key">{{ $item['label'] }}</div>
                                        <div class="setup-info-val">{{ $item['value'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="setup-note-box">{{ $companyProfileCards['note'] }}</div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Teslimat Tipleri</h4>
                                    <div class="setup-module-desc">Teklif, sipariş ve teslimat süreçlerinde kullanılacak teslimat tipleri.</div>
                                </div>
                                <span class="badge {{ ($deliveryTypeSummary['active_count'] ?? 0) > 0 ? 'badge-green' : 'badge-amber' }}">{{ ($deliveryTypeSummary['active_count'] ?? 0) > 0 ? 'Hazır' : 'Kontrol Gerekir' }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Aktif tip sayısı</div><div class="setup-info-val">{{ $deliveryTypeSummary['active_count'] ?? 0 }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Varsayılan</div><div class="setup-info-val">{{ $deliveryTypeSummary['default_label'] ?? 'Henüz seçilmedi' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Kullanım alanı</div><div class="setup-info-val">Teklif, sipariş ve teslimat ekranları</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($deliveryTypeSummary['route'])
                                    <a class="setup-action-link primary" href="{{ $deliveryTypeSummary['route'] }}">Teslimat Tiplerini Yönet <span>Yönet</span></a>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'panel-portal' ? 'is-active' : '' }}" data-settings-tab-panel="panel-portal">
                    <div class="setup-card-grid-3">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Panel Adresi</h4>
                                    <div class="setup-module-desc">Panel alt alanı ve giriş adresi.</div>
                                </div>
                                <span class="badge {{ filled($domainSummary['panel_url']) ? 'badge-green' : 'badge-red' }}">{{ filled($domainSummary['panel_url']) ? 'Hazır' : 'Eksik' }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Panel alt alanı</div><div class="setup-info-val">{{ $domainSummary['panel_subdomain'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Panel adresi</div><div class="setup-info-val">{{ $domainSummary['panel_url'] ?: 'Ayar gerekiyor' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Durum</div><div class="setup-info-val">{{ filled($domainSummary['panel_url']) ? 'Aktif' : 'Kontrol Gerekir' }}</div></div>
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Portal Durumu</h4>
                                    <div class="setup-module-desc">Müşteri girişi ve görünür paylaşım bağlantıları.</div>
                                </div>
                                <span class="badge {{ $statusPillMap[$portalSummary[0]['value'] ?? 'Kapalı'] ?? 'badge-gray' }}">{{ $portalSummary[0]['value'] ?? 'Kapalı' }}</span>
                            </div>
                            <div class="setup-note-box" style="margin-top: 0;">Portal ve Paylaşım Linkleri bu kartta müşteri girişi, görünürlük ve takip bağlantılarıyla birlikte özetlenir.</div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Müşteri girişi</div><div class="setup-info-val">{{ $portalSummary[1]['value'] ?? 'Kapalı' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Teklif görünürlüğü</div><div class="setup-info-val">{{ $portalSummary[2]['value'] ?? 'Kapalı' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Sipariş görünürlüğü</div><div class="setup-info-val">{{ $portalSummary[3]['value'] ?? 'Kapalı' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Müşteri Takip Bağlantısı</div><div class="setup-info-val">{{ $domainSummary['portal_url'] ?: 'Sonraki Faz' }}</div></div>
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Özel Domain</h4>
                                    <div class="setup-module-desc">Panel ve portal domain bilgisi.</div>
                                </div>
                                <span class="badge badge-gray">Sonraki Faz</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Özel domain</div><div class="setup-info-val">{{ $domainSummary['custom_domain'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Portal domaini</div><div class="setup-info-val">{{ $domainSummary['portal_domain'] }}</div></div>
                            </div>
                            <div class="setup-note-box">DNS ve SSL otomasyonu sonraki fazda ele alınacaktır.</div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'notifications' ? 'is-active' : '' }}" data-settings-tab-panel="notifications">
                    <div class="setup-card-grid-2">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Bildirim Durumu</h4>
                                    <div class="setup-module-desc">SMTP durumu, gönderen bilgisi ve son test sonucu.</div>
                                </div>
                                <span class="badge {{ $statusPillMap[$notificationSummary['smtp']['status_label']] ?? 'badge-gray' }}">{{ $notificationSummary['smtp']['status_label'] }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">SMTP durumu</div><div class="setup-info-val">{{ $notificationSummary['smtp']['status_label'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Gönderen adı / e-posta</div><div class="setup-info-val">{{ $notificationSummary['smtp']['from_name'] }} / {{ $notificationSummary['smtp']['from_email'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Kullanıcı adı</div><div class="setup-info-val">{{ $notificationSummary['smtp']['username_masked'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Son SMTP testi</div><div class="setup-info-val">{{ $notificationSummary['smtp']['last_test_at'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Son SMTP sonucu</div><div class="setup-info-val">{{ $notificationSummary['smtp']['last_test_status'] }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($notificationSummary['smtp']['route'])
                                    <a class="setup-action-link primary" href="{{ $notificationSummary['smtp']['route'] }}">SMTP Ayarları <span>Yönet</span></a>
                                @endif
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">WhatsApp ve Şablonlar</h4>
                                    <div class="setup-module-desc">WhatsApp telefonu, şablon sayısı ve bildirim geçmişi.</div>
                                </div>
                                <span class="badge {{ $statusPillMap[$notificationSummary['whatsapp']['status_label']] ?? 'badge-gray' }}">{{ $notificationSummary['whatsapp']['status_label'] }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">WhatsApp telefonu</div><div class="setup-info-val">{{ $notificationSummary['whatsapp']['test_phone_masked'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Şablon sayısı</div><div class="setup-info-val">{{ $notificationSummary['templates']['count'] }} kayıt</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Başarısız bildirim</div><div class="setup-info-val">{{ $notificationSummary['logs']['failed_count'] }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($notificationSummary['whatsapp']['route'])
                                    <a class="setup-action-link" href="{{ $notificationSummary['whatsapp']['route'] }}">WhatsApp Ayarları <span>Yönet</span></a>
                                @endif
                                @if($notificationSummary['templates']['route'])
                                    <a class="setup-action-link" href="{{ $notificationSummary['templates']['route'] }}">Bildirim Şablonları <span>Yönet</span></a>
                                @endif
                                @if($notificationSummary['logs']['route'])
                                    <a class="setup-action-link" href="{{ $notificationSummary['logs']['route'] }}">Bildirim Geçmişi <span>Yönet</span></a>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'package-limits' ? 'is-active' : '' }}" data-settings-tab-panel="package-limits">
                    <div class="setup-card-grid-3">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Mevcut Paket</h4>
                                    <div class="setup-module-desc">Kullandığınız paket ve erişim durumu.</div>
                                </div>
                                <span class="badge {{ $statusPillMap[$subscription['label']] ?? 'badge-blue' }}">{{ $subscription['label'] }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Paket</div><div class="setup-info-val">{{ $packageLabel }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Durum</div><div class="setup-info-val">{{ $subscription['label'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Kalan gün</div><div class="setup-info-val">{{ $subscription['days_remaining'] !== null ? $subscription['days_remaining'] : 'Aktif kullanım' }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($packageOverviewRoute)
                                    <a class="setup-action-link primary" href="{{ $packageOverviewRoute }}">Paketim ve Kullanımım <span>Yönet</span></a>
                                @endif
                            </div>
                        </div>

                        @if($canManageProcessDepthSettings)
                            <div class="setup-module-block">
                                <div class="setup-module-head">
                                    <div>
                                        <h4 class="setup-module-title">Süreç Derinliği</h4>
                                        <div class="setup-module-desc">Seçilen çalışma şeklinin teklif, sipariş ve operasyon ekranlarındaki ayrıntı seviyesine etkisini belirler.</div>
                                    </div>
                                    <span class="badge {{ ($processDepthSummary['effective']['source'] ?? 'package_default') === 'tenant_override' ? 'badge-blue' : 'badge-green' }}">{{ $processDepthSummary['effective']['source_label'] ?? 'Paket varsayılanı' }}</span>
                                </div>
                                <div class="setup-info-list">
                                    <div class="setup-info-row"><div class="setup-info-key">Etkin çalışma şekli</div><div class="setup-info-val">{{ $processDepthSummary['effective']['label'] ?? 'Standart Akış' }}</div></div>
                                    <div class="setup-info-row"><div class="setup-info-key">Seçimin kaynağı</div><div class="setup-info-val">{{ $processDepthSummary['effective']['source_label'] ?? 'Paket varsayılanı' }}</div></div>
                                    <div class="setup-info-row"><div class="setup-info-key">Paket varsayılanı</div><div class="setup-info-val">{{ $processDepthSummary['package_default']['label'] ?? 'Standart Akış' }}</div></div>
                                </div>
                                <div class="setup-note-box">{{ $processDepthSummary['manage_note'] ?? 'Bu ayar modül erişimini veya kullanıcı yetkilerini değiştirmez.' }}</div>
                                <div class="setup-note-box">{{ $processDepthSummary['support_note'] ?? 'Seçimin etkisi, Süreç Derinliği desteği eklenen operasyon ekranlarında uygulanır.' }}</div>
                                <div class="setup-link-list">
                                    @if($processDepthSummary['settings_route'])
                                        <a class="setup-action-link primary" href="{{ $processDepthSummary['settings_route'] }}">Ayarı Aç <span>Git</span></a>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Aktif Modüller</h4>
                                    <div class="setup-module-desc">Kategori bazlı modül ve erişim özeti.</div>
                                </div>
                                <span class="badge badge-green">Hazır</span>
                            </div>
                            <div class="setup-module-grid">
                                @foreach($moduleCategories as $category)
                                    <div class="setup-info-list">
                                        <div class="text-sm font-medium text-gray-900">{{ $category['label'] }}</div>
                                        @foreach($category['items'] as $item)
                                            <div class="setup-info-row">
                                                <div class="setup-info-key">{{ $item['label'] }}</div>
                                                <div class="setup-info-val"><span class="badge {{ $statusPillMap[$item['status_label']] ?? 'badge-gray' }}">{{ $item['status_label'] }}</span></div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Kullanım Limitleri</h4>
                                    <div class="setup-module-desc">Kullanıcı, ürün, sipariş, depolama ve özel domain özeti.</div>
                                </div>
                                <span class="badge badge-blue">Bilgi</span>
                            </div>
                            <div class="setup-usage-grid">
                                @foreach($usageSnapshot as $usage)
                                    <div class="setup-status-card">
                                        <div class="setup-status-label">{{ $usage['label'] }}</div>
                                        <div class="setup-status-value">{{ $usage['current'] }} / {{ $usage['limit'] ?? 'Limitsiz' }}</div>
                                        <div><span class="badge {{ $usageBadgeMap[$usage['status']] ?? 'badge-gray' }}">{{ $usageLabelMap[$usage['status']] ?? ucfirst($usage['status']) }}</span></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'users-roles' ? 'is-active' : '' }}" data-settings-tab-panel="users-roles">
                    <div class="setup-card-grid-2">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Kullanıcı Yönetimi</h4>
                                    <div class="setup-module-desc">Ekip kullanıcıları ve temel erişim yapısı.</div>
                                </div>
                                <span class="badge badge-green">Hazır</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Toplam kullanıcı</div><div class="setup-info-val">{{ $userRoleSummary['total_users'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Aktif kullanıcı</div><div class="setup-info-val">{{ $userRoleSummary['active_users'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Panel Yetkilisi</div><div class="setup-info-val">{{ $userRoleSummary['has_panel_owner'] ? 'Hazır' : 'Eksik' }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($userRoleSummary['users_route'])
                                    <a class="setup-action-link primary" href="{{ $userRoleSummary['users_route'] }}">Kullanıcı Yönetimi <span>Yönet</span></a>
                                @endif
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Rol ve Yetkiler</h4>
                                    <div class="setup-module-desc">Rol kurgusu, finans görünürlüğü ve operasyon rol yapısı.</div>
                                </div>
                                <span class="badge badge-blue">Bilgi</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Rol kurgusu</div><div class="setup-info-val">Abone firmada çekirdek roller aktif</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Finans görünürlüğü</div><div class="setup-info-val">{{ $userRoleSummary['has_finance_user'] ? 'Yalnız yetkili kullanıcılar görür' : 'Kontrol Gerekir' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Operasyon rolü</div><div class="setup-info-val">{{ $userRoleSummary['has_operations_user'] ? 'Hazır' : 'Kontrol Gerekir' }}</div></div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'catalog-product-hub' ? 'is-active' : '' }}" data-settings-tab-panel="catalog-product-hub">
                    <div class="setup-card-grid-3">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Katalog Durumu</h4>
                                    <div class="setup-module-desc">Teklif ekranına etki eden katalog görünürlüğü.</div>
                                </div>
                                <span class="badge {{ $statusPillMap[$catalogSummary['status_label']] ?? 'badge-gray' }}">{{ $catalogSummary['status_label'] }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Katalogdaki Ürünler</div><div class="setup-info-val">{{ number_format($catalogSummary['visible_catalog_rows'] ?? 0, 0, ',', '.') }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Teklifte Seçilebilir</div><div class="setup-info-val">{{ number_format($catalogSummary['quote_visible_rows'] ?? 0, 0, ',', '.') }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Kontrol Gereken Ürünler</div><div class="setup-info-val">{{ number_format($catalogSummary['attention_rows'] ?? 0, 0, ',', '.') }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($catalogSummary['catalog_route'])
                                    <a class="setup-action-link" href="{{ $catalogSummary['catalog_route'] }}">Kataloğu Aç <span>Git</span></a>
                                @endif
                                @if($catalogSummary['quote_route'])
                                    <a class="setup-action-link primary" href="{{ $catalogSummary['quote_route'] }}">Ürün Seç ve Teklif Oluştur <span>Git</span></a>
                                @endif
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Product Data Hub Bilgisi</h4>
                                    <div class="setup-module-desc">Tenant-facing sade bilgi ve yönlendirme.</div>
                                </div>
                                <span class="badge badge-blue">Bilgi</span>
                            </div>
                            <div class="setup-note-box" style="margin-top: 0;">{{ $catalogSummary['guidance_note'] }}</div>
                            <div class="setup-note-box">{{ $catalogSummary['technical_note'] }}</div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Tedarikçi Erişim Özeti</h4>
                                    <div class="setup-module-desc">Erişim sayısı ve teklif görünürlüğü bilgisi.</div>
                                </div>
                                <span class="badge {{ $catalogSummary['supplier_access_count'] > 0 ? 'badge-green' : 'badge-amber' }}">{{ $catalogSummary['supplier_access_count'] > 0 ? 'Hazır' : 'Kontrol Gerekir' }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Erişim sayısı</div><div class="setup-info-val">{{ $catalogSummary['supplier_access_count'] }} aktif erişim</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Teklif görünürlüğü</div><div class="setup-info-val">{{ ($catalogSummary['quote_visible_rows'] ?? 0) > 0 ? 'Açık' : 'Kontrol Gerekir' }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($packageRequestSummary['route'])
                                    <a class="setup-action-link" href="{{ $packageRequestSummary['route'] }}">Talep Merkezi <span>Git</span></a>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'request-center' ? 'is-active' : '' }}" data-settings-tab-panel="request-center">
                    <div class="setup-card-grid-2">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Talep Merkezi</h4>
                                    <div class="setup-module-desc">Paket, modül ve hizmet taleplerinin özeti.</div>
                                </div>
                                <span class="badge {{ $packageRequestSummary['open_count'] > 0 ? 'badge-amber' : 'badge-green' }}">{{ $packageRequestSummary['open_count'] > 0 ? 'Kontrol Gerekir' : 'Hazır' }}</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Son talep durumu</div><div class="setup-info-val">{{ $packageRequestSummary['latest'] ? ($packageRequestSummary['status_labels'][$packageRequestSummary['latest']->status] ?? $packageRequestSummary['latest']->status) : 'Henüz talep yok' }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Bekleyen talep</div><div class="setup-info-val">{{ $packageRequestSummary['open_count'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">İstenen son paket / modül</div><div class="setup-info-val">{{ $packageRequestSummary['latest']?->requestedPackage?->name ?? ($packageRequestSummary['latest']?->requested_package_key ?: 'Veri yok') }}</div></div>
                            </div>
                            <div class="setup-link-list">
                                @if($packageRequestSummary['route'])
                                    <a class="setup-action-link primary" href="{{ $packageRequestSummary['route'] }}">Talep Merkezini Aç <span>Git</span></a>
                                @endif
                            </div>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">API / Entegrasyon Talepleri</h4>
                                    <div class="setup-module-desc">Talep edilebilir entegrasyon alanları.</div>
                                </div>
                                <span class="badge badge-gray">Sonraki Faz</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">API erişimi</div><div class="setup-info-val">Talep Merkezi’nden talep edilebilir</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">XML / JSON</div><div class="setup-info-val">Sonraki Faz</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Harici bağlantılar</div><div class="setup-info-val">Bu alan yalnız bilgi amaçlıdır</div></div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="setup-tab-panel {{ $activeSettingsTab === 'file-storage' ? 'is-active' : '' }}" data-settings-tab-panel="file-storage">
                    <div class="setup-card-grid-2">
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Çalışma Klasörü Yapısı</h4>
                                    <div class="setup-module-desc">Sipariş ve iş formu çalışma klasörlerinin güvenli görünümü.</div>
                                </div>
                                <span class="badge badge-green">Hazır</span>
                            </div>
                            <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-2 space-y-4">
                                @csrf
                                <div>
                                    <label for="work_folder_root_name" class="block text-sm font-medium text-gray-700">Çalışma klasörü kök adı</label>
                                    <input id="work_folder_root_name" name="work_folder_root_name" type="text" value="{{ old('work_folder_root_name', $workFolderRootName) }}" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-gray-900">
                                    @error('work_folder_root_name')
                                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                    <p class="mt-2 setup-mini-text">Yeni oluşacak klasörlerde kullanılır. Mevcut klasörler otomatik yeniden adlandırılmaz.</p>
                                </div>
                                <div class="setup-info-list">
                                    <div class="setup-info-row"><div class="setup-info-key">Örnek güvenli display path</div><div class="setup-info-val">{{ $storageSummary['preview_path'] }}</div></div>
                                    <div class="setup-info-row"><div class="setup-info-key">Mevcut depolama</div><div class="setup-info-val">{{ $storageSummary['storage_label'] }}</div></div>
                                </div>
                                <div class="setup-note-box">Fiziksel veya absolute path gösterilmez. Panelde yalnız güvenli display path yapısı kullanılır.</div>
                                <div class="flex justify-end">
                                    <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
                                </div>
                            </form>
                        </div>
                        <div class="setup-module-block">
                            <div class="setup-module-head">
                                <div>
                                    <h4 class="setup-module-title">Depolama Alanı ve Harici Depolama</h4>
                                    <div class="setup-module-desc">Planlanan sağlayıcılar ve güvenli kullanım notları.</div>
                                </div>
                                <span class="badge badge-gray">Sonraki Faz</span>
                            </div>
                            <div class="setup-info-list">
                                <div class="setup-info-row"><div class="setup-info-key">Mevcut depolama</div><div class="setup-info-val">{{ $storageSummary['storage_label'] }}</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Harici depolama</div><div class="setup-info-val">Sonraki Faz</div></div>
                                <div class="setup-info-row"><div class="setup-info-key">Planlanan seçenekler</div><div class="setup-info-val">{{ implode(', ', $storageSummary['planned_providers']) }}</div></div>
                            </div>
                            <div class="setup-note-box">{{ $storageSummary['note'] }}</div>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <aside class="setup-summary-panel">
            <div class="setup-summary-section">
                <div class="setup-summary-title">Kurulum Özeti</div>
                <div class="setup-progress-wrap">
                    <div class="setup-progress-top">
                        <span>Canlıya hazırlık</span>
                        <span>%{{ $settingsOverview['progress_percent'] }}</span>
                    </div>
                    <div class="setup-progress-bar"><span></span></div>
                </div>
                <div class="setup-chip-row mt-3">
                    <span class="badge badge-green">{{ $settingsOverview['ready_count'] }} hazır</span>
                    <span class="badge badge-amber">{{ $settingsOverview['attention_count'] }} kontrol</span>
                    <span class="badge badge-gray">{{ $settingsOverview['later_count'] }} sonraki faz</span>
                </div>
            </div>

            <div class="setup-summary-section">
                <div class="setup-summary-title">Hızlı Geçiş</div>
                <div class="setup-quick-actions">
                    @foreach($settingsOverview['quick_tabs'] as $tab)
                        <button type="button" class="pd-btn {{ $loop->first ? 'pd-btn-primary' : 'pd-btn-light' }} w-full justify-center" data-settings-tab-jump="{{ $tab['tab'] }}">{{ $tab['label'] }}</button>
                    @endforeach
                </div>
            </div>

            <div class="setup-summary-section">
                <div class="setup-summary-title">Kontrol Gerekenler</div>
                <div class="setup-summary-checks">
                    @forelse($settingsOverview['focus_actions'] as $action)
                        <div class="setup-check-item">
                            <span class="setup-check-dot {{ $action['tone'] === 'green' ? 'green' : 'amber' }}"></span>
                            <div>
                                <div>{{ $action['title'] }}</div>
                                <div class="setup-mini-text">{{ $action['description'] }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="setup-note-box" style="margin-top: 0;">Acil eksik görünmüyor.</div>
                    @endforelse
                </div>
            </div>

            <div class="setup-summary-section">
                <div class="setup-summary-title">Hızlı Linkler</div>
                <div class="setup-summary-list">
                    @foreach($settingsOverview['quick_links'] as $link)
                        <a href="{{ $link['url'] }}" class="setup-action-link">{{ $link['label'] }} <span>Git</span></a>
                    @endforeach
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const shell = document.querySelector('[data-settings-shell]');
        const navButtons = Array.from(document.querySelectorAll('[data-settings-tab-trigger]'));
        const jumpButtons = Array.from(document.querySelectorAll('[data-settings-tab-jump]'));
        const panels = Array.from(document.querySelectorAll('[data-settings-tab-panel]'));
        const tabCaption = document.querySelector('[data-settings-tab-caption]');
        const rootInput = document.getElementById('work_folder_root_name');
        const tabDescriptions = @json(collect($settingsTabs)->mapWithKeys(fn ($tab) => [$tab['key'] => $tab['description']])->all());

        if (shell) {
            shell.classList.add('js-settings-tabs');
        }

        const activateTab = (key, updateUrl = true) => {
            navButtons.forEach((button) => {
                const active = button.getAttribute('data-settings-tab-trigger') === key;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panels.forEach((panel) => {
                const active = panel.getAttribute('data-settings-tab-panel') === key;
                panel.classList.toggle('is-active', active);
            });

            if (tabCaption && tabDescriptions[key]) {
                tabCaption.textContent = tabDescriptions[key];
            }

            if (updateUrl && window.history && window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', key);
                window.history.replaceState({}, '', url.toString());
            }
        };

        navButtons.forEach((button) => {
            button.addEventListener('click', () => {
                activateTab(button.getAttribute('data-settings-tab-trigger'));
            });
        });

        jumpButtons.forEach((button) => {
            button.addEventListener('click', () => {
                activateTab(button.getAttribute('data-settings-tab-jump'));
            });
        });

        const activeFromDom = navButtons.find((button) => button.classList.contains('is-active'));
        if (activeFromDom) {
            activateTab(activeFromDom.getAttribute('data-settings-tab-trigger'), false);
        }

        if (!rootInput) {
            return;
        }

        const replacements = {
            'Ç': 'C', 'ç': 'c',
            'Ğ': 'G', 'ğ': 'g',
            'İ': 'I', 'I': 'I', 'ı': 'i',
            'Ö': 'O', 'ö': 'o',
            'Ş': 'S', 'ş': 's',
            'Ü': 'U', 'ü': 'u'
        };

        const normalizeValue = (value) => {
            const prepared = String(value || '').trim();

            if (prepared === '') {
                return 'ISLER';
            }

            const replaced = prepared.replace(/[ÇçĞğİIıÖöŞşÜü]/g, (char) => replacements[char] ?? char);
            const ascii = replaced.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');

            return ascii
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 32)
                .replace(/-+$/g, '') || 'ISLER';
        };

        rootInput.addEventListener('input', () => {
            rootInput.value = rootInput.value;
            rootInput.setAttribute('data-normalized-preview', normalizeValue(rootInput.value));
        });
    }());
</script>
@endpush
