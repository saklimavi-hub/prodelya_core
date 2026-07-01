@extends('layouts.prodelya-admin')

@section('title', 'Başvurular / Paket Talebi Detayı')
@section('page_topbar_hidden', '1')

@section('content')
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
                        <h1 class="pd-hero-title">{{ $packageRequest->tenant?->name ?: 'Paket Talebi' }}</h1>
                        <p class="pd-hero-subtitle">Tenant sonrası ticari talebi Başvurular çatısı altında inceleyin, yönetin ve gerekirse paketi uygulayın.</p>
                    </div>
                    <div class="pd-hero-actions">
                        <a href="{{ route('admin.super.package-requests.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                        @if($canApply)
                            <form method="POST" action="{{ route('admin.super.package-requests.apply', $packageRequest) }}">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-primary">Paketi Uygula</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">2. Paket Talebi Detayı / Uygulama Kararı</h3>
                    <p class="pd-section-subtitle">Tenant, paket geçişi ve talep sahibini daha az ama daha net blok içinde görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-detail-grid">
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Tenant ve Talep Sahibi</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Tenant</span><strong>{{ $packageRequest->tenant?->name ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Tenant Kodu</span><strong>{{ $packageRequest->tenant?->slug ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Talep Eden</span><strong>{{ $packageRequest->requester?->name ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Talep Eden E-posta</span><strong>{{ $packageRequest->requester?->email ?: '-' }}</strong></div>
                        </div>
                    </div>
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Paket Geçişi ve Durum</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Mevcut Paket</span><strong>{{ $packageRequest->currentPackage?->name ?? ($packageRequest->current_package_key ?: '-') }}</strong></div>
                            <div class="pd-detail-row"><span>İstenen Paket</span><strong>{{ $packageRequest->requestedPackage?->name ?? $packageRequest->requested_package_key }}</strong></div>
                            <div class="pd-detail-row"><span>Talep Tarihi</span><strong>{{ optional($packageRequest->created_at)->format('d.m.Y H:i') }}</strong></div>
                            <div class="pd-detail-row"><span>Talep Durumu</span><strong>{{ $statusOptions[$packageRequest->status] ?? $packageRequest->status }}</strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Paket Özeti / Kullanım</h3>
                    <p class="pd-section-subtitle">Mevcut tenant kullanım yoğunluğu ve paket kararı için temel sinyaller.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    @forelse($usageSummary as $usage)
                        <div class="pd-card">
                            <div class="pd-card-body">
                                <div class="pd-label">{{ $usage['label'] ?? ($usage['key'] ?? '-') }}</div>
                                <div class="text-lg font-semibold text-gray-900 mt-2">{{ $usage['used_label'] ?? ($usage['used'] ?? 0) }} / {{ $usage['limit_label'] ?? ($usage['limit'] ?? '-') }}</div>
                                <div class="text-sm text-gray-500 mt-1">Kullanım: {{ $usage['status_label'] ?? '-' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="pd-empty-card">
                            <div class="text-sm text-gray-500">Kullanım özeti yok.</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Talep Notu</h3>
                    <p class="pd-section-subtitle">Tenant yöneticisinin paket değişim gerekçesi ve Super Admin operasyon notu.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Talep Açıklaması</div>
                            <div class="text-sm text-gray-700">{{ $packageRequest->request_note ?: 'Açıklama girilmemiş.' }}</div>
                        </div>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Super Admin Notu</div>
                            <div class="text-sm text-gray-700">{{ $packageRequest->admin_note ?: 'Henüz operasyon notu eklenmedi.' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Talep Zaman Çizgisi</h3>
                    <p class="pd-section-subtitle">Talebin açılma, onay ve uygulama akışını tek ritimde görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-timeline-list">
                    @foreach($timeline as $item)
                        <div class="pd-timeline-item">
                            <div class="flex items-center justify-between gap-2">
                                <div class="pd-timeline-item-title">{{ $item['title'] }}</div>
                                <span class="pd-badge {{ match($item['tone']) {
                                    'green' => 'pd-badge-green',
                                    'amber' => 'pd-badge-amber',
                                    'red' => 'pd-badge-red',
                                    'gray' => 'pd-badge-gray',
                                    default => 'pd-badge-blue',
                                } }}">{{ $item['at'] ?? $item['tone'] }}</span>
                            </div>
                            <div class="pd-timeline-item-copy">{{ $item['description'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Durum Yönetimi</h3>
                    <p class="pd-section-subtitle">Talep durumunu güncelleyin; onay ve uygulama akışı aynı karar dilinde ilerlesin.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="POST" action="{{ route('admin.super.package-requests.status.update', $packageRequest) }}" class="pd-grid pd-grid-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="status" class="pd-label">Talep Durumu</label>
                        <select id="status" name="status" class="pd-input">
                            @foreach($statusOptions as $key => $label)
                                <option value="{{ $key }}" @selected(old('status', $packageRequest->status) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pd-span-full">
                        <label for="admin_note" class="pd-label">Super Admin Notu</label>
                        <textarea id="admin_note" name="admin_note" rows="4" class="pd-input">{{ old('admin_note', $packageRequest->admin_note) }}</textarea>
                    </div>
                    <div class="pd-request-hub-form-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Durumu Güncelle</button>
                    </div>
                    <div class="flex items-end">
                        <span class="text-sm text-gray-600">
                            @if($canApply)
                                Talep onaylandı; istenen paketi tenant üstüne güvenli şekilde uygulayabilirsiniz.
                            @else
                                Paket uygulama butonu yalnız onaylanan taleplerde açılır.
                            @endif
                        </span>
                    </div>
                </form>
                @if($canApply)
                    <form method="POST" action="{{ route('admin.super.package-requests.apply', $packageRequest) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="pd-btn pd-btn-success">Paketi Uygula</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Paket Karar Paneli</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Talep Durumu</span>
                        <span class="pd-badge {{ match($packageRequest->status) {
                            'completed' => 'pd-badge-green',
                            'approved' => 'pd-badge-blue',
                            'rejected' => 'pd-badge-gray',
                            default => 'pd-badge-amber',
                        } }}">{{ $decisionSummary['status_label'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Tenant Uygunluğu</span>
                        <span class="pd-badge {{ $decisionSummary['tenant_ready'] ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ $decisionSummary['tenant_ready'] ? 'Hazır' : 'Kontrol Gerekir' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Mevcut Paket</span>
                        <span class="font-medium">{{ $decisionSummary['current_package_label'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>İstenen Paket</span>
                        <span class="font-medium">{{ $decisionSummary['requested_package_label'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Kullanım Alanı</span>
                        <span class="font-medium">{{ $decisionSummary['usage_count'] }} sinyal</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Hızlı İşlemler</h3>
                <div class="pd-summary-action-list">
                    <form method="POST" action="{{ route('admin.super.package-requests.status.update', $packageRequest) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" class="pd-summary-action pd-summary-action-button" @disabled($packageRequest->status === \App\Models\TenantPackageUpgradeRequest::STATUS_COMPLETED)>
                            <span>Onayla</span>
                            <span class="pd-badge pd-badge-blue">approved</span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.super.package-requests.status.update', $packageRequest) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="rejected">
                        <button type="submit" class="pd-summary-action pd-summary-action-button" @disabled($packageRequest->status === \App\Models\TenantPackageUpgradeRequest::STATUS_COMPLETED)>
                            <span>Reddet</span>
                            <span class="pd-badge pd-badge-gray">rejected</span>
                        </button>
                    </form>
                    @if($canApply)
                        <form method="POST" action="{{ route('admin.super.package-requests.apply', $packageRequest) }}">
                            @csrf
                            <button type="submit" class="pd-summary-action pd-summary-action-button">
                                <span>Paketi Uygula</span>
                                <span class="pd-badge pd-badge-green">apply</span>
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Uygulanacak Bilgiler</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Tenant</span>
                        <span class="font-medium">{{ $packageRequest->tenant?->name ?: '-' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Geçiş</span>
                        <span class="font-medium">{{ $decisionSummary['current_package_label'] }} → {{ $decisionSummary['requested_package_label'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Talep Eden</span>
                        <span class="font-medium">{{ $packageRequest->requester?->name ?: '-' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Zaman Çizgisi / Log</h3>
                <div class="pd-timeline-list">
                    @foreach($timeline as $item)
                        <div class="pd-timeline-item">
                            <div class="flex items-center justify-between gap-2">
                                <div class="pd-timeline-item-title">{{ $item['title'] }}</div>
                                <span class="pd-badge {{ match($item['tone']) {
                                    'green' => 'pd-badge-green',
                                    'amber' => 'pd-badge-amber',
                                    'red' => 'pd-badge-red',
                                    'gray' => 'pd-badge-gray',
                                    default => 'pd-badge-blue',
                                } }}">{{ $item['at'] ?? $item['tone'] }}</span>
                            </div>
                            <div class="pd-timeline-item-copy">{{ $item['description'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Kural Notu</h3>
                <div class="pd-note">
                    <p class="text-sm text-gray-600">
                        Paket talebi veri modeli bağımsız kalır; gerçek paket güncellemesi yalnız onay sonrası mevcut tenant package akışıyla yapılır.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
