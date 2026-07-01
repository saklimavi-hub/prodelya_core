@extends('layouts.prodelya-admin')

@section('title', 'Başvurular / Abone Firma Talep Detayı')
@section('page_topbar_hidden', '1')

@php
    $statusClass = match($request->status) {
        'approved', 'applied' => 'pd-badge-green',
        'pending' => 'pd-badge-amber',
        'in_review' => 'pd-badge-blue',
        default => 'pd-badge-gray',
    };

    $timelineToneClass = fn ($tone) => match ($tone) {
        'green' => 'pd-badge-green',
        'amber' => 'pd-badge-amber',
        'red' => 'pd-badge-red',
        'gray' => 'pd-badge-gray',
        default => 'pd-badge-blue',
    };
@endphp

@section('content')
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
        @if(session('success'))
            <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="pd-alert pd-alert-danger">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="pd-alert pd-alert-danger">{{ $errors->first() }}</div>
        @endif

        <section class="pd-hero-card">
            <div class="pd-card-body">
                <div class="pd-hero-main">
                    <div class="pd-hero-copy">
                        <h1 class="pd-hero-title">{{ $request->tenantAccount?->name ?: 'Abone Firma Talebi' }}</h1>
                        <p class="pd-hero-subtitle">Talebi mevcut durum, etki özeti ve güvenli karar aksiyonlarıyla birlikte yönetin.</p>
                    </div>
                    <div class="pd-hero-actions">
                        <a href="{{ route('admin.super.upgrade-requests.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Talep Özeti</h3>
                    <p class="pd-section-subtitle">Talep tipi, durum, Abone Firma ve istek sahibi bilgilerini kısa blokta görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-detail-grid">
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Talep Bilgisi</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Talep Tipi</span><strong>{{ $request->requestTypeLabel() }}</strong></div>
                            <div class="pd-detail-row"><span>Durum</span><strong>{{ $request->statusLabel() }}</strong></div>
                            <div class="pd-detail-row"><span>Talep Tarihi</span><strong>{{ optional($request->created_at)->format('d.m.Y H:i') ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Son İşlem</span><strong>{{ optional($request->reviewed_at ?? $request->updated_at)->format('d.m.Y H:i') ?: '-' }}</strong></div>
                        </div>
                    </div>
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Abone Firma ve Talep Eden</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Abone Firma</span><strong>{{ $request->tenantAccount?->name ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Abone Firma Kodu</span><strong>{{ $request->tenantAccount?->slug ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Talep Eden</span><strong>{{ $request->requestedBy?->name ?: '-' }}</strong></div>
                            <div class="pd-detail-row"><span>E-posta</span><strong>{{ $request->requestedBy?->email ?: '-' }}</strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Mevcut Durum</h3>
                    <p class="pd-section-subtitle">Talep tipine göre mevcut paket, modül, limit veya tedarikçi erişim bilgilerini görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-detail-list">
                            @foreach($currentState['rows'] as $row)
                                <div class="pd-detail-row">
                                    <span>{{ $row['label'] }}</span>
                                    <strong>{{ $row['value'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Risk / Etki Özeti</h3>
                    <p class="pd-section-subtitle">Bu fazda yalnız bilgilendirici etki gösterilir; gerçek uygulama sonraki fazda açılır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Muhtemel Etkiler</div>
                            <div class="pd-timeline-list">
                                @forelse($impactPreview['impacts'] as $impact)
                                    <div class="pd-timeline-item">
                                        <div class="pd-timeline-item-copy">{{ $impact }}</div>
                                    </div>
                                @empty
                                    <div class="text-sm text-gray-500">Bu talep tipi için ek etki özeti bulunamadı.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Risk / Uyarılar</div>
                            <div class="pd-timeline-list">
                                @forelse($impactPreview['warnings'] as $warning)
                                    <div class="pd-timeline-item">
                                        <div class="pd-timeline-item-copy">{{ $warning }}</div>
                                    </div>
                                @empty
                                    <div class="text-sm text-gray-500">Şu anda ek çakışma veya geçersizlik sinyali görünmüyor.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Talep Notları</h3>
                    <p class="pd-section-subtitle">Tenant notu ve Super Admin operasyon notunu aynı blokta takip edin.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Talep Notu</div>
                            <div class="text-sm text-gray-700">{{ $request->requested_note ?: 'Talep notu girilmedi.' }}</div>
                        </div>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Admin Notu</div>
                            <div class="text-sm text-gray-700">{{ $request->admin_note ?: 'Henüz operasyon notu eklenmedi.' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if(is_array($applySummary))
            <section class="pd-section-card pd-section-card-soft-slate">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Uygulama Özeti</h3>
                        <p class="pd-section-subtitle">Talep uygulandıktan sonra kayda yazılan özet bilgileri görün.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-detail-list">
                                @foreach($applySummary as $key => $value)
                                    @continue(is_array($value) || $value === null || $value === '')
                                    <div class="pd-detail-row">
                                        <span>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) $key)) }}</span>
                                        <strong>{{ is_bool($value) ? ($value ? 'Evet' : 'Hayır') : $value }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Talep Zaman Çizgisi</h3>
                    <p class="pd-section-subtitle">Talebin oluşturulma, inceleme, onay ve kapatma ritmini görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-timeline-list">
                    @foreach($timeline as $item)
                        <div class="pd-timeline-item">
                            <div class="flex items-center justify-between gap-2">
                                <div class="pd-timeline-item-title">{{ $item['title'] }}</div>
                                <span class="pd-badge {{ $timelineToneClass($item['tone']) }}">{{ $item['at'] ?? $item['tone'] }}</span>
                            </div>
                            <div class="pd-timeline-item-copy">{{ $item['description'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Karar Paneli</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Durum</span>
                        <span class="pd-badge {{ $statusClass }}">{{ $request->statusLabel() }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Talep Tipi</span>
                        <span class="font-medium">{{ $request->requestTypeLabel() }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Abone Firma</span>
                        <span class="font-medium">{{ $request->tenantAccount?->name ?: '-' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Durum Yönetimi</h3>
                @if($actionAvailability['apply_waiting_note'] !== '')
                    <div class="pd-note mb-4">
                        <p class="text-sm text-gray-600">{{ $actionAvailability['apply_waiting_note'] }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.super.upgrade-requests.in-review', $request) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label for="admin_note" class="pd-label">Admin Notu</label>
                        <textarea id="admin_note" name="admin_note" rows="5" class="pd-input">{{ old('admin_note', $request->admin_note) }}</textarea>
                    </div>
                    <div class="pd-summary-action-list">
                        <button type="submit" formaction="{{ route('admin.super.upgrade-requests.in-review', $request) }}" class="pd-summary-action pd-summary-action-button" @disabled(!$actionAvailability['can_in_review'])>
                            <span>İncelemeye Al</span>
                            <span class="pd-badge pd-badge-blue">review</span>
                        </button>
                        <button type="submit" formaction="{{ route('admin.super.upgrade-requests.approve', $request) }}" class="pd-summary-action pd-summary-action-button" @disabled(!$actionAvailability['can_approve'])>
                            <span>Onayla</span>
                            <span class="pd-badge pd-badge-green">approve</span>
                        </button>
                        <button type="submit" formaction="{{ route('admin.super.upgrade-requests.reject', $request) }}" class="pd-summary-action pd-summary-action-button" @disabled(!$actionAvailability['can_reject'])>
                            <span>Reddet</span>
                            <span class="pd-badge pd-badge-gray">reject</span>
                        </button>
                        <button type="submit" formaction="{{ route('admin.super.upgrade-requests.cancel', $request) }}" class="pd-summary-action pd-summary-action-button" @disabled(!$actionAvailability['can_cancel'])>
                            <span>İptal Et</span>
                            <span class="pd-badge pd-badge-gray">cancel</span>
                        </button>
                    </div>
                </form>

                @if($actionAvailability['can_apply'])
                    <form method="POST" action="{{ route('admin.super.upgrade-requests.apply', $request) }}" class="space-y-3 mt-4">
                        @csrf
                        <div>
                            <label for="apply_note" class="pd-label">Uygulama Notu</label>
                            <textarea id="apply_note" name="apply_note" rows="4" class="pd-input">{{ old('apply_note') }}</textarea>
                            <div class="text-xs text-gray-500 mt-2">
                                @if($request->isServiceRequest())
                                    Ek hizmet talebinde uygulama notu zorunludur.
                                @else
                                    İsterseniz uygulama sırasında kısa bir operasyon notu bırakabilirsiniz.
                                @endif
                            </div>
                        </div>
                        <div class="pd-summary-action-list">
                            <button type="submit" class="pd-summary-action pd-summary-action-button">
                                <span>Talebi Uygula</span>
                                <span class="pd-badge pd-badge-green">apply</span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Kural Notu</h3>
                <div class="pd-note">
                    <p class="text-sm text-gray-600">Apply yalnız approved taleplerde açılır. Uygulama tamamlanınca talep applied olur ve ilgili Abone Firma erişimi mevcut servis truth’ünden görünür.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
