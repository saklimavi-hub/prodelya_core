@extends('layouts.prodelya-admin')

@section('title', 'Başvuru Detayı')
@section('page_topbar_hidden', '1')

@section('content')
@php($cta = $readiness['cta'] ?? ['state' => 'ready', 'label' => 'Abone Firmaya Dönüştür', 'enabled' => true, 'reasons' => []])
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
    @if(session('success'))
        <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="pd-alert pd-alert-danger">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">{{ $requestItem->company_name }}</h1>
                    <p class="pd-hero-subtitle">{{ $typeOptions[$requestItem->request_type] ?? $requestItem->request_type }} kaydı, satış öncesi tercih bilgileri ve dönüşüm hazırlığı.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.signup-requests.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    @if(($cta['state'] ?? null) === 'converted' && $requestItem->convertedTenant)
                        <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-btn pd-btn-primary">Abone Firma Aç</a>
                    @elseif(($cta['enabled'] ?? false) === true)
                        <a href="{{ route('admin.super.signup-requests.conversion-preview', $requestItem) }}" class="pd-btn pd-btn-primary">{{ $cta['label'] }}</a>
                    @else
                        <span class="pd-btn pd-btn-light" aria-disabled="true">{{ $cta['label'] }}</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    @include('super-admin.partials.request-hub-tabs')

    @if($requestItem->convertedTenant)
        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-body">
                <div class="pd-alert pd-alert-success">
                    Bu başvuru Abone Firma’ya dönüştürüldü.
                    <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="font-medium underline">Oluşturulan Abone Firma kaydını aç</a>
                    <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-btn pd-btn-primary ml-3">Abone Firma Detayını Aç</a>
                    <a href="{{ route('admin.super.signup-requests.conversion-success', $requestItem) }}" class="pd-btn pd-btn-light ml-3">Dönüşüm Özeti</a>
                </div>
                <div class="pd-grid pd-grid-3 mt-4">
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Abone Firma</div>
                        <div class="pd-tenant-info-value">{{ $requestItem->convertedTenant->name }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Panel Adresi</div>
                        <div class="pd-tenant-info-value">{{ $requestItem->convertedTenant->panel_subdomain ?: 'Belirtilmedi' }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Dönüşüm Tarihi</div>
                        <div class="pd-tenant-info-value">{{ data_get($requestItem->meta_json, 'converted_at', 'Belirtilmedi') }}</div>
                    </div>
                </div>
            </div>
        </section>
    @elseif(in_array($requestItem->status, ['rejected', 'archived'], true))
        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-body">
                <div class="pd-alert pd-alert-warning">
                    Bu başvuru {{ $statusOptions[$requestItem->status] ?? $requestItem->status }} durumunda. Dönüştürmeden önce durumu gözden geçirmeniz gerekir.
                </div>
            </div>
        </section>
    @endif

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">2. Başvuru Detayı / Dönüştürmeye Hazırlık</h3>
                <p class="pd-section-subtitle">Firma, iletişim ve talep kapsamını daha az ama daha net blok içinde görün.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-detail-grid">
                <div class="pd-detail-card">
                    <div class="pd-detail-card-title">Firma ve İletişim</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Firma</span><strong>{{ $requestItem->company_name }}</strong></div>
                            <div class="pd-detail-row"><span>Firma Yetkilisi</span><strong>{{ $requestItem->contact_name }}</strong></div>
                            <div class="pd-detail-row"><span>Telefon</span><strong>{{ $requestItem->phone }}</strong></div>
                            <div class="pd-detail-row"><span>E-posta</span><strong>{{ $requestItem->email }}</strong></div>
                            <div class="pd-detail-row"><span>Şehir</span><strong>{{ $requestItem->city ?: 'Belirtilmedi' }}</strong></div>
                            <div class="pd-detail-row"><span>Sektör / İş Tipi</span><strong>{{ $requestItem->sector ?: 'Belirtilmedi' }}</strong></div>
                        </div>
                </div>
                <div class="pd-detail-card">
                    <div class="pd-detail-card-title">Talep ve Operasyon</div>
                    <div class="pd-detail-list">
                        <div class="pd-detail-row"><span>İstek Tipi</span><strong>{{ $typeOptions[$requestItem->request_type] ?? $requestItem->request_type }}</strong></div>
                        <div class="pd-detail-row"><span>Durum</span><strong>{{ $statusOptions[$requestItem->status] ?? $requestItem->status }}</strong></div>
                        <div class="pd-detail-row"><span>Tercih Edilen Paket</span><strong>{{ $requestItem->requestedPackage?->name ?: ($requestItem->requested_package_key ?: 'Seçilmedi') }}</strong></div>
                        <div class="pd-detail-row"><span>Kaynak</span><strong>{{ $requestItem->source ?: 'public_landing' }}</strong></div>
                        <div class="pd-detail-row"><span>Beklenen Kullanıcı</span><strong>{{ $requestItem->expected_user_count ?: 'Belirtilmedi' }}</strong></div>
                        <div class="pd-detail-row"><span>Oluşturulma Tarihi</span><strong>{{ optional($requestItem->created_at)->format('d.m.Y H:i') ?: 'Takip edilmiyor' }}</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tercihler ve Notlar</h3>
                <p class="pd-section-subtitle">Paket, modül, demo konusu ve dönüşüm sırasında bilgi olarak taşınacak tercih detayları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-2">
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-label">Demo Konusu</div>
                        <div class="text-sm text-gray-700">{{ $requestItem->demo_topic ?: 'Demo konusu yok' }}</div>
                    </div>
                </div>
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-label">Dönüştürülen Abone Firma</div>
                        <div class="text-sm text-gray-700">
                            @if($requestItem->convertedTenant)
                                <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="text-blue-700 hover:underline">{{ $requestItem->convertedTenant->name }}</a>
                            @else
                                Henüz dönüştürülmedi
                            @endif
                        </div>
                    </div>
                </div>
                <div class="pd-card pd-span-full">
                    <div class="pd-card-body">
                        <div class="pd-label">Tercih Edilen Modüller</div>
                        <div class="flex flex-wrap gap-2">
                            @forelse(($readiness['requested_modules_summary'] ?? []) as $moduleKey)
                                <span class="pd-badge pd-badge-blue">{{ $moduleKey }}</span>
                            @empty
                                <span class="text-sm text-gray-600">Modül tercihi yok.</span>
                            @endforelse
                        </div>
                        <p class="text-sm text-gray-500 mt-2">Bu modüller başvuru tercihi olarak taşınır. Paket/modül erişimi Abone Firma ayarlarında ayrıca yönetilir.</p>
                    </div>
                </div>
                <div class="pd-card pd-span-full">
                    <div class="pd-card-body">
                        <div class="pd-label">Not</div>
                        <div class="text-sm text-gray-700">{{ $requestItem->note ?: 'Not yok.' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Operasyon Notları</h3>
                <p class="pd-section-subtitle">Başvuru görüşmeleri ve karar öncesi kısa operasyon notlarını kaydedin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.signup-requests.notes.store', $requestItem) }}" class="pd-grid pd-grid-3">
                @csrf
                <div class="pd-span-full">
                    <label class="pd-label" for="operator_note">Operasyon Notu Ekle</label>
                    <textarea id="operator_note" name="note" class="pd-input" rows="3" maxlength="1000" placeholder="Telefonla görüşüldü, paket beklentisi netleşti, trial kararı bekleniyor...">{{ old('note') }}</textarea>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="pd-btn pd-btn-primary">Notu Kaydet</button>
                </div>
                <div class="flex items-end">
                    <span class="text-sm text-gray-600">Notlar yalnız Super Admin tarafından görülebilir ve güvenli şekilde escape edilerek gösterilir.</span>
                </div>
            </form>

            <div class="pd-timeline-list mt-4">
                @forelse($operatorNotes as $note)
                    <div class="pd-timeline-item">
                        <div class="flex items-center justify-between gap-2">
                            <div class="pd-timeline-item-title">{{ $note['user_name'] ?? 'Super Admin' }}</div>
                            <span class="pd-badge pd-badge-blue">{{ $note['at'] ?? '-' }}</span>
                        </div>
                        <div class="pd-timeline-item-copy">{{ $note['note'] }}</div>
                    </div>
                @empty
                    <div class="pd-empty-card">
                        <div class="text-sm text-gray-500">Henüz operasyon notu eklenmedi.</div>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Dönüşüm Hazırlığı</h3>
                <p class="pd-section-subtitle">Abone Firma oluşturmadan önce duplicate, paket, panel adresi ve firma yetkilisi risklerini tek yerde görün.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-2">
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-label">Firma adı kontrolü</div>
                        <div class="mt-2">
                            <span class="pd-badge {{ !empty($readiness['duplicate_company_matches']) ? 'pd-badge-amber' : 'pd-badge-green' }}">{{ !empty($readiness['duplicate_company_matches']) ? 'Uyarı' : 'Hazır' }}</span>
                        </div>
                        <div class="text-sm text-gray-500 mt-2">
                            @if(!empty($readiness['duplicate_company_matches']))
                                Benzer adla kayıtlı Abone Firmalar bulundu.
                            @else
                                Aynı veya normalize edilmiş yakın isimde Abone Firma bulunmadı.
                            @endif
                        </div>
                        @if(!empty($readiness['duplicate_company_matches']))
                            <div class="flex flex-wrap gap-2 mt-3">
                                @foreach($readiness['duplicate_company_matches'] as $match)
                                    <span class="pd-badge pd-badge-amber">{{ $match['name'] }} / {{ $match['panel_subdomain'] }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-label">Firma yetkilisi e-posta kontrolü</div>
                        <div class="mt-2">
                            <span class="pd-badge {{ match($readiness['owner_email_status']['status'] ?? 'ready') {
                                'conflict', 'missing' => 'pd-badge-red',
                                'warning' => 'pd-badge-amber',
                                default => 'pd-badge-green',
                            } }}">{{ $readiness['owner_email_status']['label'] ?? 'Hazır' }}</span>
                        </div>
                        <div class="text-sm text-gray-500 mt-2">{{ $readiness['owner_email_status']['message'] ?? 'Hazır' }}</div>
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-label">Panel adresi önerisi</div>
                        <div class="mt-2">
                            <span class="pd-badge {{ !empty($readiness['duplicate_subdomain_matches']) ? 'pd-badge-red' : 'pd-badge-green' }}">{{ !empty($readiness['duplicate_subdomain_matches']) ? 'Çakışma' : 'Hazır' }}</span>
                        </div>
                        <div class="text-sm text-gray-700 mt-2">{{ $readiness['suggested_panel_subdomain'] ?: 'Üretilemedi' }}</div>
                        <div class="text-sm text-gray-500 mt-2">
                            @if(!empty($readiness['duplicate_subdomain_matches']))
                                Aynı panel adresi başka bir Abone Firma tarafından kullanılıyor.
                            @else
                                Önerilen panel adresi create ekranına güvenli prefill olarak taşınabilir.
                            @endif
                        </div>
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-label">Paket durumu</div>
                        <div class="mt-2">
                            <span class="pd-badge {{ match($readiness['package_status']['status'] ?? 'ready') {
                                'missing', 'conflict' => 'pd-badge-red',
                                'warning' => 'pd-badge-amber',
                                default => 'pd-badge-green',
                            } }}">{{ $readiness['package_status']['label'] ?? 'Hazır' }}</span>
                        </div>
                        <div class="text-sm text-gray-700 mt-2">{{ $readiness['package_status']['package_name'] ?? 'Belirtilmedi' }}</div>
                        <div class="text-sm text-gray-500 mt-2">{{ $readiness['package_status']['message'] ?? '' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Veri Taşıma Haritası</h3>
                <p class="pd-section-subtitle">Public başvurudan create formuna hangi alanların nasıl taşındığını görün.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Başvuru Alanı</th>
                            <th>Create Hedefi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($prefillFieldMap as $row)
                            <tr>
                                <td>{{ $row['source'] }}</td>
                                <td>{{ $row['target'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Aksiyonlar</h3>
                <p class="pd-section-subtitle">Başvuru durumunu hızlıca güncelleyin veya güvenli create akışıyla Abone Firma’ya dönüştürün.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="flex flex-wrap gap-2 mb-4">
                <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="contacted">
                    <button type="submit" class="pd-btn pd-btn-light" @disabled($requestItem->status === 'converted')>İletişime Geçildi Yap</button>
                </form>

                <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="rejected">
                    <button type="submit" class="pd-btn pd-btn-light" @disabled($requestItem->status === 'converted')>Reddet</button>
                </form>

                <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="archived">
                    <button type="submit" class="pd-btn pd-btn-light" @disabled($requestItem->status === 'converted')>Arşivle</button>
                </form>

                @if($canConvert)
                    <a href="{{ route('admin.super.signup-requests.conversion-preview', $requestItem) }}" class="pd-btn pd-btn-primary">Abone Firmaya Dönüştür</a>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}" class="pd-grid pd-grid-3">
                @csrf
                @method('PATCH')
                <div>
                    <label class="pd-label" for="status">Durum</label>
                    <select id="status" name="status" class="pd-input">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($requestItem->status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="pd-btn pd-btn-primary">Durumu Güncelle</button>
                </div>
                <div class="flex items-end">
                    @if($requestItem->convertedTenant)
                        <span class="text-sm text-gray-600">Bu kayıt dönüştürüldüğü için yeniden dönüştürme aksiyonu kapatıldı.</span>
                    @else
                        <span class="text-sm text-gray-600">Dönüştürme, mevcut tenant create/onboarding akışına güvenli prefill ile yapılır.</span>
                    @endif
                </div>
            </form>
        </div>
    </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Başvuru Durumu</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Başvuru</span>
                        <span class="pd-badge {{ match($requestItem->status) {
                            'converted' => 'pd-badge-green',
                            'contacted' => 'pd-badge-amber',
                            'rejected' => 'pd-badge-red',
                            'archived' => 'pd-badge-gray',
                            default => 'pd-badge-blue',
                        } }}">{{ $statusOptions[$requestItem->status] ?? $requestItem->status }}</span>
                    </div>
                <div class="pd-summary-row">
                    <span>Uygunluk</span>
                    <span class="pd-badge {{ match($readiness['severity'] ?? 'ready') {
                        'blocker' => 'pd-badge-red',
                        'warning' => 'pd-badge-amber',
                        default => 'pd-badge-green',
                    } }}">{{ ($readiness['can_convert'] ?? false) ? 'Dönüştürülebilir' : 'Dönüştürülemez' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Paket</span>
                    <span class="font-medium">{{ $readiness['package_status']['package_name'] ?? ($requestItem->requestedPackage?->name ?: ($requestItem->requested_package_key ?: 'Belirtilmedi')) }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Modül Tercihi</span>
                    <span class="font-medium">{{ count($readiness['requested_modules_summary'] ?? []) }} seçim</span>
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-body">
            <h3 class="pd-summary-title">Dönüşüm Hazırlığı</h3>
            <div class="pd-summary-action-list">
                <div class="pd-summary-row">
                    <span>Firma adı kontrolü</span>
                    <span class="pd-badge {{ !empty($readiness['duplicate_company_matches']) ? 'pd-badge-amber' : 'pd-badge-green' }}">{{ !empty($readiness['duplicate_company_matches']) ? 'Uyarı' : 'Hazır' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Firma yetkilisi e-postası</span>
                    <span class="pd-badge {{ match($readiness['owner_email_status']['status'] ?? 'ready') {
                        'conflict', 'missing' => 'pd-badge-red',
                        'warning' => 'pd-badge-amber',
                        default => 'pd-badge-green',
                    } }}">{{ $readiness['owner_email_status']['label'] ?? 'Hazır' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Panel adresi</span>
                    <span class="pd-badge {{ !empty($readiness['duplicate_subdomain_matches']) ? 'pd-badge-red' : 'pd-badge-green' }}">{{ !empty($readiness['duplicate_subdomain_matches']) ? 'Çakışma' : 'Hazır' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Paket durumu</span>
                    <span class="pd-badge {{ match($readiness['package_status']['status'] ?? 'ready') {
                        'missing', 'conflict' => 'pd-badge-red',
                        'warning' => 'pd-badge-amber',
                        default => 'pd-badge-green',
                    } }}">{{ $readiness['package_status']['label'] ?? 'Hazır' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Panel adresi önerisi</span>
                    <span class="font-medium">{{ $readiness['suggested_panel_subdomain'] ?: 'Üretilemedi' }}</span>
                </div>
                @if(($readiness['request_type_label'] ?? null) === '1 Ay Ücretsiz Dene')
                    <div class="pd-summary-row">
                        <span>Deneme önerisi</span>
                        <span class="font-medium">{{ $readiness['trial_days'] ?? 30 }} gün</span>
                    </div>
                @elseif(filled($requestItem->demo_topic))
                    <div class="pd-summary-row">
                        <span>Demo notu</span>
                        <span class="font-medium">{{ $requestItem->demo_topic }}</span>
                    </div>
                @endif
            </div>

            <div class="pd-summary-action-list" style="margin-top:14px;">
                    @if(($cta['state'] ?? null) === 'converted' && $requestItem->convertedTenant)
                        <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-summary-action">
                            <span>Abone Firma Aç</span>
                            <span class="pd-badge pd-badge-green">open</span>
                        </a>
                        <a href="{{ route('admin.super.signup-requests.conversion-success', $requestItem) }}" class="pd-summary-action">
                            <span>Dönüşüm Özeti</span>
                            <span class="pd-badge pd-badge-blue">success</span>
                        </a>
                    @elseif(($cta['enabled'] ?? false) === true)
                    <a href="{{ route('admin.super.signup-requests.conversion-preview', $requestItem) }}" class="pd-summary-action">
                        <span>{{ $cta['label'] }}</span>
                        <span class="pd-badge {{ ($cta['state'] ?? 'ready') === 'warning' ? 'pd-badge-amber' : 'pd-badge-green' }}">create</span>
                    </a>
                @else
                    <div class="pd-summary-action">
                        <span>{{ $cta['label'] }}</span>
                        <span class="pd-badge pd-badge-red">blocked</span>
                    </div>
                @endif
            </div>

            @if(!empty($readiness['blockers']) || !empty($readiness['warnings']) || !empty($readiness['conversion_notes']))
                <div class="pd-summary-info" style="margin-top:14px;">
                    @foreach($readiness['blockers'] ?? [] as $item)
                        <div class="pd-summary-row">
                            <span>{{ $item }}</span>
                            <span class="pd-badge pd-badge-red">Çakışma</span>
                        </div>
                    @endforeach
                    @foreach($readiness['warnings'] ?? [] as $item)
                        <div class="pd-summary-row">
                            <span>{{ $item }}</span>
                            <span class="pd-badge pd-badge-amber">Uyarı</span>
                        </div>
                    @endforeach
                    @foreach($readiness['conversion_notes'] ?? [] as $item)
                        <div class="pd-summary-row">
                            <span>{{ $item }}</span>
                            <span class="pd-badge pd-badge-blue">Bilgi</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-body">
            <h3 class="pd-summary-title">Hızlı İşlemler</h3>
            <div class="pd-summary-action-list">
                <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="contacted">
                    <button type="submit" class="pd-summary-action pd-summary-action-button" @disabled($requestItem->status === 'converted')>
                        <span>İletişime Geçildi Yap</span>
                        <span class="pd-badge pd-badge-amber">contacted</span>
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="archived">
                    <button type="submit" class="pd-summary-action pd-summary-action-button" @disabled($requestItem->status === 'converted')>
                        <span>Arşivle</span>
                        <span class="pd-badge pd-badge-gray">archived</span>
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.super.signup-requests.status.update', $requestItem) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="rejected">
                    <button type="submit" class="pd-summary-action pd-summary-action-button" @disabled($requestItem->status === 'converted')>
                        <span>Reddet</span>
                        <span class="pd-badge pd-badge-red">rejected</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-body">
            <h3 class="pd-summary-title">Taşınacak Bilgiler</h3>
            <div class="pd-summary-info">
                @foreach($transferSummary as $row)
                        <div class="pd-summary-row">
                            <span>{{ $row['label'] }}</span>
                            <span class="pd-badge {{ match($row['status']) {
                                'Var', 'Korunur' => 'pd-badge-green',
                                'Metadata' => 'pd-badge-blue',
                                default => 'pd-badge-gray',
                            } }}">{{ $row['status'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Dönüşüm Logu</h3>
                <div class="pd-timeline-list">
                    @foreach($activityTimeline as $item)
                        <div class="pd-timeline-item">
                            <div class="flex items-center justify-between gap-2">
                                <div class="pd-timeline-item-title">{{ $item['title'] }}</div>
                                <span class="pd-badge {{ match($item['tone']) {
                                    'green' => 'pd-badge-green',
                                    'amber' => 'pd-badge-amber',
                                    default => 'pd-badge-blue',
                                } }}">{{ $item['at'] ?? $item['tone'] }}</span>
                            </div>
                            <div class="pd-timeline-item-copy">{{ $item['description'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
